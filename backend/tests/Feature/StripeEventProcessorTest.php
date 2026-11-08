<?php

namespace Tests\Feature;

use App\Services\StripeEventInbox;
use App\Services\StripeEventProcessor;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StripeEventProcessorTest extends TestCase
{
    private const CONNECTION = 'stripe_processor_memory';
    private const SECRET = 'whsec_processor_fixture';

    private Connection $auditDb;
    private string $previousConnection = 'sqlite';

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('testing', app()->environment());
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));

        config([
            'app.env' => 'testing',
            'payments.enabled' => false,
            'payments.auto_release_enabled' => false,
            'payments.test_mode_only' => true,
            'services.stripe.secret' => 'sk_test_processor_fixture',
            'services.stripe.key' => 'pk_test_processor_fixture',
            'services.stripe.webhook_secret' => self::SECRET,
        ]);

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

        // Only the existing parent tables are minimal fixtures.
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();
        });
        DB::table('jobs')->insert(['id' => 1]);
        DB::table('payments')->insert(['id' => 1, 'job_id' => 1]);

        $migration = require database_path(
            'migrations/2026_11_08_000000_create_payment_journal_tables.php'
        );
        $migration->up();
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

    public function test_processed_and_ignored_events_are_not_replayed(): void
    {
        $processor = app(StripeEventProcessor::class);

        foreach (['processed', 'ignored'] as $outcome) {
            $id = $this->receipt('evt_processor_' . $outcome);
            $original = (array) DB::table('stripe_event_receipts')->find($id);
            $calls = 0;
            $handler = function (array $event, int $receiptId) use (&$calls, $id, $outcome): string {
                $calls++;
                self::assertSame($id, $receiptId);
                self::assertSame('payment_intent.succeeded', $event['type']);
                if ($outcome === 'processed') {
                    $this->journal($receiptId);
                }

                return $outcome;
            };

            self::assertSame($outcome, $processor->process($id, $handler));
            self::assertSame('already_processed', $processor->process($id, $handler));
            self::assertSame(1, $calls);
            $state = $this->state($id);
            self::assertSame($outcome, $state->status);
            self::assertSame(1, (int) $state->attempts);
            self::assertNotNull($state->processed_at);
            self::assertNull($state->lease_token);
            self::assertNull($state->lease_expires_at);
            self::assertSame($original, (array) DB::table('stripe_event_receipts')->find($id));
        }

        self::assertSame(1, DB::table('payment_journal_entries')->count());
        Http::assertNothingSent();
    }

    public function test_failed_handler_rolls_back_journal_and_can_be_retried(): void
    {
        $id = $this->receipt();
        $processor = app(StripeEventProcessor::class);

        try {
            $processor->process($id, function (array $event, int $receiptId): string {
                $this->journal($receiptId);
                throw new RuntimeException('fixture_handler_failure');
            });
            self::fail('The handler failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('fixture_handler_failure', $exception->getMessage());
        }

        self::assertSame(0, DB::table('payment_journal_entries')->count());
        self::assertSame('failed', $this->state($id)->status);
        self::assertSame(1, (int) $this->state($id)->attempts);
        self::assertNull($this->state($id)->processed_at);
        self::assertNull($this->state($id)->lease_token);
        self::assertSame('processing_failed', $this->state($id)->last_error_code);

        $result = $processor->process($id, function (array $event, int $receiptId): string {
            $this->journal($receiptId);

            return 'processed';
        });

        self::assertSame('processed', $result);
        self::assertSame(2, (int) $this->state($id)->attempts);
        self::assertNull($this->state($id)->last_error_code);
        self::assertSame(1, DB::table('payment_journal_entries')->count());
        Http::assertNothingSent();
    }

    public function test_missing_handler_outcome_rolls_back_journal(): void
    {
        $id = $this->receipt();

        try {
            app(StripeEventProcessor::class)->process(
                $id,
                function (array $event, int $receiptId): void {
                    $this->journal($receiptId);
                }
            );
            self::fail('A handler must explicitly choose its outcome.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('explicitly choose', $exception->getMessage());
        }

        self::assertSame(0, DB::table('payment_journal_entries')->count());
        self::assertSame('failed', $this->state($id)->status);
        self::assertNull($this->state($id)->processed_at);
    }

    public function test_active_lease_blocks_work_and_expired_lease_can_be_recovered(): void
    {
        $id = $this->receipt();
        $oldToken = str_repeat('a', 64);
        DB::table('stripe_event_processing')->where('stripe_event_receipt_id', $id)->update([
            'status' => 'processing',
            'attempts' => 1,
            'lease_token' => $oldToken,
            'lease_expires_at' => now()->addHour(),
        ]);
        $original = (array) $this->state($id);
        $calls = 0;
        $handler = function (array $event, int $receiptId) use (&$calls, $oldToken): string {
            $calls++;
            self::assertNotSame($oldToken, $this->state($receiptId)->lease_token);
            $this->journal($receiptId);

            return 'processed';
        };
        $processor = app(StripeEventProcessor::class);

        self::assertSame('busy', $processor->process($id, $handler));
        self::assertSame(0, $calls);
        self::assertSame($original, (array) $this->state($id));

        DB::table('stripe_event_processing')->where('stripe_event_receipt_id', $id)->update([
            'lease_expires_at' => now()->subMinute(),
        ]);

        self::assertSame('processed', $processor->process($id, $handler));
        self::assertSame(1, $calls);
        self::assertSame(2, (int) $this->state($id)->attempts);
        self::assertSame(1, DB::table('payment_journal_entries')->count());
    }

    public function test_reentrant_delivery_does_not_run_a_second_handler(): void
    {
        $id = $this->receipt();
        $processor = app(StripeEventProcessor::class);
        $result = $processor->process($id, function (array $event, int $receiptId) use ($processor): string {
            $nested = $processor->process($receiptId, function (): string {
                self::fail('A second handler must not run while the lease is active.');
            });
            self::assertSame('busy', $nested);
            $this->journal($receiptId);

            return 'processed';
        });

        self::assertSame('processed', $result);
        self::assertSame(1, (int) $this->state($id)->attempts);
        self::assertSame(1, DB::table('payment_journal_entries')->count());
    }

    public function test_superseded_workers_cannot_commit_or_mark_a_new_owner_failed(): void
    {
        foreach ([false, true] as $throwDuringRead) {
            $id = $this->receipt('evt_processor_fence_' . (int) $throwDuringRead);
            $encrypter = Crypt::getFacadeRoot();
            $otherState = null;
            $calls = 0;
            $handler = function (array $event, int $receiptId) use (&$calls): string {
                $calls++;
                $this->journal($receiptId);

                return 'processed';
            };

            Crypt::shouldReceive('decryptString')->once()->andReturnUsing(
                function (string $ciphertext) use ($id, $encrypter, $throwDuringRead, &$otherState): string {
                    // Simulate another worker taking ownership while this one is paused.
                    DB::table('stripe_event_processing')->where('stripe_event_receipt_id', $id)->update([
                        'status' => 'processing',
                        'attempts' => 2,
                        'lease_token' => str_repeat('b', 64),
                        'lease_expires_at' => now()->addHour(),
                        'last_error_code' => null,
                    ]);
                    $otherState = (array) $this->state($id);
                    if ($throwDuringRead) {
                        throw new RuntimeException('fixture_stale_failure');
                    }

                    return $encrypter->decryptString($ciphertext);
                }
            );

            try {
                if ($throwDuringRead) {
                    try {
                        app(StripeEventProcessor::class)->process($id, $handler);
                        self::fail('The stale worker failure must propagate.');
                    } catch (RuntimeException $exception) {
                        self::assertSame('fixture_stale_failure', $exception->getMessage());
                    }
                } else {
                    self::assertSame(
                        'superseded',
                        app(StripeEventProcessor::class)->process($id, $handler)
                    );
                }
            } finally {
                Crypt::swap($encrypter);
            }

            self::assertSame(0, $calls);
            self::assertSame($otherState, (array) $this->state($id));
            self::assertSame(0, DB::table('payment_journal_entries')->count());
        }
    }

    public function test_payload_integrity_failure_does_not_run_handler(): void
    {
        $id = $this->receipt('evt_processor_corrupt', true);
        $calls = 0;

        try {
            app(StripeEventProcessor::class)->process($id, function () use (&$calls): string {
                $calls++;

                return 'processed';
            });
            self::fail('Corrupt stored payloads must not reach a handler.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('integrity check failed', $exception->getMessage());
        }

        self::assertSame(0, $calls);
        self::assertSame('failed', $this->state($id)->status);
        self::assertSame(0, DB::table('payment_journal_entries')->count());
    }

    public function test_production_configuration_is_rejected_before_claim(): void
    {
        $id = $this->receipt();
        config(['app.env' => 'production']);
        $queries = 0;
        DB::listen(static function ($event) use (&$queries): void {
            $queries++;
        });

        try {
            app(StripeEventProcessor::class)->process($id, function (): string {
                self::fail('Production configuration must not invoke a handler.');
            });
            self::fail('Production processing must be blocked.');
        } catch (HttpException $exception) {
            self::assertSame(503, $exception->getStatusCode());
        }

        self::assertSame(0, $queries);
        self::assertSame('pending', $this->state($id)->status);
        self::assertSame(0, (int) $this->state($id)->attempts);
        Http::assertNothingSent();
    }

    private function receipt(string $eventId = 'evt_processor_fixture', bool $corruptHash = false): int
    {
        $event = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'api_version' => '2026-10-28.endive',
            'created' => 1794096000,
            'livemode' => false,
            'data' => ['object' => [
                'id' => 'pi_processor_fixture',
                'object' => 'payment_intent',
            ]],
        ];
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't=' . $timestamp . ',v1='
            . hash_hmac('sha256', $timestamp . '.' . $payload, self::SECRET);

        if (!$corruptHash) {
            return app(StripeEventInbox::class)->receive($payload, $signature);
        }

        // Fault injection bypasses intake only for the integrity test.
        $id = DB::table('stripe_event_receipts')->insertGetId([
            'stripe_event_id' => $eventId,
            'event_type' => $event['type'],
            'api_version' => $event['api_version'],
            'livemode' => false,
            'stripe_created_at' => $event['created'],
            'payload_encrypted' => Crypt::encryptString($payload),
            'payload_sha256' => str_repeat('0', 64),
        ]);
        DB::table('stripe_event_processing')->insert([
            'stripe_event_receipt_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $id;
    }

    private function journal(int $receiptId): void
    {
        DB::table('payment_journal_entries')->insert([
            'payment_id' => 1,
            'entry_key' => 'processor:receipt:' . $receiptId,
            'entry_type' => 'payment_succeeded',
            'amount_cents' => 5000,
            'currency' => 'usd',
            'stripe_event_receipt_id' => $receiptId,
        ]);
    }

    private function state(int $receiptId): object
    {
        return DB::table('stripe_event_processing')
            ->where('stripe_event_receipt_id', $receiptId)
            ->first();
    }
}
