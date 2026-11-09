<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StripeApiException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;

final class StripePaymentIntentExecutor
{
    private const LEASE_SECONDS = 90;
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly PaymentSafetyGuard $guard,
        private readonly PaymentAgreementService $agreements,
        private readonly StripePaymentGateway $gateway
    ) {
    }

    /** @return array{status: string, payment_id: int, stripe_payment_intent_id: ?string} */
    public function executeInitial(int $operationId, int $customerId): array
    {
        $this->guard->assertPaymentsEnabled();
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new LogicException('Stripe operation execution requires a committed reservation.');
            }
        }

        $credentialHash = hash('sha256', $this->guard->apiSecret());
        $claim = $this->claim($operationId, $customerId, $credentialHash);
        if ($claim['status'] !== 'claimed') {
            return $claim;
        }
        $request = $claim['request'];
        if ($claim['lease_until']->lessThanOrEqualTo(now())) {
            return $this->result('busy', $request);
        }
        if (Carbon::parse($request->retry_until)->lessThanOrEqualTo(now())) {
            return $this->finish($claim, null, null, 'retry_window_closed');
        }
        if (!hash_equals($request->credential_sha256, hash('sha256', $this->guard->apiSecret()))) {
            return $this->finish($claim, null, null, 'credentials_changed');
        }

        $payload = $claim['payload'];
        try {
            $response = $this->gateway->createPaymentIntent(
                $payload['amount_cents'],
                $payload['transfer_group'],
                $payload['metadata'],
                $request->idempotency_key,
                $payload['description']
            );
        } catch (StripeApiException $exception) {
            $error = $exception->httpStatus === null ? $exception->reason : $exception->reason . '_' . $exception->httpStatus;

            return $this->finish($claim, null, $exception->requestId, $error);
        }

        return $this->finish($claim, $response['data']['id'], $response['request_id'], null);
    }

    private function claim(int $operationId, int $customerId, string $credentialHash): array
    {
        return DB::transaction(function () use ($operationId, $customerId, $credentialHash): array {
            // These immutable rows locate the job. All mutable checks follow its lock.
            $request = DB::table('stripe_operation_requests')
                ->join('payment_installments', 'payment_installments.id', '=', 'stripe_operation_requests.payment_installment_id')
                ->join('payment_agreements', 'payment_agreements.id', '=', 'payment_installments.payment_agreement_id')
                ->where('stripe_operation_requests.id', $operationId)
                ->where('stripe_operation_requests.operation_type', PaymentCheckoutReservation::OPERATION)
                ->first([
                    'stripe_operation_requests.*', 'payment_installments.payment_id',
                    'payment_installments.payment_agreement_id', 'payment_installments.amount_cents',
                    'payment_installments.platform_fee_cents', 'payment_installments.currency',
                    'payment_installments.transfer_group', 'payment_agreements.job_id',
                    'payment_agreements.contractor_id', 'payment_agreements.agreement_sha256',
                ]);
            abort_unless($request, 404, 'Stripe checkout request not found.');
            abort_unless((int) $request->actor_id === $customerId, 403, 'Only the authorizing customer may continue checkout.');
            $agreement = $this->agreements->lockApprovedInitial(
                (int) $request->payment_agreement_id, $customerId, $request->agreement_sha256
            );
            $state = DB::table('stripe_operation_processing')->where('operation_request_id', $operationId)
                ->lockForUpdate()->first();
            abort_unless($state, 409, 'Stripe operation state requires reconciliation.');
            $payment = DB::table('payments')->where('id', $request->payment_id)->lockForUpdate()->first();
            abort_unless($this->matchesPayment($payment, $request)
                && (int) $request->amount_cents === (int) $agreement->amount_cents
                && (int) $request->platform_fee_cents === (int) $agreement->platform_fee_cents
                && $request->currency === 'usd', 409, 'Checkout amounts require reconciliation.');

            $now = now();
            if ($state->status === 'completed') {
                if (!is_string($state->stripe_object_id) || strlen($state->stripe_object_id) > 191
                    || preg_match('/\Api_[A-Za-z0-9_]+\z/', $state->stripe_object_id) !== 1
                    || $state->stripe_object_id !== $payment->stripe_payment_intent_id
                    || !hash_equals($request->credential_sha256, $credentialHash)) {
                    return $this->result('reconciliation_required', $request);
                }

                return $this->result('completed', $request, $state->stripe_object_id);
            }
            if ($state->status === 'reconciliation_required') {
                return $this->result('reconciliation_required', $request);
            }
            if ($state->status === 'processing' && $state->lease_expires_at !== null
                && Carbon::parse($state->lease_expires_at)->greaterThan($now)) {
                return $this->result('busy', $request);
            }

            $hold = match (true) {
                !in_array($state->status, ['pending', 'processing'], true) => 'unexpected_operation_state',
                !hash_equals($request->credential_sha256, $credentialHash) => 'credentials_changed',
                $request->stripe_api_version !== StripePaymentGateway::API_VERSION => 'api_version_changed',
                $payment->stripe_payment_intent_id !== null || $payment->status !== 'requires_payment_method' => 'payment_already_observed',
                Carbon::parse($request->retry_until)->lessThanOrEqualTo($now) => 'retry_window_closed',
                (int) $state->attempts >= self::MAX_ATTEMPTS => 'attempt_limit_reached',
                default => null,
            };
            $payload = $hold === null ? $this->payload($request) : null;
            if ($payload === null) {
                $reason = $hold ?? 'invalid_saved_request';
                DB::table('stripe_operation_processing')->where('operation_request_id', $operationId)->update([
                    'status' => 'reconciliation_required', 'lease_token' => null, 'lease_expires_at' => null,
                    'last_error_code' => $reason, 'updated_at' => $now,
                ]);
                $this->journal($request, 'intent_creation_held', 'held', null, ['error_code' => $reason]);

                return $this->result('reconciliation_required', $request);
            }

            $attempt = (int) $state->attempts + 1;
            $token = bin2hex(random_bytes(32));
            $leaseUntil = $now->copy()->addSeconds(self::LEASE_SECONDS);
            DB::table('stripe_operation_processing')->where('operation_request_id', $operationId)->update([
                'status' => 'processing', 'attempts' => $attempt, 'lease_token' => $token,
                'lease_expires_at' => $leaseUntil, 'last_attempt_at' => $now,
                'last_error_code' => null, 'updated_at' => $now,
            ]);
            $this->journal($request, 'intent_creation_started', "attempt:{$attempt}:started", null, ['attempt' => $attempt]);

            return ['status' => 'claimed', 'request' => $request, 'payload' => $payload,
                'token' => $token, 'lease_until' => $leaseUntil, 'attempt' => $attempt];
        }, 3);
    }

    private function finish(
        #[\SensitiveParameter] array $claim,
        ?string $intentId,
        ?string $requestId,
        ?string $error
    ): array {
        return DB::transaction(function () use ($claim, $intentId, $requestId, $error): array {
            $request = $claim['request'];
            DB::table('jobs')->where('id', $request->job_id)->lockForUpdate()->first(['id']);
            $state = DB::table('stripe_operation_processing')->where('operation_request_id', $request->id)
                ->lockForUpdate()->first();
            if (!$state || $state->status !== 'processing' || $state->lease_token !== $claim['token']) {
                return $this->result('busy', $request);
            }

            $payment = DB::table('payments')->where('id', $request->payment_id)->lockForUpdate()->first();
            if ($intentId !== null && (!$this->matchesPayment($payment, $request)
                || ($payment->stripe_payment_intent_id !== null && $payment->stripe_payment_intent_id !== $intentId))) {
                $error = 'intent_binding_conflict';
            }

            $now = now();
            $attempt = $claim['attempt'];
            $details = ['attempt' => $attempt, 'stripe_request_id' => $requestId];
            $processing = DB::table('stripe_operation_processing')->where('operation_request_id', $request->id);
            if ($error !== null) {
                $retry = $error === 'transport_error' && $attempt < self::MAX_ATTEMPTS
                    && Carbon::parse($request->retry_until)->greaterThan($now);
                $status = $retry ? 'pending' : 'reconciliation_required';
                $processing->update([
                    'status' => $status, 'lease_token' => null, 'lease_expires_at' => null,
                    'last_error_code' => $error, 'updated_at' => $now,
                ]);
                $this->journal($request, 'intent_creation_failed', "attempt:{$attempt}:failed", $intentId,
                    $details + ['error_code' => $error, 'status' => $status]);

                return $this->result($status, $request);
            }

            // Record the provider identity. Verified events own the payment status.
            DB::table('payments')->where('id', $request->payment_id)->whereNull('stripe_payment_intent_id')
                ->update(['stripe_payment_intent_id' => $intentId, 'updated_at' => $now]);
            $processing->update([
                'status' => 'completed', 'stripe_object_id' => $intentId, 'completed_at' => $now,
                'lease_token' => null, 'lease_expires_at' => null, 'last_error_code' => null, 'updated_at' => $now,
            ]);
            $this->journal($request, 'payment_intent_created', 'completed', $intentId, $details);

            return $this->result('completed', $request, $intentId);
        }, 3);
    }

    private function payload(#[\SensitiveParameter] object $request): ?array
    {
        try {
            $plaintext = Crypt::decryptString($request->payload_encrypted);
            if (!hash_equals($request->payload_sha256, hash('sha256', $plaintext))) {
                return null;
            }
            $payload = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException | JsonException) {
            return null;
        }

        $expected = [
            'amount_cents' => (int) $request->amount_cents,
            'transfer_group' => $request->transfer_group,
            'description' => "TrustFix job #{$request->job_id}",
            'allow_redirects' => StripePaymentGateway::CHECKOUT_REDIRECTS,
            'metadata' => [
                'trustfix_job_id' => (int) $request->job_id,
                'trustfix_payment_id' => (int) $request->payment_id,
                'trustfix_installment_id' => (int) $request->payment_installment_id,
                'trustfix_agreement_id' => (int) $request->payment_agreement_id,
                'trustfix_agreement_sha256' => $request->agreement_sha256,
            ],
        ];

        return $payload === $expected && preg_match('/\Atrustfix_intent_[a-f0-9]{32}\z/', $request->idempotency_key) === 1
            ? $payload : null;
    }

    private function matchesPayment(?object $payment, #[\SensitiveParameter] object $request): bool
    {
        return $payment !== null
            && (int) $payment->job_id === (int) $request->job_id
            && (int) $payment->customer_id === (int) $request->actor_id
            && (int) $payment->contractor_id === (int) $request->contractor_id
            && (int) $payment->amount_cents === (int) $request->amount_cents
            && (int) $payment->platform_fee_cents === (int) $request->platform_fee_cents
            && $payment->currency === $request->currency;
    }

    private function journal(
        #[\SensitiveParameter] object $request,
        string $type,
        string $suffix,
        ?string $stripeId,
        array $details
    ): void {
        DB::table('payment_journal_entries')->insert([
            'payment_id' => (int) $request->payment_id,
            'entry_key' => "stripe-operation:{$request->id}:{$suffix}",
            'entry_type' => $type,
            'amount_cents' => (int) $request->amount_cents,
            'currency' => $request->currency,
            'stripe_object_id' => $stripeId,
            'actor_id' => (int) $request->actor_id,
            'details' => json_encode($details + ['operation_request_id' => (int) $request->id], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function result(string $status, #[\SensitiveParameter] object $request, ?string $intentId = null): array
    {
        return ['status' => $status, 'payment_id' => (int) $request->payment_id, 'stripe_payment_intent_id' => $intentId];
    }
}
