<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\StripeApiException;
use App\Services\StripePaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

class StripePaymentGatewayTest extends TestCase
{
    // Non-working fixtures. No real Stripe credentials or resources are used.
    private const SECRET = 'sk_test_gateway_fixture';
    private const GROUP = 'trustfix_payment_fixture';
    private const KEY = 'trustfix_test_gateway_fixture_operation';
    private const CLIENT_SECRET = 'pi_gateway_fixture_secret_unusable';

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->configured();
    }

    public function test_platform_intent_has_fixed_version_and_no_automatic_transfer(): void
    {
        Http::fake(fn () => Http::response($this->intent(), 200, $this->headers()));
        $result = $this->create();
        self::assertSame('pi_gateway_fixture', $result['data']['id']);
        self::assertSame('requires_payment_method', $result['data']['status']);
        self::assertSame('req_gateway_fixture', $result['request_id']);
        self::assertSame(StripePaymentGateway::API_VERSION, $result['api_version']);
        self::assertTrue($result['write']);

        Http::assertSent(function (Request $request): bool {
            self::assertSame('POST', $request->method());
            self::assertSame('https://api.stripe.com/v1/payment_intents', $request->url());
            self::assertTrue($request->hasHeader('Authorization', 'Bearer ' . self::SECRET));
            self::assertTrue($request->hasHeader('Stripe-Version', StripePaymentGateway::API_VERSION));
            self::assertTrue($request->hasHeader('Idempotency-Key', self::KEY));
            self::assertTrue($request->hasHeader('Accept', 'application/json'));
            $data = $request->data();
            self::assertSame('12500', (string) $data['amount']);
            self::assertSame('usd', $data['currency']);
            self::assertSame('automatic', $data['capture_method']);
            self::assertSame(self::GROUP, $data['transfer_group']);
            self::assertSame('42', $data['metadata']['trustfix_payment_id']);
            self::assertSame(StripePaymentGateway::PAYMENT_MODEL, $data['metadata']['trustfix_payment_model']);

            foreach (['transfer_data', 'application_fee_amount', 'on_behalf_of', 'confirm', 'payment_method'] as $field) {
                self::assertArrayNotHasKey($field, $data);
            }

            return true;
        });

        Http::assertSentCount(1);
    }

    public function test_explicit_retry_reuses_key_and_canonical_request(): void
    {
        Http::fake(fn () => Http::response($this->intent(), 200, $this->headers()));
        $this->create();
        $this->create(['metadata' => ['trustfix_payment_id' => 42, 'trustfix_job_id' => 7]]);
        $requests = Http::recorded()->map(fn (array $pair) => $pair[0])->all();

        self::assertCount(2, $requests);
        self::assertSame($requests[0]->data(), $requests[1]->data());
        foreach ($requests as $request) {
            self::assertTrue($request->hasHeader('Idempotency-Key', self::KEY));
        }
    }

    public function test_unsafe_configuration_and_production_runtime_block_all_io(): void
    {
        Http::fake();

        foreach ([
            ['services.stripe.secret' => 'sk_live_gateway_fixture'],
            ['services.stripe.secret' => 'rk_live_gateway_fixture'],
            ['services.stripe.key' => 'pk_live_gateway_fixture'],
            ['services.stripe.secret' => ''],
            ['payments.test_mode_only' => false],
            ['payments.auto_release_enabled' => true],
            ['app.env' => 'production'],
        ] as $overrides) {
            $this->configured($overrides);
            foreach ($this->actions() as $action) {
                $error = $this->caught($action, HttpException::class);
                self::assertSame(503, $error->getStatusCode());
            }
        }

        $this->configured();
        app()->instance('env', 'production');
        try {
            foreach ($this->actions() as $action) {
                $error = $this->caught($action, HttpException::class);
                self::assertSame(503, $error->getStatusCode());
            }
        } finally {
            app()->instance('env', 'testing');
        }

        Http::assertNothingSent();
    }

    public function test_paused_collection_allows_only_guarded_reconciliation_reads(): void
    {
        $this->configured(['payments.enabled' => false, 'services.stripe.webhook_secret' => '']);
        Http::fake(function (Request $request) {
            $object = $request->url() === 'https://api.stripe.com/v1/charges/ch_gateway_fixture'
                ? ['id' => 'ch_gateway_fixture', 'object' => 'charge', 'livemode' => false]
                : $this->intent();

            return Http::response($object, 200, $this->headers());
        });

        $this->caught(fn () => $this->create(), HttpException::class);
        Http::assertNothingSent();

        self::assertSame('pi_gateway_fixture', app(StripePaymentGateway::class)
            ->retrievePaymentIntent('pi_gateway_fixture')['data']['id']);
        self::assertSame('ch_gateway_fixture', app(StripePaymentGateway::class)
            ->retrieveCharge('ch_gateway_fixture')['data']['id']);

        foreach (Http::recorded() as [$request]) {
            self::assertSame('GET', $request->method());
            self::assertFalse($request->hasHeader('Idempotency-Key'));
            self::assertTrue($request->hasHeader('Stripe-Version', StripePaymentGateway::API_VERSION));
        }

        Http::assertSentCount(2);
    }

    public function test_invalid_money_metadata_keys_and_resource_paths_never_reach_http(): void
    {
        Http::fake();
        $cases = [];

        foreach ([null, false, '12500', 12500.0, -1, 0, 49, 100000000] as $amount) {
            $cases[] = ['amount' => $amount];
        }
        foreach (['', 'bad group', "bad\nvalue", str_repeat('g', 101)] as $group) {
            $cases[] = ['group' => $group];
        }
        foreach (['', 'short', "valid_length_key\ninjected", str_repeat('k', 256)] as $key) {
            $cases[] = ['key' => $key];
        }
        foreach ([
            ['field' => []], ['field' => 1.5], ['field' => null],
            ['bad[key]' => 'value'], [str_repeat('k', 41) => 'value'],
            ['field' => str_repeat('x', 501)], ['field' => "\xFF"], ["\xFF" => 'value'],
            ['trustfix_payment_model' => 'destination'],
        ] as $metadata) {
            $cases[] = ['metadata' => $metadata];
        }

        $many = [];
        for ($index = 0; $index < 50; $index++) {
            $many['field_' . $index] = 'value';
        }
        $cases[] = ['metadata' => $many];
        $cases[] = ['description' => "\xFF"];

        foreach ($cases as $case) {
            $this->caught(fn () => $this->create($case), InvalidArgumentException::class);
        }

        foreach ([
            'pi_', 'ch_wrong', 'pi_path/other', 'pi_value?expand=customer',
            "pi_value\n", 'pi_' . str_repeat('x', 189),
        ] as $id) {
            $this->caught(fn () => app(StripePaymentGateway::class)->retrievePaymentIntent($id),
                InvalidArgumentException::class);
        }

        $this->caught(fn () => app(StripePaymentGateway::class)->retrieveCharge('pi_wrong'),
            InvalidArgumentException::class);
        Http::assertNothingSent();
    }

    public function test_supported_money_and_utf8_metadata_boundaries_are_preserved(): void
    {
        $metadata = [];
        for ($index = 0; $index < 48; $index++) {
            $metadata['field_' . $index] = 'value';
        }
        $longKey = str_repeat('k', 40);
        $metadata[$longKey] = str_repeat('é', 500);
        $response = [];

        Http::fake(function () use (&$response) {
            return Http::response($response, 200, $this->headers());
        });

        foreach ([50, 99999999] as $amount) {
            $normalized = $metadata;
            $normalized['trustfix_payment_model'] = StripePaymentGateway::PAYMENT_MODEL;
            $response = $this->intent(['amount' => $amount, 'metadata' => $normalized]);
            $result = $this->create(['amount' => $amount, 'metadata' => $metadata]);

            self::assertSame($amount, $result['data']['amount']);
            self::assertCount(50, $result['data']['metadata']);
            self::assertSame(str_repeat('é', 500), $result['data']['metadata'][$longKey]);
        }

        Http::assertSentCount(2);
    }

    public function test_live_ambiguous_and_wrong_financial_objects_are_rejected(): void
    {
        $response = [];
        Http::fake(function () use (&$response) {
            return Http::response($response, 200, $this->headers());
        });

        $cases = [
            ['livemode' => true], ['livemode' => null], ['livemode' => 0], ['livemode' => 'false'],
            ['object' => 'charge'], ['id' => 'pi_other_fixture'], ['id' => 'ch_wrong'],
            ['id' => null], ['id' => "pi_gateway_fixture\n"],
        ];

        foreach ($cases as $case) {
            $response = $this->intent($case);
            $error = $this->caught(fn () => app(StripePaymentGateway::class)
                ->retrievePaymentIntent('pi_gateway_fixture'), StripeApiException::class);
            self::assertSame('unsafe_object', $error->reason);
            self::assertFalse($error->outcomeUnknown);
        }

        Http::assertSentCount(count($cases));
    }

    public function test_creation_context_mismatches_are_uncertain_without_retries(): void
    {
        $response = [];
        Http::fake(function () use (&$response) {
            return Http::response($response, 200, $this->headers());
        });

        $wrongMetadata = $this->intent()['metadata'];
        $wrongMetadata['trustfix_payment_id'] = '999';
        $cases = [
            ['amount' => 12501], ['currency' => 'eur'], ['transfer_group' => 'other_group'],
            ['metadata' => $wrongMetadata], ['capture_method' => 'manual'],
            ['client_secret' => null], ['client_secret' => ''],
            ['transfer_data' => ['destination' => 'acct_unassigned_fixture']],
            ['application_fee_amount' => 0], ['on_behalf_of' => 'acct_unassigned_fixture'],
        ];

        foreach ($cases as $case) {
            $response = $this->intent($case);
            $error = $this->caught(fn () => $this->create(), StripeApiException::class);
            self::assertSame('intent_mismatch', $error->reason);
            self::assertTrue($error->outcomeUnknown);
            self::assertNull($error->getPrevious());
        }

        Http::assertSentCount(count($cases));
    }

    public function test_http_errors_and_redirects_are_redacted_without_retries(): void
    {
        $status = 500;
        $attempts = 0;
        Http::fake(function () use (&$status, &$attempts) {
            $attempts++;
            return Http::response(
                ['error' => ['message' => 'private-provider-message:' . self::SECRET]],
                $status,
                $this->headers() + ['Location' => 'https://not-stripe.invalid/']
            );
        });

        foreach ([302, 400, 409, 429, 500] as $status) {
            foreach ([
                fn () => $this->create(),
                fn () => app(StripePaymentGateway::class)->retrievePaymentIntent('pi_gateway_fixture'),
            ] as $index => $action) {
                $error = $this->caught($action, StripeApiException::class);
                self::assertSame('http_error', $error->reason);
                self::assertSame($status, $error->httpStatus);
                self::assertSame($index === 0, $error->outcomeUnknown);
                self::assertStringNotContainsString('private-provider-message', $error->getMessage());
                self::assertStringNotContainsString(self::SECRET, $error->getMessage());
                self::assertNull($error->getPrevious());
            }
        }

        self::assertSame(10, $attempts);
    }

    public function test_invalid_json_protocol_and_version_drift_fail_safely(): void
    {
        $body = '';
        $headers = $this->headers();
        Http::fake(function () use (&$body, &$headers) {
            return Http::response($body, 200, $headers);
        });

        foreach ([
            '{' => 'invalid_json', 'null' => 'invalid_response',
            '"scalar"' => 'invalid_response', '[]' => 'unsafe_object',
        ] as $body => $reason) {
            $error = $this->caught(fn () => $this->create(), StripeApiException::class);
            self::assertSame($reason, $error->reason);
            self::assertTrue($error->outcomeUnknown);
        }

        $body = json_encode($this->intent(), JSON_THROW_ON_ERROR);
        $headers['Stripe-Version'] = '2020-08-27';
        $error = $this->caught(fn () => $this->create(), StripeApiException::class);
        self::assertSame('unexpected_version', $error->reason);

        $headers = ['Request-Id' => 'untrusted-header-value'];
        $result = $this->create();
        self::assertNull($result['request_id']);
        Http::assertSentCount(6);
    }

    public function test_connection_failures_expose_no_previous_error_and_do_not_retry(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('private-transport-message:' . self::SECRET);
        });

        foreach ([
            fn () => $this->create(),
            fn () => app(StripePaymentGateway::class)->retrievePaymentIntent('pi_gateway_fixture'),
        ] as $index => $action) {
            $error = $this->caught($action, StripeApiException::class);
            self::assertSame('transport_error', $error->reason);
            self::assertSame($index === 0, $error->outcomeUnknown);
            self::assertNull($error->httpStatus);
            self::assertNull($error->requestId);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString(self::SECRET, $error->getMessage());
        }

        self::assertSame(2, $attempts);
    }

    public function test_transactions_on_any_connection_block_stripe_io(): void
    {
        Http::fake();
        $name = 'stripe_gateway_memory';
        config(['database.connections.' . $name => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $connection = DB::connection($name);
        self::assertSame(':memory:', $connection->getDatabaseName());
        $connection->beginTransaction();

        try {
            foreach ([
                fn () => $this->create(),
                fn () => app(StripePaymentGateway::class)->retrievePaymentIntent('pi_gateway_fixture'),
            ] as $action) {
                $this->caught($action, LogicException::class);
            }
            Http::assertNothingSent();
        } finally {
            $connection->rollBack();
            DB::purge($name);
        }
    }

    public function test_provider_response_arguments_are_redacted_from_exception_traces(): void
    {
        Http::fake(fn () => Http::response(
            $this->intent(['livemode' => true, 'client_secret' => 'private-client-secret-marker']),
            200,
            $this->headers()
        ));

        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);

        try {
            $error = $this->caught(fn () => app(StripePaymentGateway::class)
                ->retrievePaymentIntent('pi_gateway_fixture'), StripeApiException::class);

            $found = false;
            foreach ($error->getTrace() as $frame) {
                if (($frame['class'] ?? null) === StripePaymentGateway::class
                    && ($frame['function'] ?? null) === 'financialObject') {
                    self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][0] ?? null);
                    $found = true;
                }
            }

            self::assertTrue($found);
            self::assertStringNotContainsString(
                'private-client-secret-marker',
                json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR)
            );
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    private function configured(array $overrides = []): void
    {
        config(array_replace([
            'app.env' => 'testing',
            'payments.enabled' => true,
            'payments.releases_enabled' => false,
            'payments.refunds_enabled' => false,
            'payments.auto_release_enabled' => false,
            'payments.test_mode_only' => true,
            'services.stripe.secret' => self::SECRET,
            'services.stripe.key' => 'pk_test_gateway_fixture',
            'services.stripe.webhook_secret' => 'whsec_gateway_fixture',
        ], $overrides));
    }

    private function create(array $overrides = []): array
    {
        $values = array_replace([
            'amount' => 12500,
            'group' => self::GROUP,
            'metadata' => ['trustfix_job_id' => 7, 'trustfix_payment_id' => 42],
            'key' => self::KEY,
            'description' => 'TrustFix job #7',
        ], $overrides);

        return app(StripePaymentGateway::class)->createPaymentIntent(
            $values['amount'], $values['group'], $values['metadata'],
            $values['key'], $values['description']
        );
    }

    private function actions(): array
    {
        return [
            fn () => $this->create(),
            fn () => app(StripePaymentGateway::class)->retrievePaymentIntent('pi_gateway_fixture'),
            fn () => app(StripePaymentGateway::class)->retrieveCharge('ch_gateway_fixture'),
        ];
    }

    private function intent(array $overrides = []): array
    {
        return array_replace([
            'id' => 'pi_gateway_fixture',
            'object' => 'payment_intent',
            'livemode' => false,
            'amount' => 12500,
            'currency' => 'usd',
            'capture_method' => 'automatic',
            'client_secret' => self::CLIENT_SECRET,
            'status' => 'requires_payment_method',
            'transfer_group' => self::GROUP,
            'metadata' => [
                'trustfix_payment_model' => StripePaymentGateway::PAYMENT_MODEL,
                'trustfix_payment_id' => '42',
                'trustfix_job_id' => '7',
            ],
            'transfer_data' => null,
            'application_fee_amount' => null,
            'on_behalf_of' => null,
        ], $overrides);
    }

    private function headers(): array
    {
        return [
            'Stripe-Version' => StripePaymentGateway::API_VERSION,
            'Request-Id' => 'req_gateway_fixture',
        ];
    }

    private function caught(callable $action, string $class): Throwable
    {
        try {
            $action();
        } catch (Throwable $exception) {
            self::assertInstanceOf($class, $exception);

            return $exception;
        }

        self::fail("Expected $class.");
    }
}
