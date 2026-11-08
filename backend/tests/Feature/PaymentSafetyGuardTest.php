<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Services\PaymentSafetyGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PaymentSafetyGuardTest extends TestCase
{
    // Deliberately invalid fixtures; these are not usable Stripe credentials.
    private const SECRET = 'sk_test_guardfixture';
    private const PUBLIC_KEY = 'pk_test_guardfixture';
    private const WEBHOOK_SECRET = 'whsec_guardfixture';

    public function test_controls_default_to_disabled(): void
    {
        foreach (['enabled', 'releases_enabled', 'refunds_enabled', 'auto_release_enabled'] as $name) {
            self::assertFalse(config("payments.$name"));
        }
        self::assertTrue(config('payments.test_mode_only'));
        self::assertSame(
            ['publishable_key' => null, 'enabled' => false],
            app(PaymentController::class)->publicConfig()->getData(true)
        );
        $guard = app(PaymentSafetyGuard::class);
        foreach (['assertPaymentsEnabled', 'assertTransfersEnabled', 'assertRefundsEnabled'] as $method) {
            $this->denied(fn () => $guard->$method(), 503, $method);
        }
        Http::assertNothingSent();
    }

    public function test_disabled_controller_actions_do_not_query_or_call_stripe(): void
    {
        $this->configured(['payments.enabled' => false]);
        $queries = 0;
        DB::listen(static function ($event) use (&$queries): void {
            $queries++;
        });
        $controller = app(PaymentController::class);
        foreach (['createConnectAccount', 'refreshConnectAccount', 'createIntent'] as $method) {
            $parameters = $method === 'createIntent' ? ['jobId' => 1] : [];
            $this->denied(fn () => app()->call([$controller, $method], $parameters), 503, $method);
        }
        self::assertSame(0, $queries);
        Http::assertNothingSent();
    }

    public function test_only_test_key_shapes_can_be_exposed_or_used(): void
    {
        foreach ([self::SECRET, 'rk_test_guardfixture'] as $secret) {
            $guard = $this->configured(['services.stripe.secret' => $secret]);
            self::assertSame($secret, $guard->apiSecret());
            $guard->assertPaymentsEnabled();
            $guard->assertTransfersEnabled();
            $guard->assertRefundsEnabled();
            self::assertSame(
                ['publishable_key' => self::PUBLIC_KEY, 'enabled' => true],
                app(PaymentController::class)->publicConfig()->getData(true)
            );
        }
        Http::assertNothingSent();
    }

    public function test_unsafe_configuration_cannot_enable_payments(): void
    {
        $cases = [
            ['services.stripe.secret' => 'sk_live_guardfixture'],
            ['services.stripe.secret' => 'rk_live_guardfixture'],
            ['services.stripe.key' => 'pk_live_guardfixture'],
            ['services.stripe.secret' => ''],
            ['services.stripe.secret' => 'sk_test_'],
            ['services.stripe.secret' => "sk_test_guardfixture\n"],
            ['services.stripe.secret' => ['invalid']],
            ['services.stripe.key' => "pk_test_guardfixture\n"],
            ['services.stripe.webhook_secret' => ''],
            ['services.stripe.webhook_secret' => 'invalid'],
            ['payments.test_mode_only' => false],
            ['payments.auto_release_enabled' => true],
            ['app.env' => 'production'],
        ];
        foreach ($cases as $number => $overrides) {
            $guard = $this->configured($overrides);
            $this->denied(fn () => $guard->assertPaymentsEnabled(), 503, "configuration case $number");
            self::assertNull($guard->publishableKey(), "configuration case $number");
        }
        Http::assertNothingSent();
    }

    public function test_production_runtime_remains_blocked_with_all_flags_enabled(): void
    {
        $guard = $this->configured();
        app()->instance('env', 'production');
        foreach (['apiSecret', 'assertPaymentsEnabled', 'assertTransfersEnabled', 'assertRefundsEnabled'] as $method) {
            $this->denied(fn () => $guard->$method(), 503, $method);
        }
        $this->denied(fn () => $guard->assertWebhookAllowed(['livemode' => false]));
        self::assertNull($guard->publishableKey());
        Http::assertNothingSent();
    }

    public function test_controls_require_explicit_boolean_true(): void
    {
        foreach ([
            'payments.enabled' => 'assertPaymentsEnabled',
            'payments.releases_enabled' => 'assertTransfersEnabled',
            'payments.refunds_enabled' => 'assertRefundsEnabled',
        ] as $key => $method) {
            foreach ([false, null, 1, 'true', 'false'] as $value) {
                $guard = $this->configured([$key => $value]);
                $this->denied(fn () => $guard->$method(), 503, $key);
            }
        }
        Http::assertNothingSent();
    }

    public function test_pausing_collection_does_not_disable_refunds_or_event_handling(): void
    {
        $guard = $this->configured([
            'payments.enabled' => false,
            'payments.releases_enabled' => false,
        ]);
        $this->denied(fn () => $guard->assertPaymentsEnabled());
        $this->denied(fn () => $guard->assertTransfersEnabled());
        $guard->assertRefundsEnabled();
        $guard->assertWebhookAllowed(['livemode' => false]);
        self::assertSame(self::SECRET, $guard->apiSecret());
        self::assertNull($guard->publishableKey());

        config(['payments.releases_enabled' => true, 'payments.refunds_enabled' => false]);
        $guard->assertTransfersEnabled();
        $this->denied(fn () => $guard->assertRefundsEnabled());
        Http::assertNothingSent();
    }

    public function test_live_or_ambiguous_event_modes_are_rejected(): void
    {
        $guard = $this->configured(['payments.enabled' => false]);
        foreach ([['livemode' => true], [], ['livemode' => 0], ['livemode' => 'false']] as $event) {
            $this->denied(fn () => $guard->assertWebhookAllowed($event), 400);
        }
        $guard->assertWebhookAllowed(['livemode' => false]);
        self::assertSame(self::SECRET, $guard->apiSecret());
        Http::assertNothingSent();
    }

    public function test_webhook_rejects_live_malformed_and_invalidly_signed_requests_before_queries(): void
    {
        $this->configured(['payments.enabled' => false]);
        $queries = 0;
        DB::listen(static function ($event) use (&$queries): void {
            $queries++;
        });
        $testPayload = json_encode([
            'id' => 'evt_guardfixture',
            'type' => 'payment_intent.succeeded',
            'livemode' => false,
            'data' => ['object' => ['id' => 'pi_guardfixture']],
        ], JSON_THROW_ON_ERROR);
        $livePayload = str_replace('"livemode":false', '"livemode":true', $testPayload);
        foreach ([
            [$livePayload, self::WEBHOOK_SECRET, time()],
            ['{', self::WEBHOOK_SECRET, time()],
            [$testPayload, 'whsec_wrongfixture', time()],
            [$testPayload, self::WEBHOOK_SECRET, time() - 3600],
        ] as [$payload, $secret, $timestamp]) {
            $signature = 't=' . $timestamp . ',v1='
                . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
            $request = Request::create('/api/stripe/webhook', 'POST', [], [], [], [
                'HTTP_STRIPE_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ], $payload);
            $this->denied(fn () => app(PaymentController::class)->webhook($request), 400);
        }
        self::assertSame(0, $queries);
        Http::assertNothingSent();
    }

    private function configured(array $overrides = []): PaymentSafetyGuard
    {
        config(array_replace([
            'app.env' => 'testing',
            'payments.enabled' => true,
            'payments.releases_enabled' => true,
            'payments.refunds_enabled' => true,
            'payments.auto_release_enabled' => false,
            'payments.test_mode_only' => true,
            'services.stripe.secret' => self::SECRET,
            'services.stripe.key' => self::PUBLIC_KEY,
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ], $overrides));

        return app(PaymentSafetyGuard::class);
    }

    private function denied(callable $action, int $status = 503, string $context = ''): void
    {
        try {
            $action();
        } catch (HttpException $exception) {
            self::assertSame($status, $exception->getStatusCode(), $context);

            return;
        }

        self::fail("Expected HTTP $status: $context");
    }
}
