<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ManualPaymentRefundService;
use App\Services\ManualPaymentReleaseService;
use App\Services\StripeEventInbox;
use App\Services\StripePaymentGateway;
use App\Services\StripePaymentIntentReconciler;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ManualPaymentRefundTest extends TestCase
{
    private const CONNECTION = 'manual_payment_refund_memory';

    private const WEBHOOK_SECRET = 'whsec_manual_refund_fixture';

    private Connection $memoryDb;

    private string $previousConnection = 'sqlite';

    private int $expectedStripeRequests = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('testing', app()->environment());
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        config([
            'app.env' => 'testing',
            'payments.enabled' => true,
            'payments.releases_enabled' => true,
            'payments.refunds_enabled' => true,
            'payments.test_mode_only' => true,
            'payments.auto_release_enabled' => false,
            'services.stripe.secret' => 'sk_test_manual_refund_fixture',
            'services.stripe.key' => 'pk_test_manual_refund_fixture',
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        $this->previousConnection = DB::getDefaultConnection();
        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection(self::CONNECTION);
        Schema::clearResolvedInstance('db.schema');
        $this->memoryDb = DB::connection(self::CONNECTION);
        $this->memoryDb->statement('PRAGMA foreign_keys = ON');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('handyman_id')->nullable()->constrained('users');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('change_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs');
        });
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs');
            $table->string('status');
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs');
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('contractor_id')->constrained('users');
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('platform_fee_cents')->default(0);
            $table->char('currency', 3)->default('usd');
            $table->string('status');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('contractor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->string('status');
            $table->string('stripe_account_id')->nullable()->unique();
            $table->boolean('stripe_details_submitted')->default(false);
            $table->boolean('stripe_payouts_enabled')->default(false);
        });

        foreach ([
            ['id' => 1, 'name' => 'Fixture customer'],
            ['id' => 2, 'name' => 'Fixture contractor'],
            ['id' => 3, 'name' => 'Fixture administrator'],
        ] as $user) {
            DB::table('users')->insert($user);
        }
        DB::table('jobs')->insert([
            'id' => 1,
            'customer_id' => 1,
            'handyman_id' => 2,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            '2026_11_08_000100_create_payment_agreement_tables.php',
            '2026_11_08_000000_create_payment_journal_tables.php',
            '2026_11_08_000200_create_stripe_operation_tables.php',
        ] as $migrationFile) {
            $migration = require database_path('migrations/'.$migrationFile);
            $migration->up();
        }

        DB::table('payments')->insert([
            'id' => 1,
            'job_id' => 1,
            'customer_id' => 1,
            'contractor_id' => 2,
            'stripe_payment_intent_id' => 'pi_manual_refund_fixture',
            'amount_cents' => 1000,
            'platform_fee_cents' => 100,
            'currency' => 'usd',
            'status' => 'succeeded',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_agreements')->insert([
            'id' => 1,
            'job_id' => 1,
            'customer_id' => 1,
            'contractor_id' => 2,
            'version' => 1,
            'previous_agreement_id' => null,
            'agreement_kind' => 'initial',
            'policy_version' => 'staging_2026_11_v1',
            'payment_model' => StripePaymentGateway::PAYMENT_MODEL,
            'currency' => 'usd',
            'amount_cents' => 1000,
            'platform_fee_cents' => 100,
            'platform_fee_basis_points' => 1000,
            'scope_snapshot' => '{}',
            'scope_sha256' => hash('sha256', '{}'),
            'schedule_snapshot' => '[]',
            'included_change_orders' => '[]',
            'agreement_sha256' => str_repeat('a', 64),
            'created_by_actor_id' => 1,
            'created_at' => now(),
        ]);
        DB::table('payment_installments')->insert([
            'id' => 1,
            'payment_agreement_id' => 1,
            'payment_id' => 1,
            'source_change_order_id' => null,
            'payable_key' => 'fixture:job:1:initial',
            'sequence' => 1,
            'milestone_key' => 'upfront',
            'collection_trigger' => 'customer_authorized',
            'amount_cents' => 1000,
            'platform_fee_cents' => 100,
            'currency' => 'usd',
            'transfer_group' => 'trustfix_job_1_refund_fixture',
            'installment_sha256' => str_repeat('b', 64),
            'created_at' => now(),
        ]);
        DB::table('contractor_profiles')->insert([
            'user_id' => 2,
            'status' => 'approved',
            'stripe_account_id' => 'acct_manual_refund_fixture',
            'stripe_details_submitted' => true,
            'stripe_payouts_enabled' => true,
        ]);
        DB::table('payment_journal_entries')->insert([
            'payment_id' => 1,
            'entry_key' => 'stripe-event:fixture:payment_succeeded',
            'entry_type' => 'payment_succeeded',
            'amount_cents' => 1000,
            'currency' => 'usd',
            'stripe_object_id' => 'pi_manual_refund_fixture',
            'stripe_event_receipt_id' => null,
            'actor_id' => null,
            'details' => json_encode([
                'stripe_charge_id' => 'ch_manual_refund_fixture',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            Http::assertSentCount($this->expectedStripeRequests);
        } finally {
            if (isset($this->memoryDb)) {
                DB::purge(self::CONNECTION);
                DB::setDefaultConnection($this->previousConnection);
                Schema::clearResolvedInstance('db.schema');
            }
            parent::tearDown();
        }
    }

    public function test_pre_release_full_refund_is_reserved_once_and_waits_for_webhook(): void
    {
        $requests = [];
        $this->fakeSuccessfulWorkflow($requests, false);
        $service = app(ManualPaymentRefundService::class);

        self::assertSame('available', $service->status(1)['state']);
        $first = $service->refund(1, 3);
        self::assertSame('awaiting_webhook', $first['status']);
        self::assertNull($first['stripe_transfer_reversal_id']);
        self::assertSame('re_manual_refund_fixture', $first['stripe_refund_id']);
        self::assertSame($first, $service->refund(1, 3));

        self::assertCount(3, $requests);
        self::assertSame(1, DB::table('stripe_operation_requests')->count());
        self::assertSame(1, DB::table('stripe_operation_requests')
            ->where('operation_type', ManualPaymentRefundService::REFUND_OPERATION)->count());
        self::assertSame(0, DB::table('stripe_operation_requests')
            ->where('operation_type', ManualPaymentRefundService::REVERSAL_OPERATION)->count());
        self::assertSame('succeeded', DB::table('payments')->value('status'));
        self::assertNull(DB::table('payments')->value('refunded_at'));
        self::assertSame(1, DB::table('payment_journal_entries')
            ->where('entry_type', 'customer_full_refund_authorized')->count());
        self::assertSame(1, DB::table('payment_journal_entries')
            ->where('entry_type', 'customer_full_refund_created')->count());
        self::assertSame('awaiting_webhook', $service->status(1)['state']);

        $post = $requests[2];
        self::assertSame('https://api.stripe.com/v1/refunds', $post->url());
        self::assertSame('1000', (string) $post->data()['amount']);
        self::assertSame('pi_manual_refund_fixture', $post->data()['payment_intent']);
        foreach (['reverse_transfer', 'refund_application_fee', 'charge'] as $field) {
            self::assertArrayNotHasKey($field, $post->data());
        }
    }

    public function test_post_release_reverses_full_proceeds_before_full_customer_refund(): void
    {
        $this->seedCompletedRelease();
        $requests = [];
        $this->fakeSuccessfulWorkflow($requests, true);

        $result = app(ManualPaymentRefundService::class)->refund(1, 3);
        self::assertSame('awaiting_webhook', $result['status']);
        self::assertSame('trr_manual_refund_fixture', $result['stripe_transfer_reversal_id']);
        self::assertSame('re_manual_refund_fixture', $result['stripe_refund_id']);
        self::assertCount(5, $requests);
        self::assertSame([
            'https://api.stripe.com/v1/payment_intents/pi_manual_refund_fixture',
            'https://api.stripe.com/v1/charges/ch_manual_refund_fixture',
            'https://api.stripe.com/v1/transfers/tr_manual_refund_fixture',
            'https://api.stripe.com/v1/transfers/tr_manual_refund_fixture/reversals',
            'https://api.stripe.com/v1/refunds',
        ], array_map(fn (Request $request) => $request->url(), $requests));
        self::assertSame('900', (string) $requests[3]->data()['amount']);
        self::assertSame('1000', (string) $requests[4]->data()['amount']);
        self::assertMatchesRegularExpression(
            '/\Atrustfix_reversal_[a-f0-9]{32}\z/',
            $requests[3]->header('Idempotency-Key')[0]
        );
        self::assertMatchesRegularExpression(
            '/\Atrustfix_refund_[a-f0-9]{32}\z/',
            $requests[4]->header('Idempotency-Key')[0]
        );
        self::assertNotSame(
            $requests[3]->header('Idempotency-Key')[0],
            $requests[4]->header('Idempotency-Key')[0]
        );

        self::assertSame(3, DB::table('stripe_operation_requests')->count());
        self::assertSame('completed', DB::table('stripe_operation_processing as state')
            ->join('stripe_operation_requests as request', 'request.id', '=', 'state.operation_request_id')
            ->where('request.operation_type', ManualPaymentRefundService::REVERSAL_OPERATION)
            ->value('state.status'));
        $status = app(ManualPaymentRefundService::class)->status(1);
        self::assertTrue($status['requires_transfer_reversal']);
        self::assertSame('awaiting_webhook', $status['state']);
        $release = app(ManualPaymentReleaseService::class)->status(1)['release'];
        self::assertSame('refunding', $release['state']);
        self::assertSame('tr_manual_refund_fixture', $release['stripe_transfer_id']);
    }

    public function test_reversal_failure_blocks_customer_refund_and_preserves_both_requests(): void
    {
        $this->seedCompletedRelease();
        $requests = [];
        $this->expectedStripeRequests = 4;
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request;
            if ($request->method() === 'GET') {
                return $this->readResponse($request);
            }
            if (str_ends_with($request->url(), '/reversals')) {
                return Http::response(['error' => ['type' => 'balance_insufficient']], 402, [
                    'Request-Id' => 'req_manual_refund_reversal_failed',
                    'Stripe-Version' => StripePaymentGateway::API_VERSION,
                ]);
            }

            self::fail('The customer refund must not run after reversal failure.');
        });

        $service = app(ManualPaymentRefundService::class);
        $result = $service->refund(1, 3);
        self::assertSame('reconciliation_required', $result['status']);
        self::assertSame('transfer_reversal', $result['stage']);
        self::assertCount(4, $requests);
        self::assertFalse(collect($requests)->contains(
            fn (Request $request) => str_ends_with($request->url(), '/v1/refunds')
        ));

        self::assertSame(1, DB::table('stripe_operation_requests')
            ->where('operation_type', ManualPaymentRefundService::REVERSAL_OPERATION)->count());
        self::assertSame(1, DB::table('stripe_operation_requests')
            ->where('operation_type', ManualPaymentRefundService::REFUND_OPERATION)->count());
        self::assertSame('reconciliation_required', $this->operationState(
            ManualPaymentRefundService::REVERSAL_OPERATION
        )->status);
        self::assertSame('pending', $this->operationState(
            ManualPaymentRefundService::REFUND_OPERATION
        )->status);
        self::assertSame($result, $service->refund(1, 3));
        self::assertSame('succeeded', DB::table('payments')->value('status'));
    }

    public function test_local_dispute_already_refunded_and_disabled_guards_send_nothing(): void
    {
        Http::fake();
        $service = app(ManualPaymentRefundService::class);

        DB::table('disputes')->insert(['job_id' => 1, 'status' => 'open']);
        $this->blocked(fn () => $service->refund(1, 3), 409);
        DB::table('disputes')->delete();

        DB::table('payments')->where('id', 1)->update([
            'status' => 'refunded',
            'refunded_at' => now(),
        ]);
        $this->blocked(fn () => $service->refund(1, 3), 409);
        DB::table('payments')->where('id', 1)->update([
            'status' => 'succeeded',
            'refunded_at' => null,
        ]);

        config(['payments.refunds_enabled' => false]);
        $this->blocked(fn () => $service->refund(1, 3), 503);

        self::assertSame(0, DB::table('stripe_operation_requests')->count());
        self::assertSame(1, DB::table('payment_journal_entries')->count());
    }

    public function test_partial_refund_dispute_and_already_refunded_provider_states_are_blocked(): void
    {
        $chargeChanges = [];
        $this->expectedStripeRequests = 6;
        Http::fake(function (Request $request) use (&$chargeChanges) {
            if (str_contains($request->url(), '/payment_intents/')) {
                return $this->readResponse($request);
            }
            if (str_contains($request->url(), '/charges/')) {
                $charge = [
                    'id' => 'ch_manual_refund_fixture',
                    'object' => 'charge',
                    'livemode' => false,
                    'payment_intent' => 'pi_manual_refund_fixture',
                    'amount' => 1000,
                    'currency' => 'usd',
                    'paid' => true,
                    'refunded' => false,
                    'disputed' => false,
                    'amount_refunded' => 0,
                ];

                return Http::response(
                    array_replace($charge, $chargeChanges),
                    200,
                    $this->headers('req_manual_refund_unsafe_charge')
                );
            }

            self::fail('No Stripe write is allowed for an unsafe charge state.');
        });

        $service = app(ManualPaymentRefundService::class);
        foreach ([
            ['amount_refunded' => 100],
            ['refunded' => true, 'amount_refunded' => 1000],
            ['disputed' => true],
        ] as $changes) {
            $chargeChanges = $changes;
            $this->blocked(fn () => $service->refund(1, 3), 409);
        }

        self::assertSame(0, DB::table('stripe_operation_requests')->count());
        self::assertSame(1, DB::table('payment_journal_entries')->count());
    }

    public function test_success_webhook_finalizes_once_and_charge_event_is_observed(): void
    {
        $requests = [];
        $this->fakeSuccessfulWorkflow($requests, false);
        $service = app(ManualPaymentRefundService::class);
        self::assertSame('awaiting_webhook', $service->refund(1, 3)['status']);

        $refundReceipt = $this->receipt($this->refundEvent());
        $reconciler = app(StripePaymentIntentReconciler::class);
        self::assertSame('processed', $reconciler->process(
            $refundReceipt,
            null,
            $service->reconcileEvent(...)
        ));
        self::assertSame('already_processed', $reconciler->process(
            $refundReceipt,
            null,
            $service->reconcileEvent(...)
        ));
        self::assertSame('refunded', DB::table('payments')->value('status'));
        self::assertNotNull(DB::table('payments')->value('refunded_at'));
        self::assertSame(1, DB::table('payment_journal_entries')
            ->where('entry_type', 'customer_full_refund_succeeded')->count());
        self::assertSame('refunded', $service->status(1)['state']);
        self::assertSame('refunded', $service->refund(1, 3)['status']);

        $charge = $this->refundEvent();
        $charge['id'] = 'evt_manual_charge_refunded';
        $charge['type'] = 'charge.refunded';
        $charge['data']['object'] = [
            'id' => 'ch_manual_refund_fixture',
            'object' => 'charge',
            'livemode' => false,
            'payment_intent' => 'pi_manual_refund_fixture',
            'amount' => 1000,
            'currency' => 'usd',
            'paid' => true,
            'refunded' => true,
            'amount_refunded' => 1000,
        ];
        $chargeReceipt = $this->receipt($charge);
        self::assertSame('processed', $reconciler->process(
            $chargeReceipt,
            null,
            $service->reconcileEvent(...)
        ));
        self::assertSame(1, DB::table('payment_journal_entries')
            ->where('entry_type', 'customer_charge_refunded_observed')->count());
    }

    public function test_early_refund_webhook_fails_closed_and_replays_after_operation_finishes(): void
    {
        $requests = [];
        $this->fakeSuccessfulWorkflow($requests, false);
        $service = app(ManualPaymentRefundService::class);
        self::assertSame('awaiting_webhook', $service->refund(1, 3)['status']);
        $operation = DB::table('stripe_operation_requests')
            ->where('operation_type', ManualPaymentRefundService::REFUND_OPERATION)
            ->first(['id']);
        DB::table('stripe_operation_processing')
            ->where('operation_request_id', $operation->id)
            ->update([
                'status' => 'processing',
                'stripe_object_id' => null,
                'completed_at' => null,
            ]);

        $receipt = $this->receipt($this->refundEvent('evt_manual_refund_early'));
        $reconciler = app(StripePaymentIntentReconciler::class);
        try {
            $reconciler->process($receipt, null, $service->reconcileEvent(...));
            self::fail('An unbound early refund event must request a retry.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('binding is pending', $exception->getMessage());
        }
        self::assertSame('failed', DB::table('stripe_event_processing')
            ->where('stripe_event_receipt_id', $receipt)->value('status'));
        self::assertSame('succeeded', DB::table('payments')->value('status'));

        DB::table('stripe_operation_processing')
            ->where('operation_request_id', $operation->id)
            ->update([
                'status' => 'completed',
                'stripe_object_id' => 're_manual_refund_fixture',
                'completed_at' => now(),
                'lease_token' => null,
                'lease_expires_at' => null,
            ]);
        self::assertSame('processed', $reconciler->process(
            $receipt,
            null,
            $service->reconcileEvent(...)
        ));
        self::assertSame(2, (int) DB::table('stripe_event_processing')
            ->where('stripe_event_receipt_id', $receipt)->value('attempts'));
        self::assertSame('refunded', DB::table('payments')->value('status'));
    }

    private function fakeSuccessfulWorkflow(array &$requests, bool $released): void
    {
        $this->expectedStripeRequests = $released ? 5 : 3;
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request;
            if ($request->method() === 'GET') {
                return $this->readResponse($request);
            }
            if (str_ends_with($request->url(), '/reversals')) {
                return $this->reversalResponse($request);
            }
            if (str_ends_with($request->url(), '/refunds')) {
                return $this->refundResponse($request);
            }

            self::fail('Unexpected Stripe write: '.$request->url());
        });
    }

    private function readResponse(Request $request)
    {
        if (str_contains($request->url(), '/payment_intents/')) {
            return Http::response([
                'id' => 'pi_manual_refund_fixture',
                'object' => 'payment_intent',
                'livemode' => false,
                'status' => 'succeeded',
                'amount' => 1000,
                'amount_received' => 1000,
                'currency' => 'usd',
                'latest_charge' => 'ch_manual_refund_fixture',
                'transfer_group' => 'trustfix_job_1_refund_fixture',
                'transfer_data' => null,
                'application_fee_amount' => null,
                'on_behalf_of' => null,
            ], 200, $this->headers('req_manual_refund_intent'));
        }
        if (str_contains($request->url(), '/charges/')) {
            return Http::response([
                'id' => 'ch_manual_refund_fixture',
                'object' => 'charge',
                'livemode' => false,
                'payment_intent' => 'pi_manual_refund_fixture',
                'amount' => 1000,
                'currency' => 'usd',
                'paid' => true,
                'refunded' => false,
                'disputed' => false,
                'amount_refunded' => 0,
            ], 200, $this->headers('req_manual_refund_charge'));
        }
        if (str_contains($request->url(), '/transfers/')) {
            return Http::response([
                'id' => 'tr_manual_refund_fixture',
                'object' => 'transfer',
                'livemode' => false,
                'amount' => 900,
                'currency' => 'usd',
                'destination' => 'acct_manual_refund_fixture',
                'source_transaction' => 'ch_manual_refund_fixture',
                'transfer_group' => 'trustfix_job_1_refund_fixture',
                'metadata' => $this->metadata(),
                'reversed' => false,
                'amount_reversed' => 0,
            ], 200, $this->headers('req_manual_refund_transfer'));
        }

        self::fail('Unexpected Stripe read: '.$request->url());
    }

    private function reversalResponse(Request $request)
    {
        self::assertSame('900', (string) $request->data()['amount']);

        return Http::response([
            'id' => 'trr_manual_refund_fixture',
            'object' => 'transfer_reversal',
            'amount' => 900,
            'currency' => 'usd',
            'transfer' => 'tr_manual_refund_fixture',
            'metadata' => $request->data()['metadata'],
        ], 200, $this->headers('req_manual_refund_reversal'));
    }

    private function refundResponse(Request $request)
    {
        self::assertSame('1000', (string) $request->data()['amount']);

        return Http::response([
            'id' => 're_manual_refund_fixture',
            'object' => 'refund',
            'amount' => 1000,
            'currency' => 'usd',
            'payment_intent' => 'pi_manual_refund_fixture',
            'charge' => 'ch_manual_refund_fixture',
            'reason' => 'requested_by_customer',
            'status' => 'succeeded',
            'metadata' => $request->data()['metadata'],
            'source_transfer_reversal' => null,
            'transfer_reversal' => null,
        ], 200, $this->headers('req_manual_refund_created'));
    }

    private function seedCompletedRelease(): void
    {
        $payload = json_encode([
            'amount_cents' => 900,
            'currency' => 'usd',
            'destination' => 'acct_manual_refund_fixture',
            'transfer_group' => 'trustfix_job_1_refund_fixture',
            'source_transaction' => 'ch_manual_refund_fixture',
            'description' => 'TrustFix contractor proceeds for job #1',
            'metadata' => [
                'trustfix_job_id' => 1,
                'trustfix_payment_id' => 1,
                'trustfix_installment_id' => 1,
                'trustfix_agreement_id' => 1,
            ],
        ], JSON_THROW_ON_ERROR);
        $now = now();
        $id = DB::table('stripe_operation_requests')->insertGetId([
            'payment_installment_id' => 1,
            'operation_type' => ManualPaymentReleaseService::OPERATION,
            'operation_key' => 'payment:1:contractor_release_v1',
            'idempotency_key' => 'trustfix_transfer_fixture_release_key',
            'stripe_api_version' => StripePaymentGateway::API_VERSION,
            'credential_sha256' => hash('sha256', config('services.stripe.secret')),
            'payload_encrypted' => Crypt::encryptString($payload),
            'payload_sha256' => hash('sha256', $payload),
            'actor_id' => 3,
            'retry_until' => $now->copy()->addHours(23),
            'created_at' => $now,
        ]);
        DB::table('stripe_operation_processing')->insert([
            'operation_request_id' => $id,
            'status' => 'completed',
            'attempts' => 1,
            'last_attempt_at' => $now,
            'completed_at' => $now,
            'stripe_object_id' => 'tr_manual_refund_fixture',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function refundEvent(string $id = 'evt_manual_refund_succeeded'): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => 'refund.created',
            'api_version' => StripePaymentGateway::API_VERSION,
            'created' => 1794096000,
            'livemode' => false,
            'data' => ['object' => [
                'id' => 're_manual_refund_fixture',
                'object' => 'refund',
                'amount' => 1000,
                'currency' => 'usd',
                'payment_intent' => 'pi_manual_refund_fixture',
                'charge' => 'ch_manual_refund_fixture',
                'reason' => 'requested_by_customer',
                'status' => 'succeeded',
                'metadata' => $this->metadata(),
                'source_transfer_reversal' => null,
                'transfer_reversal' => null,
            ]],
        ];
    }

    private function metadata(): array
    {
        return [
            'trustfix_payment_model' => StripePaymentGateway::PAYMENT_MODEL,
            'trustfix_job_id' => '1',
            'trustfix_payment_id' => '1',
            'trustfix_installment_id' => '1',
            'trustfix_agreement_id' => '1',
        ];
    }

    private function receipt(array $event): int
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$payload,
            self::WEBHOOK_SECRET
        );

        return app(StripeEventInbox::class)->receive(
            $payload,
            "t={$timestamp},v1={$signature}"
        );
    }

    private function operationState(string $type): object
    {
        return DB::table('stripe_operation_processing as state')
            ->join('stripe_operation_requests as request', 'request.id', '=', 'state.operation_request_id')
            ->where('request.operation_type', $type)
            ->first(['state.*']);
    }

    private function headers(string $requestId): array
    {
        return [
            'Request-Id' => $requestId,
            'Stripe-Version' => StripePaymentGateway::API_VERSION,
        ];
    }

    private function blocked(callable $action, int $status): void
    {
        try {
            $action();
            self::fail("Expected HTTP {$status}.");
        } catch (HttpException $exception) {
            self::assertSame($status, $exception->getStatusCode());
        }
    }
}
