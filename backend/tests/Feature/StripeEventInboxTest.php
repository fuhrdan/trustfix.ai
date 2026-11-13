<?php

namespace Tests\Feature;

use App\Services\StripeEventInbox;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StripeEventInboxTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_inbox_fixture';

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
            'services.stripe.secret' => 'sk_test_inbox_fixture',
            'services.stripe.key' => 'pk_test_inbox_fixture',
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    public function test_intake_encrypts_exact_signed_bytes_while_collection_is_paused(): void
    {
        $payload = $this->payload();
        $header = 'v1=' . str_repeat('0', 64) . ', ' . $this->signature($payload);
        $id = app(StripeEventInbox::class)->receive($payload, $header);
        $receipt = DB::table('stripe_event_receipts')->find($id);
        $state = $this->state($id);

        self::assertSame('evt_inbox_fixture', $receipt->stripe_event_id);
        self::assertSame($payload, Crypt::decryptString($receipt->payload_encrypted));
        self::assertSame(hash('sha256', $payload), $receipt->payload_sha256);
        self::assertStringNotContainsString('private_customer_fixture', $receipt->payload_encrypted);
        self::assertSame('pending', $state->status);
        self::assertSame(0, (int) $state->attempts);
        self::assertNull($state->processed_at);
        Http::assertNothingSent();
    }

    public function test_invalid_signatures_leave_no_durable_records(): void
    {
        $payload = $this->payload();
        $valid = $this->signature($payload);

        foreach ([
            [$payload, ''],
            [$payload, 't=' . time() . ',v1=' . str_repeat('0', 64)],
            [$payload, $this->signature($payload, time() - 601)],
            [$payload, $this->signature($payload, time() + 601)],
            [$payload, 't=invalid,v1=' . str_repeat('0', 64)],
            [$payload, $valid . ',t=' . time()],
            [$payload . ' ', $valid],
            [$payload, 't=' . time() . ',v1=abc'],
        ] as [$body, $header]) {
            $this->denied(fn () => app(StripeEventInbox::class)->receive($body, $header), 400);
        }

        self::assertSame(0, DB::table('stripe_event_receipts')->count());
        self::assertSame(0, DB::table('stripe_event_processing')->count());
        Http::assertNothingSent();
    }

    public function test_signed_invalid_or_live_events_are_not_persisted(): void
    {
        $bodies = ['{', 'null', '[]', '{"livemode":false}'];
        foreach ([
            ['object' => 'unexpected'],
            ['id' => 'not_an_event'],
            ['type' => 'invalid'],
            ['created' => '1794096000'],
            ['account' => 'invalid'],
            ['api_version' => str_repeat('x', 65)],
            ['data' => null],
            ['data' => ['object' => ['id' => '', 'object' => 'payment_intent']]],
            ['livemode' => true],
            ['livemode' => 'false'],
            ['livemode' => 0],
        ] as $changes) {
            $bodies[] = $this->payload($changes);
        }

        foreach ($bodies as $body) {
            $this->denied(
                fn () => app(StripeEventInbox::class)->receive($body, $this->signature($body)),
                400
            );
        }

        self::assertSame(0, DB::table('stripe_event_receipts')->count());
        self::assertSame(0, DB::table('stripe_event_processing')->count());
    }

    public function test_duplicate_delivery_preserves_original_receipt_and_processing_state(): void
    {
        $payload = $this->payload();
        $inbox = app(StripeEventInbox::class);
        $id = $inbox->receive($payload, $this->signature($payload));
        $original = (array) DB::table('stripe_event_receipts')->find($id);
        $redelivery = $this->payload(['pending_webhooks' => 2]);

        foreach (['pending', 'processing', 'failed', 'processed'] as $status) {
            DB::table('stripe_event_processing')->where('stripe_event_receipt_id', $id)->update([
                'status' => $status,
                'attempts' => 3,
                'last_error_code' => $status === 'failed' ? 'fixture_failure' : null,
                'processed_at' => $status === 'processed' ? '2026-11-08 00:00:00' : null,
            ]);
            $state = (array) $this->state($id);

            self::assertSame($id, $inbox->receive($redelivery, $this->signature($redelivery)));
            self::assertSame($original, (array) DB::table('stripe_event_receipts')->find($id));
            self::assertSame($state, (array) $this->state($id));
        }

        self::assertSame(1, DB::table('stripe_event_receipts')->count());
        self::assertSame(1, DB::table('stripe_event_processing')->count());
    }

    public function test_distinct_events_are_retained_when_delivered_out_of_order(): void
    {
        $inbox = app(StripeEventInbox::class);
        $newer = $this->payload();
        $older = $this->payload([
            'id' => 'evt_inbox_older',
            'created' => 1794095000,
            'type' => 'payment_intent.processing',
        ]);

        $newerId = $inbox->receive($newer, $this->signature($newer));
        $olderId = $inbox->receive($older, $this->signature($older));

        self::assertNotSame($newerId, $olderId);
        self::assertSame(2, DB::table('stripe_event_receipts')->count());
        self::assertSame(2, DB::table('stripe_event_processing')->where('status', 'pending')->count());
        self::assertSame(0, DB::table('payments')->count());
    }

    public function test_conflicting_duplicate_identity_is_rejected_without_overwriting_history(): void
    {
        $payload = $this->payload();
        $inbox = app(StripeEventInbox::class);
        $id = $inbox->receive($payload, $this->signature($payload));
        $original = (array) DB::table('stripe_event_receipts')->find($id);

        foreach ([
            ['type' => 'payment_intent.processing'],
            ['created' => 1794096001],
            ['account' => 'acct_other_fixture'],
            ['api_version' => '2025-01-27.acacia'],
        ] as $changes) {
            $conflict = $this->payload($changes);
            $this->denied(fn () => $inbox->receive($conflict, $this->signature($conflict)), 409);
            self::assertSame($original, (array) DB::table('stripe_event_receipts')->find($id));
        }

        self::assertSame(1, DB::table('stripe_event_receipts')->count());
        self::assertSame('pending', $this->state($id)->status);
    }

    public function test_missing_processing_state_requires_reconciliation(): void
    {
        $payload = $this->payload();
        $inbox = app(StripeEventInbox::class);
        $id = $inbox->receive($payload, $this->signature($payload));
        DB::table('stripe_event_processing')->where('stripe_event_receipt_id', $id)->delete();

        $this->denied(fn () => $inbox->receive($payload, $this->signature($payload)), 503);
        self::assertSame(1, DB::table('stripe_event_receipts')->count());
        self::assertSame(0, DB::table('stripe_event_processing')->count());
    }

    public function test_processing_insert_failure_rolls_back_receipt_and_allows_retry(): void
    {
        // This fixture trigger exists only inside the forced SQLite memory database.
        DB::unprepared(
            "CREATE TRIGGER inbox_fixture_failure BEFORE INSERT ON stripe_event_processing "
            . "BEGIN SELECT RAISE(ABORT, 'fixture_processing_failure'); END"
        );
        $payload = $this->payload();
        $inbox = app(StripeEventInbox::class);

        try {
            $inbox->receive($payload, $this->signature($payload));
            self::fail('Intake must fail when durable processing state cannot be stored.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('fixture_processing_failure', $exception->getMessage());
        }

        self::assertSame(0, DB::table('stripe_event_receipts')->count());
        self::assertSame(0, DB::table('stripe_event_processing')->count());
        DB::unprepared('DROP TRIGGER inbox_fixture_failure');

        $id = $inbox->receive($payload, $this->signature($payload));
        self::assertSame(1, DB::table('stripe_event_receipts')->count());
        self::assertSame('pending', $this->state($id)->status);
        Http::assertNothingSent();
    }

    private function payload(array $changes = []): string
    {
        return json_encode(array_replace([
            'id' => 'evt_inbox_fixture',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'api_version' => '2026-10-28.endive',
            'created' => 1794096000,
            'livemode' => false,
            'data' => ['object' => [
                'id' => 'pi_inbox_fixture',
                'object' => 'payment_intent',
                'description' => 'private_customer_fixture',
            ]],
        ], $changes), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    private function signature(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't=' . $timestamp . ',v1='
            . hash_hmac('sha256', $timestamp . '.' . $payload, self::WEBHOOK_SECRET);
    }

    private function state(int $receiptId): object
    {
        return DB::table('stripe_event_processing')
            ->where('stripe_event_receipt_id', $receiptId)
            ->first();
    }

    private function denied(callable $action, int $status): void
    {
        try {
            $action();
        } catch (HttpException $exception) {
            self::assertSame($status, $exception->getStatusCode());

            return;
        }

        self::fail("Expected HTTP {$status}.");
    }
}
