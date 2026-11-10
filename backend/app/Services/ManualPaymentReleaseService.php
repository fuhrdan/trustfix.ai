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

final class ManualPaymentReleaseService
{
    public const OPERATION = 'create_transfer_v1';

    private const LEASE_SECONDS = 90;
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly PaymentSafetyGuard $guard,
        private readonly StripePaymentGateway $gateway
    ) {
    }

    public function status(int $jobId): array
    {
        $this->guard->assertTransfersEnabled();
        abort_if($jobId < 1, 404, 'Job not found.');

        $payment = DB::table('payments as payment')
            ->join('payment_installments as installment', 'installment.payment_id', '=', 'payment.id')
            ->join('payment_agreements as agreement', 'agreement.id', '=', 'installment.payment_agreement_id')
            ->join('jobs as job', 'job.id', '=', 'agreement.job_id')
            ->join('users as contractor', 'contractor.id', '=', 'payment.contractor_id')
            ->where('agreement.job_id', $jobId)
            ->orderByDesc('payment.id')
            ->first([
                'payment.id', 'payment.status', 'payment.amount_cents',
                'payment.platform_fee_cents', 'payment.currency', 'payment.paid_at',
                'payment.stripe_payment_intent_id', 'installment.id as installment_id',
                'installment.transfer_group', 'agreement.job_id', 'payment.contractor_id',
                'job.status as job_status', 'contractor.name as contractor_name',
            ]);

        if ($payment === null) {
            return [
                'job_id' => $jobId,
                'payment' => null,
                'contractor' => null,
                'release' => [
                    'state' => 'unavailable',
                    'can_release' => false,
                    'reason' => 'No reserved payment exists for this job.',
                    'stripe_transfer_id' => null,
                ],
            ];
        }

        $profile = DB::table('contractor_profiles')
            ->where('user_id', $payment->contractor_id)
            ->first([
                'stripe_account_id', 'stripe_details_submitted',
                'stripe_payouts_enabled', 'status',
            ]);
        $funding = DB::table('payment_journal_entries')
            ->where('payment_id', $payment->id)
            ->where('entry_type', 'payment_succeeded')
            ->where('amount_cents', $payment->amount_cents)
            ->where('currency', $payment->currency)
            ->where('stripe_object_id', $payment->stripe_payment_intent_id)
            ->exists();
        $refund = DB::table('payment_journal_entries')
            ->where('payment_id', $payment->id)
            ->where('entry_type', 'like', '%refund%')
            ->exists();
        $dispute = DB::table('disputes')
            ->where('job_id', $jobId)
            ->whereIn('status', ['open', 'under_review'])
            ->exists();
        $operation = DB::table('stripe_operation_requests as request')
            ->join('stripe_operation_processing as state', 'state.operation_request_id', '=', 'request.id')
            ->where('request.payment_installment_id', $payment->installment_id)
            ->where('request.operation_type', self::OPERATION)
            ->orderByDesc('request.id')
            ->first(['request.id', 'state.status', 'state.stripe_object_id']);

        $ready = $payment->job_status === 'completed'
            && $payment->status === 'succeeded'
            && $payment->paid_at !== null
            && $funding
            && !$refund
            && !$dispute
            && $this->profileReady($profile);

        $state = match (true) {
            $operation?->status === 'completed'
                && $this->stripeId($operation->stripe_object_id, 'tr_') => 'released',
            $operation?->status === 'reconciliation_required' => 'reconciliation_required',
            $operation?->status === 'processing' => 'processing',
            $operation?->status === 'pending' => 'pending',
            $ready => 'ready',
            default => 'held',
        };

        $reason = match (true) {
            $state === 'released' => 'Contractor proceeds were released.',
            $state === 'processing' => 'The release is currently processing.',
            $state === 'pending' => 'The release can be safely retried.',
            $state === 'reconciliation_required' => 'The release requires administrator review.',
            $payment->job_status !== 'completed' => 'The job must be completed before funds are released.',
            $payment->status !== 'succeeded' || !$funding => 'Verified payment funding is required.',
            $refund => 'A refund record blocks contractor release.',
            $dispute => 'An open dispute blocks contractor release.',
            !$this->profileReady($profile) => 'The contractor payout account is not ready.',
            default => 'Contractor proceeds are ready for manual release.',
        };
        $proceeds = (int) $payment->amount_cents - (int) $payment->platform_fee_cents;

        return [
            'job_id' => (int) $payment->job_id,
            'job_status' => $payment->job_status,
            'payment' => [
                'id' => (int) $payment->id,
                'status' => $payment->status,
                'amount_cents' => (int) $payment->amount_cents,
                'platform_fee_cents' => (int) $payment->platform_fee_cents,
                'contractor_proceeds_cents' => $proceeds,
                'currency' => $payment->currency,
            ],
            'contractor' => [
                'id' => (int) $payment->contractor_id,
                'name' => $payment->contractor_name,
                'payout_ready' => $this->profileReady($profile),
            ],
            'release' => [
                'state' => $state,
                'can_release' => in_array($state, ['ready', 'pending'], true),
                'reason' => $reason,
                'stripe_transfer_id' => $state === 'released' ? $operation->stripe_object_id : null,
            ],
        ];
    }

    public function release(int $paymentId, int $adminId): array
    {
        $this->guard->assertTransfersEnabled();
        abort_if($paymentId < 1, 404, 'Payment not found.');
        abort_if($adminId < 1, 403, 'An administrator is required.');

        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new LogicException('Stripe release execution requires committed local state.');
            }
        }

        $operationId = $this->existingOperationId($paymentId);
        if ($operationId !== null) {
            return $this->execute($operationId);
        }

        $snapshot = DB::transaction(
            fn (): object => $this->lockedSnapshot($paymentId),
            3
        );
        $intent = $this->gateway->retrievePaymentIntent($snapshot->payment_intent_id)['data'];
        $charge = $this->gateway->retrieveCharge($snapshot->charge_id)['data'];
        $this->assertProviderSnapshot($snapshot, $intent, $charge);

        $operationId = $this->reserve($snapshot, $adminId);

        return $this->execute($operationId);
    }

    private function existingOperationId(int $paymentId): ?int
    {
        $id = DB::table('stripe_operation_requests as request')
            ->join('payment_installments as installment', 'installment.id', '=', 'request.payment_installment_id')
            ->where('installment.payment_id', $paymentId)
            ->where('request.operation_type', self::OPERATION)
            ->value('request.id');

        return $id === null ? null : (int) $id;
    }

    private function reserve(object $providerSnapshot, int $adminId): int
    {
        $credentialHash = hash('sha256', $this->guard->apiSecret());

        return DB::transaction(function () use ($providerSnapshot, $adminId, $credentialHash): int {
            $snapshot = $this->lockedSnapshot((int) $providerSnapshot->payment_id);
            abort_unless($this->sameSnapshot($providerSnapshot, $snapshot), 409,
                'Payment release facts changed during provider verification.');

            $operationKey = "payment:{$snapshot->payment_id}:contractor_release_v1";
            $existing = DB::table('stripe_operation_requests')
                ->where('payment_installment_id', $snapshot->installment_id)
                ->where('operation_type', self::OPERATION)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                abort_unless($existing->operation_key === $operationKey
                    && $existing->stripe_api_version === StripePaymentGateway::API_VERSION
                    && hash_equals($existing->credential_sha256, $credentialHash),
                    409, 'Existing contractor release requires reconciliation.');

                return (int) $existing->id;
            }

            $payload = json_encode($this->payloadFor($snapshot), JSON_THROW_ON_ERROR);
            $now = now();
            $operationId = (int) DB::table('stripe_operation_requests')->insertGetId([
                'payment_installment_id' => $snapshot->installment_id,
                'operation_type' => self::OPERATION,
                'operation_key' => $operationKey,
                'idempotency_key' => 'trustfix_transfer_' . bin2hex(random_bytes(16)),
                'stripe_api_version' => StripePaymentGateway::API_VERSION,
                'credential_sha256' => $credentialHash,
                'payload_encrypted' => Crypt::encryptString($payload),
                'payload_sha256' => hash('sha256', $payload),
                'actor_id' => $adminId,
                'retry_until' => $now->copy()->addHours(23),
                'created_at' => $now,
            ]);
            DB::table('stripe_operation_processing')->insert([
                'operation_request_id' => $operationId,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('payment_journal_entries')->insert([
                'payment_id' => $snapshot->payment_id,
                'entry_key' => "stripe-operation:{$operationId}:release_authorized",
                'entry_type' => 'contractor_release_authorized',
                'amount_cents' => $snapshot->proceeds_cents,
                'currency' => $snapshot->currency,
                'actor_id' => $adminId,
                'details' => json_encode([
                    'operation_request_id' => $operationId,
                    'installment_id' => $snapshot->installment_id,
                    'platform_fee_cents' => $snapshot->platform_fee_cents,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);

            return $operationId;
        }, 3);
    }

    private function execute(int $operationId): array
    {
        $credentialHash = hash('sha256', $this->guard->apiSecret());
        $claim = $this->claim($operationId, $credentialHash);
        if ($claim['status'] !== 'claimed') {
            return $claim;
        }

        $request = $claim['request'];
        $payload = $claim['payload'];
        try {
            $response = $this->gateway->createTransfer(
                $payload['amount_cents'],
                $payload['destination'],
                $payload['transfer_group'],
                $payload['source_transaction'],
                $payload['metadata'],
                $request->idempotency_key,
                $payload['description']
            );
        } catch (StripeApiException $exception) {
            $error = $exception->httpStatus === null
                ? $exception->reason
                : $exception->reason . '_' . $exception->httpStatus;

            return $this->finish($claim, null, $exception->requestId, $error);
        }

        return $this->finish(
            $claim,
            $response['data']['id'],
            $response['request_id'],
            null
        );
    }

    private function claim(int $operationId, string $credentialHash): array
    {
        return DB::transaction(function () use ($operationId, $credentialHash): array {
            $request = DB::table('stripe_operation_requests as request')
                ->join('payment_installments as installment', 'installment.id', '=', 'request.payment_installment_id')
                ->join('payment_agreements as agreement', 'agreement.id', '=', 'installment.payment_agreement_id')
                ->join('payments as payment', 'payment.id', '=', 'installment.payment_id')
                ->where('request.id', $operationId)
                ->where('request.operation_type', self::OPERATION)
                ->first([
                    'request.*', 'installment.payment_id',
                    'installment.id as installment_id', 'agreement.job_id',
                    'payment.amount_cents', 'payment.platform_fee_cents',
                    'payment.currency',
                ]);
            abort_unless($request, 404, 'Contractor release request not found.');

            DB::table('jobs')->where('id', $request->job_id)->lockForUpdate()->first(['id']);
            $state = DB::table('stripe_operation_processing')
                ->where('operation_request_id', $operationId)
                ->lockForUpdate()
                ->first();
            abort_unless($state, 409, 'Contractor release state requires reconciliation.');

            if ($state->status === 'completed') {
                if (!$this->stripeId($state->stripe_object_id, 'tr_')
                    || !hash_equals($request->credential_sha256, $credentialHash)) {
                    return $this->result('reconciliation_required', $request);
                }

                return $this->result('completed', $request, $state->stripe_object_id);
            }
            if ($state->status === 'reconciliation_required') {
                return $this->result('reconciliation_required', $request);
            }

            $now = now();
            if ($state->status === 'processing' && $state->lease_expires_at !== null
                && Carbon::parse($state->lease_expires_at)->greaterThan($now)) {
                return $this->result('busy', $request);
            }

            $hold = match (true) {
                !in_array($state->status, ['pending', 'processing'], true) => 'unexpected_operation_state',
                !hash_equals($request->credential_sha256, $credentialHash) => 'credentials_changed',
                $request->stripe_api_version !== StripePaymentGateway::API_VERSION => 'api_version_changed',
                Carbon::parse($request->retry_until)->lessThanOrEqualTo($now) => 'retry_window_closed',
                (int) $state->attempts >= self::MAX_ATTEMPTS => 'attempt_limit_reached',
                default => null,
            };
            $snapshot = $hold === null
                ? $this->lockedSnapshot((int) $request->payment_id)
                : null;
            $payload = $snapshot === null ? null : $this->savedPayload($request, $snapshot);
            if ($hold !== null || $payload === null) {
                $reason = $hold ?? 'invalid_saved_request';
                DB::table('stripe_operation_processing')
                    ->where('operation_request_id', $operationId)
                    ->update([
                        'status' => 'reconciliation_required',
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'last_error_code' => $reason,
                        'updated_at' => $now,
                    ]);
                $this->journal($request, 'contractor_transfer_held', 'held', null,
                    ['error_code' => $reason]);

                return $this->result('reconciliation_required', $request);
            }

            $attempt = (int) $state->attempts + 1;
            $token = bin2hex(random_bytes(32));
            $leaseUntil = $now->copy()->addSeconds(self::LEASE_SECONDS);
            DB::table('stripe_operation_processing')
                ->where('operation_request_id', $operationId)
                ->update([
                    'status' => 'processing',
                    'attempts' => $attempt,
                    'lease_token' => $token,
                    'lease_expires_at' => $leaseUntil,
                    'last_attempt_at' => $now,
                    'last_error_code' => null,
                    'updated_at' => $now,
                ]);
            $this->journal($request, 'contractor_transfer_started', "attempt:{$attempt}:started", null,
                ['attempt' => $attempt]);

            return [
                'status' => 'claimed',
                'request' => $request,
                'payload' => $payload,
                'token' => $token,
                'attempt' => $attempt,
            ];
        }, 3);
    }

    private function finish(array $claim, ?string $transferId, ?string $requestId, ?string $error): array
    {
        return DB::transaction(function () use ($claim, $transferId, $requestId, $error): array {
            $request = $claim['request'];
            DB::table('jobs')->where('id', $request->job_id)->lockForUpdate()->first(['id']);
            $state = DB::table('stripe_operation_processing')
                ->where('operation_request_id', $request->id)
                ->lockForUpdate()
                ->first();
            if (!$state || $state->status !== 'processing' || $state->lease_token !== $claim['token']) {
                return $this->result('busy', $request);
            }

            $now = now();
            $attempt = $claim['attempt'];
            $processing = DB::table('stripe_operation_processing')
                ->where('operation_request_id', $request->id);
            $details = ['attempt' => $attempt, 'stripe_request_id' => $requestId];
            if ($error !== null) {
                $retry = $error === 'transport_error'
                    && $attempt < self::MAX_ATTEMPTS
                    && Carbon::parse($request->retry_until)->greaterThan($now);
                $status = $retry ? 'pending' : 'reconciliation_required';
                $processing->update([
                    'status' => $status,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => $error,
                    'updated_at' => $now,
                ]);
                $this->journal($request, 'contractor_transfer_failed', "attempt:{$attempt}:failed",
                    $transferId, $details + ['error_code' => $error, 'status' => $status]);

                return $this->result($status, $request);
            }

            if (!$this->stripeId($transferId, 'tr_')) {
                $processing->update([
                    'status' => 'reconciliation_required',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => 'invalid_transfer_identifier',
                    'updated_at' => $now,
                ]);
                $this->journal(
                    $request,
                    'contractor_transfer_held',
                    "attempt:{$attempt}:invalid-transfer",
                    null,
                    $details + ['error_code' => 'invalid_transfer_identifier']
                );

                return $this->result('reconciliation_required', $request);
            }

            $processing->update([
                'status' => 'completed',
                'stripe_object_id' => $transferId,
                'completed_at' => $now,
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error_code' => null,
                'updated_at' => $now,
            ]);
            $this->journal($request, 'contractor_transfer_created', 'completed', $transferId, $details);

            return $this->result('completed', $request, $transferId);
        }, 3);
    }

    private function lockedSnapshot(int $paymentId): object
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Payment release facts require a database transaction.');
        }

        $seed = DB::table('payment_installments as installment')
            ->join('payment_agreements as agreement', 'agreement.id', '=', 'installment.payment_agreement_id')
            ->where('installment.payment_id', $paymentId)
            ->first(['agreement.job_id']);
        abort_unless($seed, 404, 'Reserved payment not found.');

        $job = DB::table('jobs')->where('id', $seed->job_id)
            ->lockForUpdate()->first(['id', 'status']);
        abort_unless($job, 404, 'Job not found.');

        $payment = DB::table('payment_installments as installment')
            ->join('payment_agreements as agreement', 'agreement.id', '=', 'installment.payment_agreement_id')
            ->join('payments as payment', 'payment.id', '=', 'installment.payment_id')
            ->where('payment.id', $paymentId)
            ->lockForUpdate()
            ->first([
                'payment.id as payment_id', 'payment.status as payment_status',
                'payment.amount_cents', 'payment.platform_fee_cents', 'payment.currency',
                'payment.paid_at', 'payment.stripe_payment_intent_id as payment_intent_id',
                'payment.contractor_id', 'installment.id as installment_id',
                'installment.transfer_group', 'agreement.id as agreement_id',
                'agreement.job_id',
            ]);
        abort_unless($payment, 404, 'Reserved payment not found.');
        abort_unless($job->status === 'completed', 409,
            'The job must be completed before contractor proceeds are released.');
        abort_unless($payment->payment_status === 'succeeded' && $payment->paid_at !== null
            && $this->stripeId($payment->payment_intent_id, 'pi_')
            && $payment->currency === 'usd'
            && (int) $payment->amount_cents > (int) $payment->platform_fee_cents,
            409, 'Verified succeeded payment funding is required.');

        $profile = DB::table('contractor_profiles')
            ->where('user_id', $payment->contractor_id)
            ->lockForUpdate()
            ->first([
                'stripe_account_id', 'stripe_details_submitted',
                'stripe_payouts_enabled', 'status',
            ]);
        abort_unless($this->profileReady($profile), 409,
            'The contractor payout account is not ready.');
        abort_if(DB::table('disputes')->where('job_id', $payment->job_id)
            ->whereIn('status', ['open', 'under_review'])->exists(), 409,
            'An open dispute blocks contractor release.');
        abort_if(DB::table('payment_journal_entries')->where('payment_id', $paymentId)
            ->where('entry_type', 'like', '%refund%')->exists(), 409,
            'A refund record blocks contractor release.');

        $funding = DB::table('payment_journal_entries')
            ->where('payment_id', $paymentId)
            ->where('entry_type', 'payment_succeeded')
            ->where('amount_cents', $payment->amount_cents)
            ->where('currency', $payment->currency)
            ->where('stripe_object_id', $payment->payment_intent_id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first(['details']);
        abort_unless($funding, 409, 'Verified payment funding is required.');

        try {
            $details = json_decode((string) $funding->details, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(409, 'The funding receipt requires reconciliation.');
        }
        $chargeId = is_array($details) ? ($details['stripe_charge_id'] ?? null) : null;
        abort_unless($this->stripeId($chargeId, 'ch_'), 409,
            'The funding charge requires reconciliation.');

        return (object) [
            'payment_id' => (int) $payment->payment_id,
            'installment_id' => (int) $payment->installment_id,
            'agreement_id' => (int) $payment->agreement_id,
            'job_id' => (int) $payment->job_id,
            'contractor_id' => (int) $payment->contractor_id,
            'amount_cents' => (int) $payment->amount_cents,
            'platform_fee_cents' => (int) $payment->platform_fee_cents,
            'proceeds_cents' => (int) $payment->amount_cents - (int) $payment->platform_fee_cents,
            'currency' => $payment->currency,
            'payment_intent_id' => $payment->payment_intent_id,
            'charge_id' => $chargeId,
            'transfer_group' => $payment->transfer_group,
            'destination' => $profile->stripe_account_id,
        ];
    }

    private function assertProviderSnapshot(object $snapshot, array $intent, array $charge): void
    {
        abort_unless(($intent['id'] ?? null) === $snapshot->payment_intent_id
            && ($intent['status'] ?? null) === 'succeeded'
            && ($intent['amount'] ?? null) === $snapshot->amount_cents
            && ($intent['amount_received'] ?? null) === $snapshot->amount_cents
            && ($intent['currency'] ?? null) === $snapshot->currency
            && ($intent['latest_charge'] ?? null) === $snapshot->charge_id
            && ($intent['transfer_group'] ?? null) === $snapshot->transfer_group
            && ($intent['transfer_data'] ?? null) === null
            && ($intent['application_fee_amount'] ?? null) === null,
            409, 'Stripe payment funding does not match the release facts.');
        abort_unless(($charge['id'] ?? null) === $snapshot->charge_id
            && ($charge['payment_intent'] ?? null) === $snapshot->payment_intent_id
            && ($charge['amount'] ?? null) === $snapshot->amount_cents
            && ($charge['currency'] ?? null) === $snapshot->currency
            && ($charge['paid'] ?? null) === true
            && ($charge['refunded'] ?? null) === false
            && ($charge['disputed'] ?? null) === false
            && ($charge['amount_refunded'] ?? null) === 0,
            409, 'Stripe charge funding does not match the release facts.');
    }

    private function savedPayload(object $request, object $snapshot): ?array
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

        return $payload === $this->payloadFor($snapshot)
            && preg_match('/\Atrustfix_transfer_[a-f0-9]{32}\z/', $request->idempotency_key) === 1
            ? $payload
            : null;
    }

    private function payloadFor(object $snapshot): array
    {
        return [
            'amount_cents' => $snapshot->proceeds_cents,
            'currency' => $snapshot->currency,
            'destination' => $snapshot->destination,
            'transfer_group' => $snapshot->transfer_group,
            'source_transaction' => $snapshot->charge_id,
            'description' => "TrustFix contractor proceeds for job #{$snapshot->job_id}",
            'metadata' => [
                'trustfix_job_id' => $snapshot->job_id,
                'trustfix_payment_id' => $snapshot->payment_id,
                'trustfix_installment_id' => $snapshot->installment_id,
                'trustfix_agreement_id' => $snapshot->agreement_id,
            ],
        ];
    }

    private function sameSnapshot(object $left, object $right): bool
    {
        return $this->payloadFor($left) === $this->payloadFor($right)
            && $left->payment_intent_id === $right->payment_intent_id
            && $left->amount_cents === $right->amount_cents
            && $left->platform_fee_cents === $right->platform_fee_cents;
    }

    private function profileReady(?object $profile): bool
    {
        return $profile !== null
            && $profile->status === 'approved'
            && (bool) $profile->stripe_details_submitted
            && (bool) $profile->stripe_payouts_enabled
            && $this->stripeId($profile->stripe_account_id, 'acct_');
    }

    private function stripeId(mixed $id, string $prefix): bool
    {
        return is_string($id) && strlen($id) <= 191
            && preg_match('/\A' . preg_quote($prefix, '/') . '[A-Za-z0-9_]+\z/', $id) === 1;
    }

    private function journal(object $request, string $type, string $suffix, ?string $stripeId, array $details): void
    {
        $amount = (int) $request->amount_cents - (int) $request->platform_fee_cents;
        DB::table('payment_journal_entries')->insert([
            'payment_id' => (int) $request->payment_id,
            'entry_key' => "stripe-operation:{$request->id}:{$suffix}",
            'entry_type' => $type,
            'amount_cents' => $amount,
            'currency' => $request->currency,
            'stripe_object_id' => $stripeId,
            'actor_id' => (int) $request->actor_id,
            'details' => json_encode($details + [
                'operation_request_id' => (int) $request->id,
                'platform_fee_cents' => (int) $request->platform_fee_cents,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function result(string $status, object $request, ?string $transferId = null): array
    {
        return [
            'status' => $status,
            'payment_id' => (int) $request->payment_id,
            'contractor_proceeds_cents' =>
                (int) $request->amount_cents - (int) $request->platform_fee_cents,
            'stripe_transfer_id' => $transferId,
        ];
    }
}