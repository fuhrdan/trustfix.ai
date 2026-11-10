<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StripeApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use LogicException;

final class StripePaymentGateway
{
    public const API_VERSION = '2026-10-28.endive';
    public const PAYMENT_MODEL = 'separate_charges_transfers_v1';
    public const CHECKOUT_REDIRECTS = 'never';

    public function __construct(private readonly PaymentSafetyGuard $guard)
    {
    }

    // The caller must authorize the payer and durably reserve the operation/key
    // before this call. Creation responses do not establish that payment succeeded.
    public function createPaymentIntent(
        mixed $amountCents,
        string $transferGroup,
        array $metadata,
        string $idempotencyKey,
        string $description = ''
    ): array {
        $this->guard->assertPaymentsEnabled();

        if (!is_int($amountCents) || $amountCents < 50 || $amountCents > 99999999) {
            throw new InvalidArgumentException('Payment amount must be supported integer USD cents.');
        }

        if (preg_match('/\A[A-Za-z0-9:_-]{1,100}\z/', $transferGroup) !== 1
            || preg_match('/\A[A-Za-z0-9:_-]{16,255}\z/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('A valid operation key and transfer group are required.');
        }

        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !mb_check_encoding($key, 'UTF-8')
                || mb_strlen($key, 'UTF-8') < 1 || mb_strlen($key, 'UTF-8') > 40
                || strpbrk($key, '[]') !== false
                || (!is_string($value) && !is_int($value))) {
                throw new InvalidArgumentException('Unsupported payment metadata.');
            }

            $value = (string) $value;
            if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 500) {
                throw new InvalidArgumentException('Unsupported payment metadata.');
            }

