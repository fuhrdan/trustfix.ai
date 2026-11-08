<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PaymentJournalStorageTest extends TestCase
{
    private const CONNECTION = 'payment_journal_memory';

    private Connection $auditDb;
    private Migration $auditMigration;
    private string $previousConnection = 'sqlite';

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('testing', app()->environment());
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));

        $this->previousConnection = DB::getDefaultConnection();
        config(['database.connections.' . self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection(self::CONNECTION);
        Schema::clearResolvedInstance('db.schema');

        $this->auditDb = DB::connection(self::CONNECTION);
        self::assertSame(':memory:', $this->auditDb->getDatabaseName());
        $this->auditDb->statement('PRAGMA foreign_keys = ON');
        self::assertSame(
            1,
            (int) $this->auditDb->selectOne('PRAGMA foreign_keys')->foreign_keys
        );

        // Minimal parents reproduce the existing job-to-payment cascade.
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();
        });
        $this->auditDb->table('jobs')->insert(['id' => 1]);
        $this->auditDb->table('payments')->insert(['id' => 1, 'job_id' => 1]);

        $this->auditMigration = require database_path(
            'migrations/2026_11_08_000000_create_payment_journal_tables.php'
        );
        $this->auditMigration->up();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->auditDb)) {
                DB::purge(self::CONNECTION);
                DB::setDefaultConnection($this->previousConnection);
                Schema::clearResolvedInstance('db.schema');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_journal_and_receipts_reject_raw_updates_and_deletes(): void
    {
        $receipt = $this->receipt();
        $entry = $this->entry(['stripe_event_receipt_id' => $receipt]);

        foreach ([
            ['stripe_event_receipts', $receipt, ['payload_encrypted' => 'tampered']],
            ['payment_journal_entries', $entry, ['amount_cents' => 1]],
        ] as [$table, $id, $changes]) {
            $original = $this->row($table, $id);
            $this->rejected(
                fn () => $this->auditDb->table($table)->where('id', $id)->update($changes),
                'append-only'
            );
            self::assertSame($original, $this->row($table, $id));

            $this->rejected(
                fn () => $this->auditDb->table($table)->where('id', $id)->delete(),
                'append-only'
            );
            self::assertSame($original, $this->row($table, $id));
        }
    }

    public function test_duplicate_event_entry_and_processing_keys_are_rejected(): void
    {
        $receipt = $this->receipt();
        $this->entry();
        $processing = ['stripe_event_receipt_id' => $receipt];
        $this->auditDb->table('stripe_event_processing')->insert($processing);

        $this->rejected(fn () => $this->receipt(), 'UNIQUE constraint failed');
        $this->rejected(fn () => $this->entry(), 'UNIQUE constraint failed');
        $this->rejected(
            fn () => $this->auditDb->table('stripe_event_processing')->insert($processing),
            'UNIQUE constraint failed'
        );

        foreach ([
            'stripe_event_receipts',
            'payment_journal_entries',
            'stripe_event_processing',
        ] as $table) {
            self::assertSame(1, $this->auditDb->table($table)->count());
        }
    }

    public function test_retry_state_can_change_without_rewriting_the_receipt(): void
    {
        $receipt = $this->receipt();
        $original = $this->row('stripe_event_receipts', $receipt);
        $id = $this->auditDb->table('stripe_event_processing')->insertGetId([
            'stripe_event_receipt_id' => $receipt,
        ]);
        $query = fn () => $this->auditDb->table('stripe_event_processing')->where('id', $id);

        self::assertSame('pending', $query()->value('status'));
        self::assertSame(0, (int) $query()->value('attempts'));

        $query()->update([
            'status' => 'failed',
            'attempts' => 1,
            'last_error_code' => 'fixture_failure',
        ]);
        self::assertNull($query()->value('processed_at'));

        $query()->update([
            'status' => 'processing',
            'attempts' => 2,
            'lease_token' => str_repeat('a', 64),
            'lease_expires_at' => '2030-01-01 00:00:00',
            'last_error_code' => null,
        ]);
        $query()->update([
            'status' => 'processed',
            'processed_at' => '2026-11-08 00:00:00',
            'lease_token' => null,
            'lease_expires_at' => null,
        ]);

        self::assertSame('processed', $query()->value('status'));
        self::assertSame(2, (int) $query()->value('attempts'));
        self::assertNotNull($query()->value('processed_at'));
        self::assertSame($original, $this->row('stripe_event_receipts', $receipt));
    }

    public function test_journal_history_prevents_payment_and_job_cascade_deletion(): void
    {
        $entry = $this->entry();
        $original = $this->row('payment_journal_entries', $entry);

        foreach (['payments', 'jobs'] as $table) {
            $this->rejected(
                fn () => $this->auditDb->table($table)->where('id', 1)->delete(),
                'FOREIGN KEY constraint failed'
            );
            self::assertSame(1, $this->auditDb->table('jobs')->count());
            self::assertSame(1, $this->auditDb->table('payments')->count());
            self::assertSame($original, $this->row('payment_journal_entries', $entry));
        }
    }

    public function test_corrections_append_facts_without_rewriting_prior_entries(): void
    {
        $entry = $this->entry();
        $original = $this->row('payment_journal_entries', $entry);
        $correction = $this->entry([
            'entry_key' => 'fixture:refund:1',
            'entry_type' => 'payment_refunded',
            'amount_cents' => 2000,
        ]);

        self::assertSame(2, $this->auditDb->table('payment_journal_entries')->count());
        self::assertSame($original, $this->row('payment_journal_entries', $entry));
        self::assertSame(
            2000,
            (int) $this->row('payment_journal_entries', $correction)['amount_cents']
        );
    }

    public function test_migration_rollback_refuses_to_remove_audit_history(): void
    {
        $entry = $this->entry();
        $original = $this->row('payment_journal_entries', $entry);

        try {
            $this->auditMigration->down();
            self::fail('Audit history must not be removed by migration rollback.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('retained', $exception->getMessage());
        }

        self::assertSame($original, $this->row('payment_journal_entries', $entry));
    }

    private function receipt(): int
    {
        $payload = '{"id":"evt_audit_fixture","type":"payment_intent.succeeded","livemode":false}';

        return $this->auditDb->table('stripe_event_receipts')->insertGetId([
            'stripe_event_id' => 'evt_audit_fixture',
            'event_type' => 'payment_intent.succeeded',
            'livemode' => false,
            'stripe_created_at' => 1794096000,
            'payload_encrypted' => Crypt::encryptString($payload),
            'payload_sha256' => hash('sha256', $payload),
        ]);
    }

    private function entry(array $overrides = []): int
    {
        return $this->auditDb->table('payment_journal_entries')->insertGetId(
            array_replace([
                'payment_id' => 1,
                'entry_key' => 'fixture:charge:1',
                'entry_type' => 'payment_succeeded',
                'amount_cents' => 5000,
                'currency' => 'usd',
            ], $overrides)
        );
    }

    private function row(string $table, int $id): array
    {
        return (array) $this->auditDb->table($table)->find($id);
    }

    private function rejected(callable $action, string $message): void
    {
        try {
            $action();
        } catch (QueryException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());

            return;
        }

        self::fail('The database accepted a prohibited audit-record operation.');
    }
}
