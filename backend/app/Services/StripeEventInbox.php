<?php

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StripeEventInbox
{
    public function __construct(
        private readonly PaymentSafetyGuard $guard
    ) {
    }

    // Intake only: the returned receipt ID does not mean processing succeeded.
    // Configure this endpoint for Stripe snapshot events.
    public function receive(string $payload, string $signature): int
    {
        $this->verifySignature($payload, $signature);

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'Invalid Stripe event JSON.');
        }

        if (!is_array($event)) {
            throw new HttpException(400, 'Invalid Stripe event JSON.');
        }

        $this->guard->assertWebhookAllowed($event);
        $this->validateEvent($event);

        try {
            return DB::transaction(function () use ($event, $payload): int {
                $receivedAt = now();
                $receiptId = DB::table('stripe_event_receipts')->insertGetId([
                    'stripe_event_id' => $event['id'],
                    'stripe_account_id' => $event['account'] ?? null,
                    'event_type' => $event['type'],
                    'livemode' => $event['livemode'],
                    'api_version' => $event['api_version'] ?? null,
                    'stripe_created_at' => $event['created'],
                    'payload_encrypted' => Crypt::encryptString($payload),
                    'payload_sha256' => hash('sha256', $payload),
                    'created_at' => $receivedAt,
                ]);

                DB::table('stripe_event_processing')->insert([
                    'stripe_event_receipt_id' => $receiptId,
                    'status' => 'pending',
                    'attempts' => 0,
                    'created_at' => $receivedAt,
                    'updated_at' => $receivedAt,
                ]);

                return (int) $receiptId;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // The failed transaction has rolled back before duplicate lookup.
            $receipt = DB::table('stripe_event_receipts')
                ->useWritePdo()
                ->where('stripe_event_id', $event['id'])
                ->first();

            if (!$receipt) {
                throw $exception;
            }

            if (
                $receipt->event_type !== $event['type']
                || $receipt->stripe_account_id !== ($event['account'] ?? null)
                || (bool) $receipt->livemode !== $event['livemode']
                || $receipt->api_version !== ($event['api_version'] ?? null)
                || (int) $receipt->stripe_created_at !== $event['created']
            ) {
                throw new HttpException(409, 'Conflicting Stripe event identity.');
            }

            $hasProcessingState = DB::table('stripe_event_processing')
                ->useWritePdo()
                ->where('stripe_event_receipt_id', $receipt->id)
                ->exists();

            if (!$hasProcessingState) {
                throw new HttpException(503, 'Stripe event processing state requires reconciliation.');
            }

            // Preserve the original payload and processing state, including
            // failed attempts. The processor must decide whether to retry.
            return (int) $receipt->id;
        }
    }

    private function verifySignature(string $payload, string $header): void
    {
        $secret = config('services.stripe.webhook_secret');
        if (!is_string($secret) || $secret === '') {
            throw new HttpException(503, 'Stripe webhook signing is not configured.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't') {
                if (
                    $timestamp !== null
                    || preg_match('/\A[0-9]{1,12}\z/', $value) !== 1
                ) {
                    throw new HttpException(400, 'Invalid Stripe signature.');
                }
                $timestamp = $value;
            } elseif (
                $name === 'v1'
                && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1
            ) {
                $signatures[] = $value;
            }
        }

        if (
            $timestamp === null
            || abs(time() - (int) $timestamp) > 300
            || $signatures === []
        ) {
            throw new HttpException(400, 'Invalid Stripe signature.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new HttpException(400, 'Invalid Stripe signature.');
    }

    private function validateEvent(array $event): void
    {
        $type = $event['type'] ?? null;
        $created = $event['created'] ?? null;
        $account = $event['account'] ?? null;
        $version = $event['api_version'] ?? null;
        $data = $event['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        $valid = ($event['object'] ?? null) === 'event'
            && $this->identifier($event['id'] ?? null, 'evt_')
            && is_string($type)
            && strlen($type) <= 128
            && preg_match('/\A[a-z0-9_]+(?:\.[a-z0-9_]+)+\z/', $type) === 1
            && is_int($created)
            && $created >= 0
            && ($account === null || $this->identifier($account, 'acct_'))
            && ($version === null || (
                is_string($version) && $version !== '' && strlen($version) <= 64
            ))
            && is_array($object)
            && is_string($object['id'] ?? null)
            && $object['id'] !== ''
            && strlen($object['id']) <= 191
            && is_string($object['object'] ?? null)
            && $object['object'] !== '';

        if (!$valid) {
            throw new HttpException(400, 'Invalid Stripe snapshot event.');
        }
    }

    private function identifier(mixed $value, string $prefix): bool
    {
        return is_string($value)
            && strlen($value) <= 191
            && preg_match(
                '/\A' . preg_quote($prefix, '/') . '[A-Za-z0-9_]+\z/',
                $value
            ) === 1;
    }
}