            $normalized[$key] = $value;
        }

        if (array_key_exists('trustfix_payment_model', $normalized)
            && $normalized['trustfix_payment_model'] !== self::PAYMENT_MODEL) {
            throw new InvalidArgumentException('Payment model metadata conflicts with this gateway.');
        }

        $normalized['trustfix_payment_model'] = self::PAYMENT_MODEL;

        if (count($normalized) > 50 || !mb_check_encoding($description, 'UTF-8')) {
            throw new InvalidArgumentException('Unsupported payment metadata or description.');
        }

        ksort($normalized);

        $parameters = [
            'amount' => $amountCents,
            'currency' => 'usd',
            'capture_method' => 'automatic',
            'automatic_payment_methods' => [
                'enabled' => 'true', 'allow_redirects' => self::CHECKOUT_REDIRECTS,
            ],
            'transfer_group' => $transferGroup,
            'metadata' => $normalized,
        ];

        if ($description !== '') {
            $parameters['description'] = $description;
        }

        $result = $this->financialObject(
            $this->send('POST', '/payment_intents', $parameters, $idempotencyKey),
            'payment_intent',
            'pi_'
        );

        $intent = $result['data'];
        $returnedMetadata = $intent['metadata'] ?? null;

        if (is_array($returnedMetadata)) {
            ksort($returnedMetadata);
        }

        if (($intent['amount'] ?? null) !== $amountCents
            || ($intent['currency'] ?? null) !== 'usd'
            || ($intent['transfer_group'] ?? null) !== $transferGroup
            || $returnedMetadata !== $normalized
            || ($intent['capture_method'] ?? null) !== 'automatic'
            || !is_string($intent['client_secret'] ?? null)
            || $intent['client_secret'] === ''
            || ($intent['transfer_data'] ?? null) !== null
            || ($intent['application_fee_amount'] ?? null) !== null
            || ($intent['on_behalf_of'] ?? null) !== null
            || !self::excludesRedirects($intent)) {
            throw new StripeApiException(
                'intent_mismatch', $result['http_status'], $result['request_id'], true
            );
        }

        return $result;
    }

    // A manual release moves only the recorded contractor proceeds. The caller
    // must reserve an immutable operation before invoking this write.
    public function createTransfer(
        mixed $amountCents,
        string $destination,
        string $transferGroup,
        string $sourceTransaction,
        array $metadata,
        string $idempotencyKey,
        string $description = ''
    ): array {
        $this->guard->assertTransfersEnabled();

        if (!is_int($amountCents) || $amountCents < 1 || $amountCents > 99999999) {
            throw new InvalidArgumentException('Transfer amount must be supported integer USD cents.');
        }

        $this->identifier($destination, 'acct_');
        $this->identifier($sourceTransaction, 'ch_');

        if (preg_match('/\A[A-Za-z0-9:_-]{1,100}\z/', $transferGroup) !== 1
            || preg_match('/\A[A-Za-z0-9:_-]{16,255}\z/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('A valid operation key and transfer group are required.');
        }

        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !mb_check_encoding($key, 'UTF-8')
                || mb_strlen($key, 'UTF-8') < 1 || mb_strlen($key, 'UTF-8') > 40
                || strpbrk($key, '[]') !== false
                || (!is_string($value) && !is_int($value))) {
                throw new InvalidArgumentException('Unsupported transfer metadata.');
            }

            $value = (string) $value;
            if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 500) {
                throw new InvalidArgumentException('Unsupported transfer metadata.');
            }

            $normalized[$key] = $value;
        }

        if (array_key_exists('trustfix_payment_model', $normalized)
            && $normalized['trustfix_payment_model'] !== self::PAYMENT_MODEL) {
            throw new InvalidArgumentException('Payment model metadata conflicts with this gateway.');
        }

        $normalized['trustfix_payment_model'] = self::PAYMENT_MODEL;

        if (count($normalized) > 50 || !mb_check_encoding($description, 'UTF-8')) {
            throw new InvalidArgumentException('Unsupported transfer metadata or description.');
        }

        ksort($normalized);
        $parameters = [
            'amount' => $amountCents,
            'currency' => 'usd',
            'destination' => $destination,
            'transfer_group' => $transferGroup,
            'source_transaction' => $sourceTransaction,
            'metadata' => $normalized,
        ];

        if ($description !== '') {
            $parameters['description'] = $description;
        }

        $result = $this->financialObject(
            $this->send('POST', '/transfers', $parameters, $idempotencyKey),
            'transfer',
            'tr_'
        );
        $transfer = $result['data'];
        $returnedMetadata = $transfer['metadata'] ?? null;

        if (is_array($returnedMetadata)) {
            ksort($returnedMetadata);
        }

        if (($transfer['amount'] ?? null) !== $amountCents
            || ($transfer['currency'] ?? null) !== 'usd'
            || ($transfer['destination'] ?? null) !== $destination
            || ($transfer['transfer_group'] ?? null) !== $transferGroup
            || ($transfer['source_transaction'] ?? null) !== $sourceTransaction
            || $returnedMetadata !== $normalized
            || ($transfer['reversed'] ?? null) !== false
            || ($transfer['amount_reversed'] ?? null) !== 0) {
            throw new StripeApiException(
                'transfer_mismatch',
                $result['http_status'],
                $result['request_id'],
                true
            );
        }

        return $result;
    }
    // Reads stay available while collection is paused, subject to the test guard.
    public function retrievePaymentIntent(string $id): array
    {
        $this->identifier($id, 'pi_');

        return $this->financialObject(
            $this->send('GET', '/payment_intents/' . $id),
            'payment_intent',
            'pi_',
            $id
        );
    }

    public function retrieveCharge(string $id): array
    {
        $this->identifier($id, 'ch_');

        return $this->financialObject(
            $this->send('GET', '/charges/' . $id),
            'charge',
            'ch_',
            $id
        );
    }

    public static function excludesRedirects(#[\SensitiveParameter] array $intent): bool
    {
        $methods = $intent['automatic_payment_methods'] ?? null;

        return is_array($methods) && ($methods['enabled'] ?? null) === true
            && ($methods['allow_redirects'] ?? null) === self::CHECKOUT_REDIRECTS;
    }

    private function identifier(string $id, string $prefix): void
    {
        if (strlen($id) > 191
            || preg_match('/\A' . preg_quote($prefix, '/') . '[A-Za-z0-9_]+\z/', $id) !== 1) {
            throw new InvalidArgumentException('Invalid Stripe resource identifier.');
        }
    }

    private function send(
        string $method,
        string $path,
        array $parameters = [],
        ?string $key = null
    ): array {
        $secret = $this->guard->apiSecret();

        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new LogicException('Stripe requests must run outside database transactions.');
            }
        }

        $headers = ['Stripe-Version' => self::API_VERSION];
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        $request = Http::asForm()->acceptJson()->withToken($secret)
            ->withHeaders($headers)->connectTimeout(5)->timeout(15)->withoutRedirecting();

        $write = $method === 'POST';

        try {
            // No transport retries: durable operation/reconciliation code owns retries.
            $response = $write
                ? $request->post('https://api.stripe.com/v1' . $path, $parameters)
                : $request->get('https://api.stripe.com/v1' . $path);
        } catch (ConnectionException) {
            throw new StripeApiException('transport_error', null, null, $write);
        }

        $requestId = $response->header('Request-Id');
        $requestId = is_string($requestId)
            && preg_match('/\Areq_[A-Za-z0-9_]+\z/', $requestId) === 1
            ? $requestId : null;

        $status = $response->status();

        if ($status !== 200) {
            throw new StripeApiException('http_error', $status, $requestId, $write);
        }

        $version = $response->header('Stripe-Version');

        if (is_string($version) && $version !== '' && $version !== self::API_VERSION) {
            throw new StripeApiException('unexpected_version', $status, $requestId, $write);
        }

        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new StripeApiException('invalid_json', $status, $requestId, $write);
        }

        if (!is_array($data)) {
            throw new StripeApiException('invalid_response', $status, $requestId, $write);
        }

        return [
            'data' => $data,
            'http_status' => $status,
            'request_id' => $requestId,
            'api_version' => self::API_VERSION,
            'write' => $write,
        ];
    }

    private function financialObject(
        #[\SensitiveParameter] array $result,
        string $type,
        string $prefix,
        ?string $id = null
    ): array {
        $object = $result['data'];
        $objectId = $object['id'] ?? null;

        if (($object['object'] ?? null) !== $type
            || ($object['livemode'] ?? null) !== false
            || !is_string($objectId) || strlen($objectId) > 191
            || preg_match('/\A' . preg_quote($prefix, '/') . '[A-Za-z0-9_]+\z/', $objectId) !== 1
            || ($id !== null && $objectId !== $id)) {
            throw new StripeApiException(
                'unsafe_object',
                $result['http_status'],
                $result['request_id'],
                $result['write']
            );
        }

        return $result;
    }
}
