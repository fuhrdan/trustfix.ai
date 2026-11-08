<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

final class StripeEventProcessor
{
    private const LEASE_SECONDS = 300;

    public function __construct(
        private readonly PaymentSafetyGuard $guard
    ) {
    }

    /**
     * The handler must perform database changes only and explicitly return
     * "processed" or "ignored". External requests must run outside this method.
     *
     * @param callable(array, int): string $handler
     */
    public function process(int $receiptId, callable $handler): string
    {
        if ($receiptId < 1) {
            throw new InvalidArgumentException('A valid Stripe receipt ID is required.');
        }

        // Reject production or live configuration before accessing event state.
        $this->guard->apiSecret();

        $token = bin2hex(random_bytes(32));
        $claim = $this->claim($receiptId, $token);
        if ($claim !== 'claimed') {
            return $claim;
        }

        try {
            $receipt = DB::table('stripe_event_receipts')
                ->useWritePdo()
                ->find($receiptId);

            if (!$receipt) {
                throw new RuntimeException('Stripe event receipt requires reconciliation.');
            }

            $payload = Crypt::decryptString($receipt->payload_encrypted);
            if (!hash_equals((string) $receipt->payload_sha256, hash('sha256', $payload))) {
                throw new RuntimeException('Stripe event payload integrity check failed.');
            }

            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (
                !is_array($event)
                || ($event['id'] ?? null) !== $receipt->stripe_event_id
                || ($event['type'] ?? null) !== $receipt->event_type
            ) {
                throw new RuntimeException('Stored Stripe event identity is invalid.');
            }

            $this->guard->assertWebhookAllowed($event);

            return DB::transaction(function () use ($receiptId, $token, $event, $handler): string {
                $state = DB::table('stripe_event_processing')
                    ->where('stripe_event_receipt_id', $receiptId)
                    ->lockForUpdate()
                    ->first();

                if (!$state) {
                    throw new RuntimeException('Stripe event processing state requires reconciliation.');
                }

                // An expired worker cannot commit after another worker claims
                // ownership. The row lock protects the handler through commit.
                if (
                    $state->status !== 'processing'
                    || !hash_equals((string) $state->lease_token, $token)
                ) {
                    return 'superseded';
                }

                $outcome = $handler($event, $receiptId);
                if (!in_array($outcome, ['processed', 'ignored'], true)) {
                    throw new LogicException('Stripe handlers must explicitly choose an outcome.');
                }

                $changed = DB::table('stripe_event_processing')
                    ->where('id', $state->id)
                    ->where('status', 'processing')
                    ->where('lease_token', $token)
                    ->update([
                        'status' => $outcome,
                        'processed_at' => now(),
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'last_error_code' => null,
                        'updated_at' => now(),
                    ]);

                if ($changed !== 1) {
                    throw new RuntimeException('Stripe event ownership changed before completion.');
                }

                return $outcome;
            }, 3);
        } catch (Throwable $exception) {
            try {
                // Never overwrite another worker's state or a completed event.
                DB::table('stripe_event_processing')
                    ->where('stripe_event_receipt_id', $receiptId)
                    ->where('status', 'processing')
                    ->where('lease_token', $token)
                    ->update([
                        'status' => 'failed',
                        'processed_at' => null,
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'last_error_code' => 'processing_failed',
                        'updated_at' => now(),
                    ]);
            } catch (Throwable) {
                // If the database is unavailable, the durable lease expires
                // and allows recovery. Preserve the original processing error.
            }

            throw $exception;
        }
    }

    private function claim(int $receiptId, string $token): string
    {
        return DB::transaction(function () use ($receiptId, $token): string {
            $state = DB::table('stripe_event_processing')
                ->where('stripe_event_receipt_id', $receiptId)
                ->lockForUpdate()
                ->first();

            if (!$state) {
                throw new RuntimeException('Stripe event processing state requires reconciliation.');
            }

            if (in_array($state->status, ['processed', 'ignored'], true)) {
                if ($state->processed_at === null) {
                    throw new RuntimeException('Stripe event completion state requires reconciliation.');
                }

                return 'already_processed';
            }

            if (!in_array($state->status, ['pending', 'failed', 'processing'], true)) {
                throw new RuntimeException('Unexpected Stripe event processing state.');
            }

            if (
                $state->status === 'processing'
                && $state->lease_expires_at !== null
                && Carbon::parse($state->lease_expires_at)->greaterThan(now())
            ) {
                return 'busy';
            }

            $attempts = (int) $state->attempts;
            $startedAt = now();
            $changed = DB::table('stripe_event_processing')
                ->where('id', $state->id)
                ->where('status', $state->status)
                ->where('attempts', $attempts)
                ->where('lease_token', $state->lease_token)
                ->update([
                    'status' => 'processing',
                    'attempts' => $attempts + 1,
                    'last_attempt_at' => $startedAt,
                    'processed_at' => null,
                    'lease_token' => $token,
                    'lease_expires_at' => $startedAt->copy()->addSeconds(self::LEASE_SECONDS),
                    'last_error_code' => null,
                    'updated_at' => $startedAt,
                ]);

            return $changed === 1 ? 'claimed' : 'busy';
        }, 3);
    }
}
