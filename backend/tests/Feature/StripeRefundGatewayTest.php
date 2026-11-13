<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\StripeApiException;
use App\Services\StripePaymentGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StripeRefundGatewayTest extends TestCase
{
    private const SECRET = 'sk_test_refund_gateway_fixture';

    private const MODEL = StripePaymentGateway::PAYMENT_MODEL;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('testing', app()->environment());
        config([
            'app.env' => 'testing',
            'payments.enabled' => true,
            'payments.releases_enabled' => true,
            'payments.refunds_enabled' => true,
            'payments.test_mode_only' => true,
            'payments.auto_release_enabled' => false,
            'services.stripe.secret' => self::SECRET,
            'services.stripe.key' => 'pk_test_refund_gateway_fixture',
            'services.stripe.webhook_secret' => 'whsec_refund_gateway_fixture',
        ]);
        Http::preventStrayRequests();
    }

    public function test_full_refund_and_transfer_reversal_use_separate_guarded_writes(): void
    {
        Http::fake(function (Request $request) {
            $metadata = $request->data()['metadata'];
            if (str_ends_with($request->url(), '/v1/transfers/tr_refund_fixture/reversals')) {
                return Http::response([
                    'id' => 'trr_refund_fixture',
                    'object' => 'transfer_reversal',
                    'amount' => 900,
                    'currency' => 'usd',
                    'transfer' => 'tr_refund_fixture',
                    'metadata' => $metadata,
                ], 200, $this->headers('req_refund_reversal_fixture'));
            }
            if (str_ends_with($request->url(), '/v1/refunds')) {
                return Http::response([
                    'id' => 're_refund_fixture',
                    'object' => 'refund',
                    'amount' => 1000,
                    'currency' => 'usd',
                    'payment_intent' => 'pi_refund_fixture',
                    'charge' => 'ch_refund_fixture',
                    'reason' => 'requested_by_customer',
                    'status' => 'succeeded',
                    'metadata' => $metadata,
                    'source_transfer_reversal' => null,
                    'transfer_reversal' => null,
                ], 200, $this->headers('req_refund_fixture'));
            }

            self::fail('Unexpected Stripe fixture request: '.$request->url());
        });

        $metadata = [
            'trustfix_job_id' => 1,
            'trustfix_payment_id' => 1,
        ];
        $gateway = app(StripePaymentGateway::class);
        $reversal = $gateway->createTransferReversal(
            900,
            'tr_refund_fixture',
            $metadata,
            'trustfix_reversal_fixture_key'
        );
        $refund = $gateway->createFullRefund(
            1000,
            'pi_refund_fixture',
            'ch_refund_fixture',
            $metadata,
            'trustfix_refund_fixture_key'
        );

        self::assertSame('trr_refund_fixture', $reversal['data']['id']);
        self::assertSame('re_refund_fixture', $refund['data']['id']);
        self::assertTrue($reversal['write']);
        self::assertTrue($refund['write']);

        $recorded = Http::recorded()->map(fn (array $pair) => $pair[0])->all();
        self::assertCount(2, $recorded);
        self::assertSame(
            'https://api.stripe.com/v1/transfers/tr_refund_fixture/reversals',
            $recorded[0]->url()
        );
        self::assertSame('https://api.stripe.com/v1/refunds', $recorded[1]->url());
        self::assertTrue($recorded[0]->hasHeader('Idempotency-Key', 'trustfix_reversal_fixture_key'));
        self::assertTrue($recorded[1]->hasHeader('Idempotency-Key', 'trustfix_refund_fixture_key'));
        self::assertSame('900', (string) $recorded[0]->data()['amount']);
        self::assertSame('1000', (string) $recorded[1]->data()['amount']);
        self::assertSame('pi_refund_fixture', $recorded[1]->data()['payment_intent']);
        self::assertSame('requested_by_customer', $recorded[1]->data()['reason']);
        self::assertSame(self::MODEL, $recorded[0]->data()['metadata']['trustfix_payment_model']);
        self::assertSame(self::MODEL, $recorded[1]->data()['metadata']['trustfix_payment_model']);
        foreach (['reverse_transfer', 'refund_application_fee', 'charge'] as $field) {
            self::assertArrayNotHasKey($field, $recorded[1]->data());
        }
    }

    public function test_refund_resources_without_livemode_are_strictly_context_checked(): void
    {
        $response = [];
        Http::fake(function () use (&$response) {
            return Http::response(
                $response,
                200,
                $this->headers('req_refund_mismatch_fixture')
            );
        });
        $metadata = [
            'trustfix_payment_id' => '1',
            'trustfix_payment_model' => self::MODEL,
        ];
        $base = [
            'id' => 're_refund_fixture',
            'object' => 'refund',
            'amount' => 1000,
            'currency' => 'usd',
            'payment_intent' => 'pi_refund_fixture',
            'charge' => 'ch_refund_fixture',
            'reason' => 'requested_by_customer',
            'status' => 'succeeded',
            'metadata' => $metadata,
            'source_transfer_reversal' => null,
            'transfer_reversal' => null,
        ];

        foreach ([
            ['amount' => 999],
            ['currency' => 'eur'],
            ['payment_intent' => 'pi_other_fixture'],
            ['charge' => 'ch_other_fixture'],
            ['status' => 'failed'],
            ['reason' => 'fraudulent'],
            ['source_transfer_reversal' => 'trr_other_fixture'],
            ['metadata' => $metadata + ['unexpected' => 'value']],
        ] as $changes) {
            $response = array_replace($base, $changes);
            $error = $this->caught(fn () => app(StripePaymentGateway::class)->createFullRefund(
                1000,
                'pi_refund_fixture',
                'ch_refund_fixture',
                ['trustfix_payment_id' => 1],
                'trustfix_refund_fixture_key'
            ), StripeApiException::class);
            self::assertSame('refund_mismatch', $error->reason);
            self::assertTrue($error->outcomeUnknown);
        }
        Http::assertSentCount(8);
    }

    public function test_disabled_refunds_and_unsafe_configuration_block_all_writes(): void
    {
        Http::fake();
        $gateway = app(StripePaymentGateway::class);
        $actions = [
            fn () => $gateway->createTransferReversal(
                900,
                'tr_refund_fixture',
                [],
                'trustfix_reversal_fixture_key'
            ),
            fn () => $gateway->createFullRefund(
                1000,
                'pi_refund_fixture',
                'ch_refund_fixture',
                [],
                'trustfix_refund_fixture_key'
            ),
        ];

        foreach ([
            ['payments.refunds_enabled' => false],
            ['payments.test_mode_only' => false],
            ['payments.auto_release_enabled' => true],
            ['services.stripe.secret' => 'sk_live_refund_fixture'],
            ['app.env' => 'production'],
        ] as $override) {
            config($override);
            foreach ($actions as $action) {
                $error = $this->caught($action, HttpException::class);
                self::assertSame(503, $error->getStatusCode());
            }
            $this->restoreConfiguration();
        }

        Http::assertNothingSent();
    }

    private function restoreConfiguration(): void
    {
        config([
            'app.env' => 'testing',
            'payments.refunds_enabled' => true,
            'payments.test_mode_only' => true,
            'payments.auto_release_enabled' => false,
            'services.stripe.secret' => self::SECRET,
        ]);
    }

    private function headers(string $requestId): array
    {
        return [
            'Request-Id' => $requestId,
            'Stripe-Version' => StripePaymentGateway::API_VERSION,
        ];
    }

    /** @template T of \Throwable @param class-string<T> $type @return T */
    private function caught(callable $action, string $type): \Throwable
    {
        try {
            $action();
        } catch (\Throwable $exception) {
            self::assertInstanceOf($type, $exception);

            return $exception;
        }

        self::fail("Expected {$type}.");
    }
}
