<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PaymentAgreementSchemaTest extends TestCase
{
    private const CONNECTION = 'payment_agreement_schema_memory';
    private const TABLES = ['payment_agreements', 'payment_installments', 'payment_agreement_consents'];
    private Connection $memoryDb;
    private Migration $migration;
    private string $previousConnection = 'sqlite';
    private array $legacyPayments;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));

        $this->previousConnection = DB::getDefaultConnection();
        config(['database.connections.' . self::CONNECTION => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection(self::CONNECTION);
        Schema::clearResolvedInstance('db.schema');
        $this->memoryDb = DB::connection(self::CONNECTION);
        self::assertSame(':memory:', $this->memoryDb->getDatabaseName());
        $this->memoryDb->statement('PRAGMA foreign_keys = ON');
        self::assertSame(1, (int) $this->memoryDb->selectOne('PRAGMA foreign_keys')->foreign_keys);

        // Minimal parent fixtures reproduce the application's cascading links.
        // The agreement migration itself runs unchanged against SQLite memory.
        Schema::create('users', function (Blueprint $table) { $table->id(); });
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('handyman_id')->constrained('users')->cascadeOnDelete();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('contractor_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('status');
            $table->string('stripe_payment_intent_id')->nullable();
        });
        Schema::create('change_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();
        });
        DB::table('users')->insert([['id' => 1], ['id' => 2]]);
        DB::table('jobs')->insert(['id' => 1, 'customer_id' => 1, 'handyman_id' => 2]);
        DB::table('change_orders')->insert(['id' => 1, 'job_id' => 1]);

        for ($id = 1; $id <= 20; $id++) {
            DB::table('payments')->insert([
                'id' => $id, 'job_id' => 1, 'customer_id' => 1, 'contractor_id' => 2,
                'amount_cents' => 5000,
                'status' => $id === 20 ? 'succeeded' : 'requires_payment_method',
                'stripe_payment_intent_id' => $id === 20 ? 'pi_legacy_destination_fixture' : null,
            ]);
        }

        $this->legacyPayments = $this->rows('payments');
        $this->migration = require database_path(
            'migrations/2026_11_08_000100_create_payment_agreement_tables.php'
        );
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
        } finally {
            if (isset($this->memoryDb)) {
                DB::purge(self::CONNECTION);
                DB::setDefaultConnection($this->previousConnection);
                Schema::clearResolvedInstance('db.schema');
            }
            parent::tearDown();
        }
    }

    public function test_financial_snapshots_and_consent_facts_cannot_be_rewritten_or_deleted(): void
    {
        $this->fixture();
        foreach (self::TABLES as $table) {
            $original = $this->rows($table);
            foreach (['update', 'delete'] as $operation) {
                $this->blocked(function () use ($table, $operation) {
                    $query = DB::table($table);
                    return $operation === 'update'
                        ? $query->update(['created_at' => '2030-01-01 00:00:00'])
                        : $query->delete();
                }, 'append-only');
                self::assertSame($original, $this->rows($table));
            }
        }
    }

    public function test_new_versions_and_revocations_append_without_changing_earlier_facts(): void
    {
        $first = $this->agreement();
        $original = $this->rows('payment_agreements');
        DB::table('payment_agreement_consents')->insert($this->consentData($first));
        DB::table('payment_agreement_consents')->insert($this->consentData($first, [
            'decision_key' => 'contractor_accepts',
            'actor_id' => 2,
            'actor_role' => 'contractor',
        ]));

        $accepted = $this->rows('payment_agreement_consents');
        DB::table('payment_agreement_consents')->insert($this->consentData($first, [
            'decision_key' => 'customer_revokes',
            'event_type' => 'consent_revoked',
        ]));

        $second = $this->agreement([
            'version' => 2, 'previous_agreement_id' => $first,
            'amount_cents' => 5500, 'platform_fee_cents' => 550,
            'scope_snapshot' => json_encode(['description' => 'Revised fixture scope'], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame($original[0], $this->rows('payment_agreements')[0]);
        self::assertSame($accepted, array_slice($this->rows('payment_agreement_consents'), 0, 2));
        self::assertCount(3, $this->rows('payment_agreement_consents'));
        self::assertNotSame($first, $second);
        self::assertNotSame(
            $original[0]['agreement_sha256'],
            DB::table('payment_agreements')->find($second)->agreement_sha256
        );
        self::assertSame(0, DB::table('payment_agreement_consents')
            ->where('payment_agreement_id', $second)->count());
    }

    public function test_duplicate_payable_and_consent_identities_are_rejected(): void
    {
        $agreement = $this->fixture();
        $this->blocked(fn () => $this->agreement(), 'payment_agreements.job_id, payment_agreements.version');

        foreach ([
            'payment_id' => 1,
            'payable_key' => 'fixture_payable_1',
            'transfer_group' => 'fixture_group_1',
            'sequence' => 1,
            'milestone_key' => 'upfront',
        ] as $field => $value) {
            $data = $this->installmentData($agreement, array_replace([
                'payment_id' => 2, 'payable_key' => 'fixture_payable_2',
                'transfer_group' => 'fixture_group_2', 'sequence' => 2, 'milestone_key' => 'later',
            ], [$field => $value]));

            $this->blocked(fn () => DB::table('payment_installments')->insert($data),
                'payment_installments.' . $field);
        }

        $this->blocked(fn () => DB::table('payment_agreement_consents')->insert(
            $this->consentData($agreement, ['actor_id' => 2, 'actor_role' => 'contractor'])
        ), 'payment_agreement_consents.decision_key');

        foreach (self::TABLES as $table) {
            self::assertSame(1, DB::table($table)->count());
        }
    }

    public function test_missing_parent_references_cannot_create_financial_records(): void
    {
        foreach (['job_id', 'customer_id', 'contractor_id', 'previous_agreement_id'] as $field) {
            $this->blocked(fn () => $this->agreement([$field => 999999]), 'FOREIGN KEY constraint failed');
        }
        self::assertSame(0, DB::table('payment_agreements')->count());

        $agreement = $this->agreement();
        foreach (['payment_agreement_id', 'payment_id', 'source_change_order_id'] as $field) {
            $this->blocked(fn () => DB::table('payment_installments')->insert(
                $this->installmentData($agreement, [$field => 999999])
            ), 'FOREIGN KEY constraint failed');
        }
        $this->blocked(fn () => DB::table('payment_agreement_consents')->insert(
            $this->consentData($agreement, ['payment_agreement_id' => 999999])
        ), 'FOREIGN KEY constraint failed');

        self::assertSame(0, DB::table('payment_installments')->count());
        self::assertSame(0, DB::table('payment_agreement_consents')->count());
    }

    public function test_parent_deletion_and_existing_cascades_cannot_erase_financial_history(): void
    {
        $this->fixture();
        $before = $this->snapshot();

        foreach ([
            ['payments', 1], ['change_orders', 1], ['jobs', 1], ['users', 1], ['users', 2],
        ] as [$table, $id]) {
            $this->blocked(fn () => DB::table($table)->where('id', $id)->delete(),
                'FOREIGN KEY constraint failed');
            self::assertSame($before, $this->snapshot());
        }
    }

    public function test_failed_transaction_leaves_no_partial_terms_or_payable_reservation(): void
    {
        $this->blocked(function () {
            DB::transaction(function () {
                $agreement = $this->agreement();
                DB::table('payment_installments')->insert($this->installmentData($agreement));
                DB::table('payment_agreement_consents')->insert(
                    $this->consentData($agreement, ['payment_agreement_id' => 999999])
                );
            });
        }, 'FOREIGN KEY constraint failed');

        foreach (self::TABLES as $table) {
            self::assertSame(0, DB::table($table)->count());
        }
        self::assertSame($this->legacyPayments, $this->rows('payments'));

        DB::transaction(fn () => $this->fixture());
        foreach (self::TABLES as $table) {
            self::assertSame(1, DB::table($table)->count());
        }
    }

    public function test_rollback_and_repeat_migration_refuse_to_destroy_existing_history(): void
    {
        $this->fixture();
        $before = $this->snapshot();

        foreach ([
            'down' => 'history is retained',
            'up' => 'Existing payment agreement table requires review',
        ] as $method => $message) {
            try {
                $this->migration->$method();
                self::fail('Migration must stop without rewriting history.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
            self::assertSame($before, $this->snapshot());
        }
    }

    public function test_terms_and_consent_do_not_rewrite_legacy_payments_or_confirm_funding(): void
    {
        self::assertSame($this->legacyPayments, $this->rows('payments'));
        $this->fixture();
        self::assertSame($this->legacyPayments, $this->rows('payments'));
        self::assertSame('requires_payment_method', DB::table('payments')->find(1)->status);

        $before = $this->snapshot();
        DB::table('payments')->where('id', 1)->update(['status' => 'processing']);
        foreach (self::TABLES as $table) {
            self::assertSame($before[$table], $this->rows($table));
        }
        self::assertSame($this->legacyPayments[19], (array) DB::table('payments')->find(20));
    }

    private function agreement(array $overrides = []): int
    {
        $row = array_replace([
            'job_id' => 1, 'customer_id' => 1, 'contractor_id' => 2, 'version' => 1,
            'previous_agreement_id' => null, 'agreement_kind' => 'change_order',
            'policy_version' => 'staging_2026_11_v1',
            'payment_model' => 'separate_charges_transfers_v1',
            'currency' => 'usd', 'amount_cents' => 5000, 'platform_fee_cents' => 500,
            'platform_fee_basis_points' => 1000, 'created_by_actor_id' => 2,
            'scope_snapshot' => json_encode(['description' => 'Fixture extra scope'], JSON_THROW_ON_ERROR),
        ], $overrides);

        $row['scope_sha256'] = hash('sha256', $row['scope_snapshot']);
        $row['schedule_snapshot'] = json_encode([[
            'key' => 'upfront', 'amount_cents' => $row['amount_cents'],
            'platform_fee_cents' => $row['platform_fee_cents'],
        ]], JSON_THROW_ON_ERROR);
        $row['included_change_orders'] = json_encode([[
            'id' => 1, 'price_delta_cents' => $row['amount_cents'],
        ]], JSON_THROW_ON_ERROR);
        $row['agreement_sha256'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));

        return (int) DB::table('payment_agreements')->insertGetId($row);
    }

    private function installmentData(int $agreement, array $overrides = []): array
    {
        $row = array_replace([
            'payment_agreement_id' => $agreement,
            'payment_id' => 1,
            'source_change_order_id' => 1,
            'payable_key' => 'fixture_payable_1',
            'sequence' => 1,
            'milestone_key' => 'upfront',
            'collection_trigger' => 'customer_authorized',
            'amount_cents' => 5000,
            'platform_fee_cents' => 500,
            'currency' => 'usd',
            'transfer_group' => 'fixture_group_1',
        ], $overrides);

        $row['installment_sha256'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        return $row;
    }

    private function consentData(int $agreement, array $overrides = []): array
    {
        return array_replace([
            'payment_agreement_id' => $agreement,
            'decision_key' => 'customer_accepts',
            'actor_id' => 1,
            'actor_role' => 'customer',
            'event_type' => 'scope_accepted',
            'agreement_sha256' => DB::table('payment_agreements')->find($agreement)->agreement_sha256,
        ], $overrides);
    }

    private function fixture(): int
    {
        $agreement = $this->agreement();
        DB::table('payment_installments')->insert($this->installmentData($agreement));
        DB::table('payment_agreement_consents')->insert($this->consentData($agreement));
        return $agreement;
    }

    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (array_merge(['users', 'jobs', 'payments', 'change_orders'], self::TABLES) as $table) {
            $snapshot[$table] = $this->rows($table);
        }
        return $snapshot;
    }

    private function blocked(callable $action, string $message): void
    {
        try {
            $action();
        } catch (QueryException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
            return;
        }
        self::fail('Expected database constraint rejection.');
    }
}
