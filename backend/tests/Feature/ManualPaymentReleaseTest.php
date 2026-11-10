<?php

namespace Tests\Feature;

use App\Services\ManualPaymentReleaseService;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ManualPaymentReleaseTest extends TestCase
{
    private const CONNECTION = 'manual_payment_release_memory';

    private Connection $memoryDb;
    private string $previousConnection = 'sqlite';
    private int $expectedStripeResponses = 0;

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
            'services.stripe.secret' => 'sk_test_manual_release_fixture',
            'services.stripe.key' => 'pk_test_manual_release_fixture',
            'services.stripe.webhook_secret' => 'whsec_manual_release_fixture',
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
            $migration = require database_path('migrations/' . $migrationFile);
            $migration->up();
        }

        DB::table('payments')->insert([
            'id' => 1,
            'job_id' => 1,
            'customer_id' => 1,
            'contractor_id' => 2,
            'stripe_payment_intent_id' => 'pi_manual_release_fixture',
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
            'payment_model' => 'separate_charges_transfers_v1',
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
            'transfer_group' => 'trustfix_job_1_fixture',
            'installment_sha256' => str_repeat('b', 64),
            'created_at' => now(),
        ]);
        DB::table('contractor_profiles')->insert([
            'user_id' => 2,
            'status' => 'approved',
            'stripe_account_id' => 'acct_manual_release_fixture',
            'stripe_details_submitted' => true,
            'stripe_payouts_enabled' => true,
        ]);
        DB::table('payment_journal_entries')->insert([
            'payment_id' => 1,
            'entry_key' => 'stripe-event:fixture:payment_succeeded',
            'entry_type' => 'payment_succeeded',
            'amount_cents' => 1000,
            'currency' => 'usd',
            'stripe_object_id' => 'pi_manual_release_fixture',
            'stripe_event_receipt_id' => null,
            'actor_id' => null,
            'details' => json_encode([
                'stripe_charge_id' => 'ch_manual_release_fixture',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            Http::assertSentCount($this->expectedStripeResponses);
        } finally {
            if (isset($this->memoryDb)) {
                DB::purge(self::CONNECTION);
                DB::setDefaultConnection($this->previousConnection);
                Schema::clearResolvedInstance('db.schema');
            }
            parent::tearDown();
        }
    }

    public function test_manual_release_creates_one_transfer_and_replay_is_local(): void
    {
        $requests = [];
        $this->fakeSuccessfulRelease($requests);

        $service = app(ManualPaymentReleaseService::class);
        self::assertSame('ready', $service->status(1)['release']['state']);

        $first = $service->release(1, 3);
        self::assertSame('completed', $first['status']);
        self::assertSame(900, $first['contractor_proceeds_cents']);
        self::assertSame('tr_manual_release_fixture', $first['stripe_transfer_id']);
        self::assertSame($first, $service->release(1, 3));

        self::assertSame('released', $service->status(1)['release']['state']);
        self::assertCount(3, $requests);
        self::assertSame(1, DB::table('stripe_operation_requests')
            ->where('operation_type', ManualPaymentReleaseService::OPERATION)->count());
        self::assertSame(1, DB::table('payment_journal_entries')
            ->where('entry_type', 'contractor_transfer_created')->count());
        self::assertSame(1, DB::table('payments')->count());
        self::assertSame('succeeded', DB::table('payments')->value('status'));
    }

    public function test_transport_retry_reuses_the_transfer_payload_and_key(): void
    {
        $posts = [];
        $successfulResponses = 0;
        Http::fake(function (Request $request) use (&$posts, &$successfulResponses) {
            if ($request->method() === 'GET') {
                $successfulResponses++;
                return $this->readResponse($request);
            }

            $posts[] = [$request->header('Idempotency-Key'), $request->data()];
            if (count($posts) === 1) {
                throw new ConnectionException('Fixture network timeout');
            }

            $successfulResponses++;
            return $this->transferResponse($request);
        });

        $service = app(ManualPaymentReleaseService::class);
        self::assertSame('pending', $service->release(1, 3)['status']);
        self::assertSame('transport_error', DB::table('stripe_operation_processing')
            ->value('last_error_code'));
        self::assertSame('completed', $service->release(1, 3)['status']);

        self::assertCount(2, $posts);
        self::assertSame($posts[0], $posts[1]);
        self::assertMatchesRegularExpression(
            '/\Atrustfix_transfer_[a-f0-9]{32}\z/',
            $posts[0][0][0]
        );
        self::assertSame(2, (int) DB::table('stripe_operation_processing')->value('attempts'));
        $this->expectedStripeResponses = $successfulResponses;
    }

    public function test_local_holds_block_stripe_before_an_operation_is_reserved(): void
    {
        $service = app(ManualPaymentReleaseService::class);

        DB::table('jobs')->where('id', 1)->update(['status' => 'in_progress']);
        $this->blocked(fn () => $service->release(1, 3), 409);
        DB::table('jobs')->where('id', 1)->update(['status' => 'completed']);

        DB::table('disputes')->insert(['job_id' => 1, 'status' => 'open']);
        $this->blocked(fn () => $service->release(1, 3), 409);
        DB::table('disputes')->delete();

        DB::table('contractor_profiles')->update(['stripe_payouts_enabled' => false]);
        $this->blocked(fn () => $service->release(1, 3), 409);
        DB::table('contractor_profiles')->update(['stripe_payouts_enabled' => true]);

        config(['payments.releases_enabled' => false]);
        $this->blocked(fn () => $service->release(1, 3), 503);

        self::assertSame(0, DB::table('stripe_operation_requests')->count());
        self::assertSame(1, DB::table('payment_journal_entries')->count());
    }

    public function test_provider_mismatch_never_creates_a_transfer_operation(): void
    {
        $this->expectedStripeResponses = 2;
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/payment_intents/')) {
                return Http::response($this->intent(['amount_received' => 999]), 200, [
                    'Request-Id' => 'req_manual_mismatch_intent',
                    'Stripe-Version' => '2026-10-28.endive',
                ]);
            }

            return $this->readResponse($request);
        });

        $this->blocked(
            fn () => app(ManualPaymentReleaseService::class)->release(1, 3),
            409
        );
        Http::assertNotSent(fn (Request $request) =>
            $request->method() === 'POST' && str_ends_with($request->url(), '/transfers')
        );
        self::assertSame(0, DB::table('stripe_operation_requests')->count());
        self::assertSame(1, DB::table('payment_journal_entries')->count());
    }

    private function fakeSuccessfulRelease(array &$requests): void
    {
        $this->expectedStripeResponses = 3;
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request;

            return $request->method() === 'GET'
                ? $this->readResponse($request)
                : $this->transferResponse($request);
        });
    }

    private function readResponse(Request $request)
    {
        if (str_contains($request->url(), '/payment_intents/')) {
            return Http::response($this->intent(), 200, [
                'Request-Id' => 'req_manual_release_intent',
                'Stripe-Version' => '2026-10-28.endive',
            ]);
        }
        if (str_contains($request->url(), '/charges/')) {
            return Http::response([
                'id' => 'ch_manual_release_fixture',
                'object' => 'charge',
                'livemode' => false,
                'payment_intent' => 'pi_manual_release_fixture',
                'amount' => 1000,
                'currency' => 'usd',
                'paid' => true,
                'refunded' => false,
                'disputed' => false,
                'amount_refunded' => 0,
            ], 200, [
                'Request-Id' => 'req_manual_release_charge',
                'Stripe-Version' => '2026-10-28.endive',
            ]);
        }

        self::fail('Unexpected Stripe read: ' . $request->url());
    }

    private function transferResponse(Request $request)
    {
        self::assertSame('POST', $request->method());
        self::assertTrue(str_ends_with($request->url(), '/v1/transfers'));
        self::assertSame(900, $request->data()['amount']);
        self::assertSame('usd', $request->data()['currency']);
        self::assertSame('acct_manual_release_fixture', $request->data()['destination']);
        self::assertSame('ch_manual_release_fixture', $request->data()['source_transaction']);
        self::assertSame('trustfix_job_1_fixture', $request->data()['transfer_group']);

        return Http::response([
            'id' => 'tr_manual_release_fixture',
            'object' => 'transfer',
            'livemode' => false,
            'amount' => 900,
            'currency' => 'usd',
            'destination' => 'acct_manual_release_fixture',
            'source_transaction' => 'ch_manual_release_fixture',
            'transfer_group' => 'trustfix_job_1_fixture',
            'metadata' => $request->data()['metadata'],
            'reversed' => false,
            'amount_reversed' => 0,
        ], 200, [
            'Request-Id' => 'req_manual_release_transfer',
            'Stripe-Version' => '2026-10-28.endive',
        ]);
    }

    private function intent(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'pi_manual_release_fixture',
            'object' => 'payment_intent',
            'livemode' => false,
            'status' => 'succeeded',
            'amount' => 1000,
            'amount_received' => 1000,
            'currency' => 'usd',
            'latest_charge' => 'ch_manual_release_fixture',
            'transfer_group' => 'trustfix_job_1_fixture',
            'transfer_data' => null,
            'application_fee_amount' => null,
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