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
use RuntimeException;

final class ManualPaymentRefundService
{
    public const REVERSAL_OPERATION = 'reverse_transfer_v1';

    public const REFUND_OPERATION = 'create_full_refund_v1';

    private const LEASE_SECONDS = 90;

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly PaymentSafetyGuard $guard,
        private readonly StripePaymentGateway $gateway
    ) {}

    public function status(int $jobId): array
    {
        abort_if($jobId < 1, 404, 'Job not found.');
        $enabled = config('payments.refunds_enabled', false) === true;
        if ($enabled) {
            $this->guard->assertRefundsEnabled();
        } else {
            // Keep the read-only admin page available while refund writes are paused.
            $this->guard->apiSecret();
        }

        $payment = DB::table('payments as payment')
            ->join('payment_installments as installment', 'installment.payment_id', '=', 'payment.id')
            ->join('payment_agreements as agreement', 'agreement.id', '=', 'installment.payment_agreement_id')
            ->join('jobs as job', 'job.id', '=', 'agreement.job_id')
            ->where('agreement.job_id', $jobId)
            ->orderByDesc('payment.id')
            ->first([
                'payment.id', 'payment.status', 'payment.amount_cents',
                'payment.platform_fee_cents', 'payment.currency', 'payment.paid_at',
                'payment.refunded_at', 'payment.stripe_payment_intent_id',
                'installment.id as installment_id', 'agreement.job_id',
                'job.status as job_status',
            ]);

        if ($payment === null) {
            return [
                'state' => $enabled ? 'unavailable' : 'disabled',
                'can_refund' => false,
                'reason' => $enabled
                    ? 'No reserved payment exists for this job.'
                    : 'Manual refunds are disabled.',
                'full_refund_amount_cents' => null,
                'requires_transfer_reversal' => false,
                'stripe_refund_id' => null,
                'stripe_transfer_reversal_id' => null,
            ];
        }

        $funding = DB::table('payment_journal_entries')
            ->where('payment_id', $payment->id)
            ->where('entry_type', 'payment_succeeded')
            ->where('amount_cents', $payment->amount_cents)
            ->where('currency', $payment->currency)
            ->where('stripe_object_id', $payment->stripe_payment_intent_id)
            ->exists();
        $refundJournal = DB::table('payment_journal_entries')
            ->where('payment_id', $payment->id)
            ->where('entry_type', 'like', '%refund%')
            ->exists();
        $successJournal = DB::table('payment_journal_entries')
            ->where('payment_id', $payment->id)
            ->where('entry_type', 'customer_full_refund_succeeded')
            ->exists();
        $dispute = DB::table('disputes')
            ->where('job_id', $jobId)
            ->whereIn('status', ['open', 'under_review'])
            ->exists();
        $release = $this->operationForInstallment(
            (int) $payment->installment_id,
            ManualPaymentReleaseService::OPERATION
        );
        $reversal = $this->operationForInstallment(
            (int) $payment->installment_id,
            self::REVERSAL_OPERATION
        );
        $refund = $this->operationForInstallment(
            (int) $payment->installment_id,
            self::REFUND_OPERATION
        );
        $releaseComplete = $release === null || (
            $release->status === 'completed'
            && $this->stripeId($release->stripe_object_id, 'tr_')
        );
        $requiresReversal = $release !== null;

        $state = match (true) {
            $payment->status === 'refunded' && $payment->refunded_at !== null && $successJournal => 'refunded',
            $payment->status === 'refunded' || $payment->refunded_at !== null => 'reconciliation_required',
            $refund?->status === 'reconciliation_required'
                || $reversal?->status === 'reconciliation_required' => 'reconciliation_required',
            $refund?->status === 'completed' && $this->stripeId($refund->stripe_object_id, 're_') => 'awaiting_webhook',
            $refund?->status === 'processing' => 'processing',
            $reversal?->status === 'processing' => 'reversing_transfer',
            $refund?->status === 'pending' || $reversal?->status === 'pending'
                || $reversal?->status === 'completed' => 'pending',
            $refundJournal || $refund !== null || $reversal !== null => 'reconciliation_required',
            ! $enabled => 'disabled',
            $dispute => 'held',
            $payment->status !== 'succeeded' || $payment->paid_at === null || ! $funding => 'held',
            ! $releaseComplete => 'held',
            default => 'available',
        };

        $reason = match (true) {
            $state === 'refunded' => 'The full customer payment was refunded.',
            $state === 'awaiting_webhook' => 'Stripe accepted the full refund; webhook confirmation is pending.',
            $state === 'processing' => 'The full customer refund is processing.',
            $state === 'reversing_transfer' => 'The contractor transfer reversal is processing.',
            $state === 'pending' => 'The same idempotent refund workflow can be safely retried.',
            $state === 'reconciliation_required' => 'The refund workflow requires administrator review.',
            $state === 'disabled' => 'Manual refunds are disabled.',
            $dispute => 'An open dispute blocks a manual refund.',
            $payment->status !== 'succeeded' || $payment->paid_at === null || ! $funding => 'Verified succeeded payment funding is required.',
            ! $releaseComplete => 'The contractor release must be reconciled before refunding.',
            $requiresReversal => 'A full refund is available. The contractor transfer must be reversed first.',
            default => 'A full refund is available before contractor release.',
        };

        return [
            'state' => $state,
            'can_refund' => $enabled && in_array($state, ['available', 'pending'], true),
            'reason' => $reason,
            'full_refund_amount_cents' => (int) $payment->amount_cents,
            'platform_fee_refund_cents' => (int) $payment->platform_fee_cents,
            'contractor_proceeds_reversal_cents' => $requiresReversal
                ? (int) $payment->amount_cents - (int) $payment->platform_fee_cents
                : 0,
            'requires_transfer_reversal' => $requiresReversal,
            'stripe_refund_id' => $this->stripeId($refund?->stripe_object_id, 're_')
                ? $refund->stripe_object_id : null,
            'stripe_transfer_reversal_id' => $this->stripeId($reversal?->stripe_object_id, 'trr_')
                ? $reversal->stripe_object_id : null,
        ];
    }

    public function refund(int $paymentId, int $adminId): array
    {
        $this->guard->assertRefundsEnabled();
        abort_if($paymentId < 1, 404, 'Payment not found.');
        abort_if($adminId < 1, 403, 'An administrator is required.');

        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new LogicException('Stripe refund execution requires committed local state.');
            }
        }

        if ($this->existingRefundOperationId($paymentId) !== null) {
            return $this->executeWorkflow($paymentId);
        }

        $snapshot = DB::transaction(
            fn (): object => $this->lockedSnapshot($paymentId, false),
            3
        );
        $intent = $this->gateway->retrievePaymentIntent($snapshot->payment_intent_id)['data'];
        $charge = $this->gateway->retrieveCharge($snapshot->charge_id)['data'];
        $transfer = $snapshot->transfer_id === null
            ? null
            : $this->gateway->retrieveTransfer($snapshot->transfer_id)['data'];
        $this->assertProviderSnapshot($snapshot, $intent, $charge, $transfer);

        $this->reserve($snapshot, $adminId);

        return $this->executeWorkflow($paymentId);
    }

    /**
     * Runs only inside StripeEventProcessor's database transaction. It returns
     * null for unrelated events so the funding and legacy handlers may run.
     */
    public function reconcileEvent(
        #[\SensitiveParameter] array $event,
        int $receiptId
    ): ?string {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Stripe refund events require a database transaction.');
        }

        return match ($event['type'] ?? null) {
            'refund.created', 'refund.updated', 'refund.failed' => $this->reconcileRefundEvent($event, $receiptId),
            'transfer.reversed' => $this->reconcileTransferReversedEvent($event, $receiptId),
            'charge.refunded' => $this->reconcileChargeRefundedEvent($event, $receiptId),
            default => null,
        };
    }

    private function reserve(object $providerSnapshot, int $adminId): void
    {
        $credentialHash = hash('sha256', $this->guard->apiSecret());

        DB::transaction(function () use ($providerSnapshot, $adminId, $credentialHash): void {
            $snapshot = $this->lockedSnapshot((int) $providerSnapshot->payment_id, false);
            abort_unless($this->sameSnapshot($providerSnapshot, $snapshot), 409,
                'Payment refund facts changed during provider verification.');

            $now = now();
            $reversalId = null;
            if ($snapshot->transfer_id !== null) {
                $reversalId = $this->insertOperation(
                    $snapshot,
                    self::REVERSAL_OPERATION,
                    "payment:{$snapshot->payment_id}:contractor_transfer_reversal_v1",
                    'trustfix_reversal_',
                    $adminId,
                    $credentialHash,
                    $now
                );
            }
            $refundId = $this->insertOperation(
                $snapshot,
                self::REFUND_OPERATION,
                "payment:{$snapshot->payment_id}:full_refund_v1",
                'trustfix_refund_',
                $adminId,
                $credentialHash,
                $now
            );

            DB::table('payment_journal_entries')->insert([
                'payment_id' => $snapshot->payment_id,
                'entry_key' => "payment:{$snapshot->payment_id}:full_refund_authorized_v1",
                'entry_type' => 'customer_full_refund_authorized',
                'amount_cents' => $snapshot->amount_cents,
                'currency' => $snapshot->currency,
                'actor_id' => $adminId,
                'details' => json_encode([
                    'full_refund' => true,
                    'refund_operation_request_id' => $refundId,
                    'reversal_operation_request_id' => $reversalId,
                    'installment_id' => $snapshot->installment_id,
                    'platform_fee_cents' => $snapshot->platform_fee_cents,
                    'contractor_proceeds_cents' => $snapshot->proceeds_cents,
                    'stripe_transfer_id' => $snapshot->transfer_id,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        }, 3);
    }

    private function insertOperation(
        object $snapshot,
        string $type,
        string $operationKey,
        string $keyPrefix,
        int $adminId,
        string $credentialHash,
        Carbon $now
    ): int {
        $payload = json_encode($this->payloadFor($type, $snapshot), JSON_THROW_ON_ERROR);
        $operationId = (int) DB::table('stripe_operation_requests')->insertGetId([
            'payment_installment_id' => $snapshot->installment_id,
            'operation_type' => $type,
            'operation_key' => $operationKey,
            'idempotency_key' => $keyPrefix.bin2hex(random_bytes(16)),
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

        return $operationId;
    }

    private function executeWorkflow(int $paymentId): array
    {
        $refundId = $this->existingRefundOperationId($paymentId);
        abort_unless($refundId !== null, 409, 'The full refund request requires reconciliation.');

        $reversalId = $this->operationId($paymentId, self::REVERSAL_OPERATION);
        $reversalObjectId = null;
        if ($reversalId !== null) {
            $reversal = $this->executeOperation($reversalId, self::REVERSAL_OPERATION);
            if ($reversal['status'] !== 'completed') {
                return $this->workflowResult(
                    $reversal['status'],
                    $paymentId,
                    'transfer_reversal',
                    $reversal['stripe_object_id']
                );
            }
            $reversalObjectId = $reversal['stripe_object_id'];
        }

        $refund = $this->executeOperation($refundId, self::REFUND_OPERATION);
        if ($refund['status'] !== 'completed') {
            return $this->workflowResult(
                $refund['status'],
                $paymentId,
                'customer_refund',
                $reversalObjectId,
                $refund['stripe_object_id']
            );
        }

        $payment = DB::table('payments')->where('id', $paymentId)
            ->first(['status', 'refunded_at']);
        $confirmed = $payment?->status === 'refunded'
            && $payment->refunded_at !== null
            && DB::table('payment_journal_entries')
                ->where('payment_id', $paymentId)
                ->where('entry_type', 'customer_full_refund_succeeded')
                ->exists();

        return $this->workflowResult(
            $confirmed ? 'refunded' : 'awaiting_webhook',
            $paymentId,
            'customer_refund',
            $reversalObjectId,
            $refund['stripe_object_id']
        );
    }

    private function executeOperation(int $operationId, string $type): array
    {
        $credentialHash = hash('sha256', $this->guard->apiSecret());
        $claim = $this->claim($operationId, $type, $credentialHash);
        if ($claim['status'] !== 'claimed') {
            return $claim;
        }

        $request = $claim['request'];
        $payload = $claim['payload'];
        try {
            if ($type === self::REVERSAL_OPERATION) {
                $response = $this->gateway->createTransferReversal(
                    $payload['amount_cents'],
                    $payload['transfer_id'],
                    $payload['metadata'],
                    $request->idempotency_key
                );
            } else {
                $response = $this->gateway->createFullRefund(
                    $payload['amount_cents'],
                    $payload['payment_intent_id'],
                    $payload['charge_id'],
                    $payload['metadata'],
                    $request->idempotency_key
                );
            }
        } catch (StripeApiException $exception) {
            $error = $exception->httpStatus === null
                ? $exception->reason
                : $exception->reason.'_'.$exception->httpStatus;

            return $this->finish($claim, null, $exception->requestId, $error, null);
        }

        return $this->finish(
            $claim,
            $response['data']['id'],
            $response['request_id'],
            null,
            $type === self::REFUND_OPERATION ? $response['data']['status'] : null
        );
    }

    private function claim(int $operationId, string $type, string $credentialHash): array
    {
        return DB::transaction(function () use ($operationId, $type, $credentialHash): array {
            $request = DB::table('stripe_operation_requests as request')
                ->join('payment_installments as installment', 'installment.id', '=', 'request.payment_installment_id')
                ->join('payment_agreements as agreement', 'agreement.id', '=', 'installment.payment_agreement_id')
                ->join('payments as payment', 'payment.id', '=', 'installment.payment_id')
                ->where('request.id', $operationId)
                ->where('request.operation_type', $type)
                ->first([
                    'request.*', 'installment.payment_id',
                    'installment.id as installment_id', 'agreement.job_id',
                    'payment.amount_cents', 'payment.platform_fee_cents',
                    'payment.currency',
                ]);
            abort_unless($request, 404, 'Refund operation not found.');

            DB::table('jobs')->where('id', $request->job_id)->lockForUpdate()->first(['id']);
            $state = DB::table('stripe_operation_processing')
                ->where('operation_request_id', $operationId)
                ->lockForUpdate()
                ->first();
            abort_unless($state, 409, 'Refund operation state requires reconciliation.');

            $prefix = $type === self::REVERSAL_OPERATION ? 'trr_' : 're_';
            if ($state->status === 'completed') {
                if (! $this->stripeId($state->stripe_object_id, $prefix)
                    || ! hash_equals($request->credential_sha256, $credentialHash)) {
                    return $this->operationResult('reconciliation_required', $request);
                }

                return $this->operationResult('completed', $request, $state->stripe_object_id);
            }
            if ($state->status === 'reconciliation_required') {
                return $this->operationResult('reconciliation_required', $request, $state->stripe_object_id);
            }

            $now = now();
            if ($state->status === 'processing' && $state->lease_expires_at !== null
                && Carbon::parse($state->lease_expires_at)->greaterThan($now)) {
                return $this->operationResult('busy', $request);
            }

            $hold = match (true) {
                ! in_array($state->status, ['pending', 'processing'], true) => 'unexpected_operation_state',
                ! hash_equals($request->credential_sha256, $credentialHash) => 'credentials_changed',
                $request->stripe_api_version !== StripePaymentGateway::API_VERSION => 'api_version_changed',
                Carbon::parse($request->retry_until)->lessThanOrEqualTo($now) => 'retry_window_closed',
                (int) $state->attempts >= self::MAX_ATTEMPTS => 'attempt_limit_reached',
                default => null,
            };
            $snapshot = $hold === null
                ? $this->lockedSnapshot((int) $request->payment_id, true)
                : null;
            if ($hold === null && $type === self::REFUND_OPERATION
                && $snapshot?->transfer_id !== null
                && ! $this->completedReversalExists((int) $request->installment_id)) {
                $hold = 'transfer_reversal_not_completed';
            }
            $payload = $snapshot === null
                ? null
                : $this->savedPayload($request, $type, $snapshot);
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
                $this->journalOperation(
                    $request,
                    $type,
                    $this->eventType($type, 'held'),
                    'held',
                    null,
                    ['error_code' => $reason]
                );

                return $this->operationResult('reconciliation_required', $request);
            }

            $attempt = (int) $state->attempts + 1;
            $token = bin2hex(random_bytes(32));
            DB::table('stripe_operation_processing')
                ->where('operation_request_id', $operationId)
                ->update([
                    'status' => 'processing',
                    'attempts' => $attempt,
                    'lease_token' => $token,
                    'lease_expires_at' => $now->copy()->addSeconds(self::LEASE_SECONDS),
                    'last_attempt_at' => $now,
                    'last_error_code' => null,
                    'updated_at' => $now,
                ]);
            $this->journalOperation(
                $request,
                $type,
                $this->eventType($type, 'started'),
                "attempt:{$attempt}:started",
                null,
                ['attempt' => $attempt]
            );

            return [
                'status' => 'claimed',
                'request' => $request,
                'payload' => $payload,
                'token' => $token,
                'attempt' => $attempt,
                'type' => $type,
            ];
        }, 3);
    }

    private function finish(
        array $claim,
        ?string $objectId,
        ?string $requestId,
        ?string $error,
        ?string $providerStatus
    ): array {
        return DB::transaction(function () use (
            $claim, $objectId, $requestId, $error, $providerStatus
        ): array {
            $request = $claim['request'];
            $type = $claim['type'];
            DB::table('jobs')->where('id', $request->job_id)->lockForUpdate()->first(['id']);
            $state = DB::table('stripe_operation_processing')
                ->where('operation_request_id', $request->id)
                ->lockForUpdate()
                ->first();
            if (! $state || $state->status !== 'processing'
                || $state->lease_token !== $claim['token']) {
                return $this->operationResult('busy', $request);
            }

            $now = now();
            $attempt = $claim['attempt'];
            $details = [
                'attempt' => $attempt,
                'stripe_request_id' => $requestId,
                'provider_status' => $providerStatus,
            ];
            if ($error !== null) {
                $retry = $error === 'transport_error'
                    && $attempt < self::MAX_ATTEMPTS
                    && Carbon::parse($request->retry_until)->greaterThan($now);
                $status = $retry ? 'pending' : 'reconciliation_required';
                DB::table('stripe_operation_processing')
                    ->where('operation_request_id', $request->id)
                    ->update([
                        'status' => $status,
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'last_error_code' => $error,
                        'updated_at' => $now,
                    ]);
                $this->journalOperation(
                    $request,
                    $type,
                    $this->eventType($type, 'failed'),
                    "attempt:{$attempt}:failed",
                    $objectId,
                    $details + ['error_code' => $error, 'status' => $status]
                );

                return $this->operationResult($status, $request, $objectId);
            }

            $prefix = $type === self::REVERSAL_OPERATION ? 'trr_' : 're_';
            if (! $this->stripeId($objectId, $prefix)) {
                DB::table('stripe_operation_processing')
                    ->where('operation_request_id', $request->id)
                    ->update([
                        'status' => 'reconciliation_required',
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'last_error_code' => 'invalid_stripe_identifier',
                        'updated_at' => $now,
                    ]);
                $this->journalOperation(
                    $request,
                    $type,
                    $this->eventType($type, 'held'),
                    "attempt:{$attempt}:invalid-object",
                    null,
                    $details + ['error_code' => 'invalid_stripe_identifier']
                );

                return $this->operationResult('reconciliation_required', $request);
            }

            DB::table('stripe_operation_processing')
                ->where('operation_request_id', $request->id)
                ->update([
                    'status' => 'completed',
                    'stripe_object_id' => $objectId,
                    'completed_at' => $now,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => null,
                    'updated_at' => $now,
                ]);
            $this->journalOperation(
                $request,
                $type,
                $this->eventType($type, 'created'),
                'completed',
                $objectId,
                $details
            );

            return $this->operationResult('completed', $request, $objectId);
        }, 3);
    }

    private function lockedSnapshot(int $paymentId, bool $allowAuthorized): object
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Payment refund facts require a database transaction.');
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
                'payment.paid_at', 'payment.refunded_at',
                'payment.stripe_payment_intent_id as payment_intent_id',
                'installment.id as installment_id', 'installment.transfer_group',
                'agreement.id as agreement_id', 'agreement.job_id',
            ]);
        abort_unless($payment, 404, 'Reserved payment not found.');
        abort_unless($payment->payment_status === 'succeeded'
            && $payment->paid_at !== null && $payment->refunded_at === null
            && $this->stripeId($payment->payment_intent_id, 'pi_')
            && $payment->currency === 'usd'
            && (int) $payment->amount_cents >= 50
            && (int) $payment->amount_cents > (int) $payment->platform_fee_cents,
            409, 'Verified succeeded payment funding is required.');
        abort_if(DB::table('disputes')->where('job_id', $payment->job_id)
            ->whereIn('status', ['open', 'under_review'])->exists(), 409,
            'An open dispute blocks a manual refund.');

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
            $fundingDetails = json_decode(
                (string) $funding->details,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            abort(409, 'The funding receipt requires reconciliation.');
        }
        $chargeId = is_array($fundingDetails)
            ? ($fundingDetails['stripe_charge_id'] ?? null) : null;
        abort_unless($this->stripeId($chargeId, 'ch_'), 409,
            'The funding charge requires reconciliation.');

        $release = DB::table('stripe_operation_requests as request')
            ->join('stripe_operation_processing as state', 'state.operation_request_id', '=', 'request.id')
            ->where('request.payment_installment_id', $payment->installment_id)
            ->where('request.operation_type', ManualPaymentReleaseService::OPERATION)
            ->lockForUpdate()
            ->first([
                'request.id', 'request.operation_key', 'request.stripe_api_version',
                'request.payload_encrypted', 'request.payload_sha256',
                'state.status', 'state.stripe_object_id',
            ]);
        $transferId = null;
        $destination = null;
        if ($release !== null) {
            abort_unless($release->status === 'completed'
                && $this->stripeId($release->stripe_object_id, 'tr_')
                && $release->operation_key === "payment:{$payment->payment_id}:contractor_release_v1"
                && $release->stripe_api_version === StripePaymentGateway::API_VERSION,
                409, 'The contractor release must be reconciled before refunding.');
            try {
                $releasePayload = $this->decryptPayload($release);
            } catch (RuntimeException) {
                abort(409, 'The contractor release facts require reconciliation.');
            }
            $expected = [
                'amount_cents' => (int) $payment->amount_cents - (int) $payment->platform_fee_cents,
                'currency' => $payment->currency,
                'transfer_group' => $payment->transfer_group,
                'source_transaction' => $chargeId,
            ];
            foreach ($expected as $field => $value) {
                abort_unless(($releasePayload[$field] ?? null) === $value, 409,
                    'The contractor release facts require reconciliation.');
            }
            abort_unless($this->stripeId($releasePayload['destination'] ?? null, 'acct_'), 409,
                'The contractor release destination requires reconciliation.');
            $transferId = $release->stripe_object_id;
            $destination = $releasePayload['destination'];
        }

        $authorizationKey = "payment:{$paymentId}:full_refund_authorized_v1";
        $authorized = DB::table('payment_journal_entries')
            ->where('payment_id', $paymentId)
            ->where('entry_key', $authorizationKey)
            ->lockForUpdate()
            ->exists();
        $refundHistory = DB::table('payment_journal_entries')
            ->where('payment_id', $paymentId)
            ->where('entry_type', 'like', '%refund%')
            ->lockForUpdate()
            ->exists();
        $refundOperations = DB::table('stripe_operation_requests')
            ->where('payment_installment_id', $payment->installment_id)
            ->whereIn('operation_type', [self::REVERSAL_OPERATION, self::REFUND_OPERATION])
            ->lockForUpdate()
            ->get(['id'])
            ->count();
        if ($allowAuthorized) {
            abort_unless($authorized && $refundOperations >= 1, 409,
                'The full refund authorization requires reconciliation.');
        } else {
            abort_if($authorized || $refundHistory || $refundOperations !== 0, 409,
                'An existing refund record requires reconciliation.');
        }

        return (object) [
            'payment_id' => (int) $payment->payment_id,
            'installment_id' => (int) $payment->installment_id,
            'agreement_id' => (int) $payment->agreement_id,
            'job_id' => (int) $payment->job_id,
            'amount_cents' => (int) $payment->amount_cents,
            'platform_fee_cents' => (int) $payment->platform_fee_cents,
            'proceeds_cents' => (int) $payment->amount_cents - (int) $payment->platform_fee_cents,
            'currency' => $payment->currency,
            'payment_intent_id' => $payment->payment_intent_id,
            'charge_id' => $chargeId,
            'transfer_group' => $payment->transfer_group,
            'transfer_id' => $transferId,
            'destination' => $destination,
        ];
    }

    private function assertProviderSnapshot(
        object $snapshot,
        array $intent,
        array $charge,
        ?array $transfer
    ): void {
        abort_unless(($intent['id'] ?? null) === $snapshot->payment_intent_id
            && ($intent['status'] ?? null) === 'succeeded'
            && ($intent['amount'] ?? null) === $snapshot->amount_cents
            && ($intent['amount_received'] ?? null) === $snapshot->amount_cents
            && ($intent['currency'] ?? null) === $snapshot->currency
            && ($intent['latest_charge'] ?? null) === $snapshot->charge_id
            && ($intent['transfer_group'] ?? null) === $snapshot->transfer_group
            && ($intent['transfer_data'] ?? null) === null
            && ($intent['application_fee_amount'] ?? null) === null
            && ($intent['on_behalf_of'] ?? null) === null,
            409, 'Stripe payment funding does not match the refund facts.');
        abort_unless(($charge['id'] ?? null) === $snapshot->charge_id
            && ($charge['payment_intent'] ?? null) === $snapshot->payment_intent_id
            && ($charge['amount'] ?? null) === $snapshot->amount_cents
            && ($charge['currency'] ?? null) === $snapshot->currency
            && ($charge['paid'] ?? null) === true
            && ($charge['refunded'] ?? null) === false
            && ($charge['disputed'] ?? null) === false
            && ($charge['amount_refunded'] ?? null) === 0,
            409, 'Stripe charge funding does not match the refund facts.');

        if ($snapshot->transfer_id === null) {
            abort_unless($transfer === null, 409,
                'An unexpected transfer requires reconciliation.');

            return;
        }

        abort_unless(is_array($transfer)
            && ($transfer['id'] ?? null) === $snapshot->transfer_id
            && ($transfer['amount'] ?? null) === $snapshot->proceeds_cents
            && ($transfer['currency'] ?? null) === $snapshot->currency
            && ($transfer['destination'] ?? null) === $snapshot->destination
            && ($transfer['source_transaction'] ?? null) === $snapshot->charge_id
            && ($transfer['transfer_group'] ?? null) === $snapshot->transfer_group
            && ($transfer['reversed'] ?? null) === false
            && ($transfer['amount_reversed'] ?? null) === 0,
            409, 'Stripe transfer funding does not match the refund facts.');
        $this->assertMetadata($transfer['metadata'] ?? null, $snapshot);
    }

    private function reconcileRefundEvent(array $event, int $receiptId): ?string
    {
        $refund = $event['data']['object'] ?? null;
        if (! is_array($refund) || ($refund['object'] ?? null) !== 'refund') {
            throw new RuntimeException('Invalid Stripe refund snapshot.');
        }
        $refundId = $refund['id'] ?? null;
        $intentId = $refund['payment_intent'] ?? null;
        if (! $this->stripeId($refundId, 're_') || ! $this->stripeId($intentId, 'pi_')) {
            throw new RuntimeException('Invalid Stripe refund identifiers.');
        }

        $terms = $this->termsForIntent($intentId);
        if ($terms === null) {
            return ($refund['metadata']['trustfix_payment_model'] ?? null)
                === StripePaymentGateway::PAYMENT_MODEL
                ? throw new RuntimeException('Stripe refund binding is pending.')
                : null;
        }
        DB::table('jobs')->where('id', $terms->job_id)->lockForUpdate()->first(['id']);
        $payment = DB::table('payments')->where('id', $terms->payment_id)
            ->lockForUpdate()->first();
        $operation = DB::table('stripe_operation_requests as request')
            ->join('stripe_operation_processing as state', 'state.operation_request_id', '=', 'request.id')
            ->where('request.payment_installment_id', $terms->installment_id)
            ->where('request.operation_type', self::REFUND_OPERATION)
            ->lockForUpdate()
            ->first([
                'request.id', 'request.stripe_api_version',
                'state.status', 'state.stripe_object_id',
            ]);
        if (! $payment || ! $operation || $operation->status !== 'completed'
            || $operation->stripe_object_id !== $refundId) {
            throw new RuntimeException('Stripe refund operation binding is pending.');
        }

        $chargeId = $this->fundingChargeId((int) $payment->id, $intentId);
        $status = $refund['status'] ?? null;
        if (($event['account'] ?? null) !== null || ($event['context'] ?? null) !== null
            || ($event['api_version'] ?? null) !== $operation->stripe_api_version
            || ($refund['amount'] ?? null) !== (int) $payment->amount_cents
            || ($refund['currency'] ?? null) !== $payment->currency
            || ($refund['charge'] ?? null) !== $chargeId
            || ($refund['reason'] ?? null) !== 'requested_by_customer'
            || ! in_array($status, ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true)
            || (($event['type'] ?? null) === 'refund.failed' && $status !== 'failed')
            || ($refund['source_transfer_reversal'] ?? null) !== null
            || ($refund['transfer_reversal'] ?? null) !== null) {
            throw new RuntimeException('Stripe refund snapshot does not match the full refund request.');
        }
        $this->assertMetadata($refund['metadata'] ?? null, $terms);

        $successKey = "payment:{$payment->id}:full_refund_succeeded";
        $existingSuccess = DB::table('payment_journal_entries')
            ->where('entry_key', $successKey)->lockForUpdate()->first();
        if ($existingSuccess !== null) {
            if ($existingSuccess->entry_type !== 'customer_full_refund_succeeded'
                || (int) $existingSuccess->amount_cents !== (int) $payment->amount_cents
                || $existingSuccess->currency !== $payment->currency
                || $existingSuccess->stripe_object_id !== $refundId
                || $existingSuccess->stripe_event_receipt_id === null
                || $payment->status !== 'refunded'
                || $payment->refunded_at === null) {
                throw new RuntimeException('Conflicting full refund journal entry.');
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                throw new RuntimeException('Stripe refund failed after local success confirmation.');
            }

            // Stripe does not guarantee event order. An older pending snapshot
            // cannot reopen an already confirmed full refund.
            return 'ignored';
        }

        if (in_array($status, ['failed', 'canceled'], true)) {
            $key = "stripe-event:{$receiptId}:customer_full_refund_failed";
            if (DB::table('payment_journal_entries')->where('entry_key', $key)->exists()) {
                return 'ignored';
            }
            DB::table('payment_journal_entries')->insert([
                'payment_id' => $payment->id,
                'entry_key' => $key,
                'entry_type' => 'customer_full_refund_failed',
                'amount_cents' => (int) $payment->amount_cents,
                'currency' => $payment->currency,
                'stripe_object_id' => $refundId,
                'stripe_event_receipt_id' => $receiptId,
                'details' => json_encode([
                    'operation_request_id' => (int) $operation->id,
                    'provider_status' => $status,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            DB::table('stripe_operation_processing')
                ->where('operation_request_id', $operation->id)
                ->update([
                    'status' => 'reconciliation_required',
                    'last_error_code' => 'refund_'.$status,
                    'updated_at' => now(),
                ]);

            return 'processed';
        }

        if ($status !== 'succeeded') {
            $key = "stripe-event:{$receiptId}:customer_full_refund_pending";
            if (DB::table('payment_journal_entries')->where('entry_key', $key)->exists()) {
                return 'ignored';
            }
            DB::table('payment_journal_entries')->insert([
                'payment_id' => $payment->id,
                'entry_key' => $key,
                'entry_type' => 'customer_full_refund_pending',
                'amount_cents' => (int) $payment->amount_cents,
                'currency' => $payment->currency,
                'stripe_object_id' => $refundId,
                'stripe_event_receipt_id' => $receiptId,
                'details' => json_encode([
                    'operation_request_id' => (int) $operation->id,
                    'provider_status' => $status,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return 'processed';
        }

        if ($payment->status !== 'succeeded' || $payment->paid_at === null
            || $payment->refunded_at !== null) {
            throw new RuntimeException('Stripe refund conflicts with the local payment state.');
        }

        $now = now();
        DB::table('payment_journal_entries')->insert([
            'payment_id' => $payment->id,
            'entry_key' => $successKey,
            'entry_type' => 'customer_full_refund_succeeded',
            'amount_cents' => (int) $payment->amount_cents,
            'currency' => $payment->currency,
            'stripe_object_id' => $refundId,
            'stripe_event_receipt_id' => $receiptId,
            'details' => json_encode([
                'operation_request_id' => (int) $operation->id,
                'stripe_charge_id' => $chargeId,
                'platform_fee_cents' => (int) $payment->platform_fee_cents,
                'full_refund' => true,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);
        DB::table('payments')->where('id', $payment->id)->update([
            'status' => 'refunded',
            'refunded_at' => $now,
            'updated_at' => $now,
        ]);

        return 'processed';
    }

    private function reconcileTransferReversedEvent(array $event, int $receiptId): ?string
    {
        $transfer = $event['data']['object'] ?? null;
        if (! is_array($transfer) || ($transfer['object'] ?? null) !== 'transfer') {
            throw new RuntimeException('Invalid Stripe transfer reversal snapshot.');
        }
        $transferId = $transfer['id'] ?? null;
        if (! $this->stripeId($transferId, 'tr_')) {
            throw new RuntimeException('Invalid Stripe transfer identifier.');
        }

        $release = DB::table('stripe_operation_requests as request')
            ->join('stripe_operation_processing as state', 'state.operation_request_id', '=', 'request.id')
            ->join('payment_installments as installment', 'installment.id', '=', 'request.payment_installment_id')
            ->join('payment_agreements as agreement', 'agreement.id', '=', 'installment.payment_agreement_id')
            ->join('payments as payment', 'payment.id', '=', 'installment.payment_id')
            ->where('request.operation_type', ManualPaymentReleaseService::OPERATION)
            ->where('state.stripe_object_id', $transferId)
            ->first([
                'request.payment_installment_id', 'request.payload_encrypted',
                'request.payload_sha256', 'state.status', 'agreement.job_id',
                'agreement.id as agreement_id', 'payment.id as payment_id',
                'payment.amount_cents', 'payment.platform_fee_cents', 'payment.currency',
                'payment.stripe_payment_intent_id', 'installment.id as installment_id',
                'installment.transfer_group',
            ]);
        if ($release === null) {
            return null;
        }
        if ($release->status !== 'completed') {
            throw new RuntimeException('Stripe contractor release binding is invalid.');
        }
        DB::table('jobs')->where('id', $release->job_id)->lockForUpdate()->first(['id']);
        DB::table('payments')->where('id', $release->payment_id)->lockForUpdate()->first(['id']);
        $reversal = DB::table('stripe_operation_requests as request')
            ->join('stripe_operation_processing as state', 'state.operation_request_id', '=', 'request.id')
            ->where('request.payment_installment_id', $release->payment_installment_id)
            ->where('request.operation_type', self::REVERSAL_OPERATION)
            ->lockForUpdate()
            ->first([
                'request.id', 'request.stripe_api_version',
                'state.status', 'state.stripe_object_id',
            ]);
        if (! $reversal || $reversal->status !== 'completed'
            || ! $this->stripeId($reversal->stripe_object_id, 'trr_')) {
            throw new RuntimeException('Stripe transfer reversal binding is pending.');
        }
        $chargeId = $this->fundingChargeId(
            (int) $release->payment_id,
            $release->stripe_payment_intent_id
        );
        $releasePayload = $this->decryptPayload($release);
        $destination = $releasePayload['destination'] ?? null;
        $proceeds = (int) $release->amount_cents - (int) $release->platform_fee_cents;
        if (($event['account'] ?? null) !== null || ($event['context'] ?? null) !== null
            || ($event['api_version'] ?? null) !== $reversal->stripe_api_version
            || ($transfer['livemode'] ?? null) !== false
            || ($transfer['amount'] ?? null) !== $proceeds
            || ($transfer['currency'] ?? null) !== $release->currency
            || ! $this->stripeId($destination, 'acct_')
            || ($transfer['destination'] ?? null) !== $destination
            || ($transfer['source_transaction'] ?? null) !== $chargeId
            || ($transfer['transfer_group'] ?? null) !== $release->transfer_group
            || ($transfer['reversed'] ?? null) !== true
            || ($transfer['amount_reversed'] ?? null) !== $proceeds) {
            throw new RuntimeException('Stripe transfer reversal does not match the refund request.');
        }
        $this->assertMetadata($transfer['metadata'] ?? null, $release);

        $key = "payment:{$release->payment_id}:contractor_transfer_reversed";
        $previous = DB::table('payment_journal_entries')
            ->where('entry_key', $key)->lockForUpdate()->first();
        if ($previous !== null) {
            if ($previous->entry_type !== 'contractor_transfer_reversed'
                || (int) $previous->amount_cents !== $proceeds
                || $previous->currency !== $release->currency
                || $previous->stripe_object_id !== $reversal->stripe_object_id
                || $previous->stripe_event_receipt_id === null) {
                throw new RuntimeException('Conflicting transfer reversal journal entry.');
            }

            return 'ignored';
        }
        DB::table('payment_journal_entries')->insert([
            'payment_id' => $release->payment_id,
            'entry_key' => $key,
            'entry_type' => 'contractor_transfer_reversed',
            'amount_cents' => $proceeds,
            'currency' => $release->currency,
            'stripe_object_id' => $reversal->stripe_object_id,
            'stripe_event_receipt_id' => $receiptId,
            'details' => json_encode([
                'operation_request_id' => (int) $reversal->id,
                'stripe_transfer_id' => $transferId,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return 'processed';
    }

    private function reconcileChargeRefundedEvent(array $event, int $receiptId): ?string
    {
        $charge = $event['data']['object'] ?? null;
        if (! is_array($charge) || ($charge['object'] ?? null) !== 'charge') {
            throw new RuntimeException('Invalid Stripe refunded charge snapshot.');
        }
        $chargeId = $charge['id'] ?? null;
        $intentId = $charge['payment_intent'] ?? null;
        if (! $this->stripeId($chargeId, 'ch_') || ! $this->stripeId($intentId, 'pi_')) {
            throw new RuntimeException('Invalid Stripe refunded charge identifiers.');
        }
        $terms = $this->termsForIntent($intentId);
        if ($terms === null) {
            return null;
        }
        DB::table('jobs')->where('id', $terms->job_id)->lockForUpdate()->first(['id']);
        $payment = DB::table('payments')->where('id', $terms->payment_id)
            ->lockForUpdate()->first();
        $fundingCharge = $this->fundingChargeId((int) $terms->payment_id, $intentId);
        if ($fundingCharge !== $chargeId) {
            throw new RuntimeException('Stripe refunded charge does not match payment funding.');
        }
        $operation = DB::table('stripe_operation_requests as request')
            ->join('stripe_operation_processing as state', 'state.operation_request_id', '=', 'request.id')
            ->where('request.payment_installment_id', $terms->installment_id)
            ->where('request.operation_type', self::REFUND_OPERATION)
            ->lockForUpdate()
            ->first([
                'request.id', 'request.stripe_api_version',
                'state.status', 'state.stripe_object_id',
            ]);
        $succeeded = DB::table('payment_journal_entries')
            ->where('payment_id', $terms->payment_id)
            ->where('entry_type', 'customer_full_refund_succeeded')
            ->lockForUpdate()
            ->first(['stripe_object_id']);
        if (! $payment || ! $operation || $operation->status !== 'completed'
            || ! $this->stripeId($operation->stripe_object_id, 're_')
            || ! $succeeded || $succeeded->stripe_object_id !== $operation->stripe_object_id
            || $payment->status !== 'refunded' || $payment->refunded_at === null) {
            throw new RuntimeException('Stripe refunded charge confirmation is pending.');
        }
        if (($event['account'] ?? null) !== null || ($event['context'] ?? null) !== null
            || ($event['api_version'] ?? null) !== $operation->stripe_api_version
            || ($charge['livemode'] ?? null) !== false
            || ($charge['amount'] ?? null) !== (int) $payment->amount_cents
            || ($charge['currency'] ?? null) !== $payment->currency
            || ($charge['paid'] ?? null) !== true
            || ($charge['refunded'] ?? null) !== true
            || ($charge['amount_refunded'] ?? null) !== (int) $payment->amount_cents) {
            throw new RuntimeException('Stripe refunded charge is not a supported full refund.');
        }

        $key = "stripe-event:{$receiptId}:customer_charge_refunded";
        if (DB::table('payment_journal_entries')->where('entry_key', $key)->exists()) {
            return 'ignored';
        }
        DB::table('payment_journal_entries')->insert([
            'payment_id' => $payment->id,
            'entry_key' => $key,
            'entry_type' => 'customer_charge_refunded_observed',
            'amount_cents' => (int) $payment->amount_cents,
            'currency' => $payment->currency,
            'stripe_object_id' => $chargeId,
            'stripe_event_receipt_id' => $receiptId,
            'details' => json_encode([
                'refund_operation_request_id' => (int) $operation->id,
                'stripe_refund_id' => $operation->stripe_object_id,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return 'processed';
    }

    private function termsForIntent(string $intentId): ?object
    {
        return DB::table('payments as payment')
            ->join('payment_installments as installment', 'installment.payment_id', '=', 'payment.id')
            ->join('payment_agreements as agreement', 'agreement.id', '=', 'installment.payment_agreement_id')
            ->where('payment.stripe_payment_intent_id', $intentId)
            ->first([
                'payment.id as payment_id', 'payment.amount_cents',
                'payment.platform_fee_cents', 'payment.currency',
                'installment.id as installment_id', 'installment.transfer_group',
                'agreement.id as agreement_id', 'agreement.job_id',
            ]);
    }

    private function fundingChargeId(int $paymentId, string $intentId): string
    {
        $entry = DB::table('payment_journal_entries')
            ->where('payment_id', $paymentId)
            ->where('entry_type', 'payment_succeeded')
            ->where('stripe_object_id', $intentId)
            ->lockForUpdate()
            ->first(['details']);
        if (! $entry) {
            throw new RuntimeException('Stripe funding journal is missing.');
        }
        try {
            $details = json_decode((string) $entry->details, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Stripe funding journal is invalid.');
        }
        $chargeId = is_array($details) ? ($details['stripe_charge_id'] ?? null) : null;
        if (! $this->stripeId($chargeId, 'ch_')) {
            throw new RuntimeException('Stripe funding charge is invalid.');
        }

        return $chargeId;
    }

    private function operationForInstallment(int $installmentId, string $type): ?object
    {
        return DB::table('stripe_operation_requests as request')
            ->join('stripe_operation_processing as state', 'state.operation_request_id', '=', 'request.id')
            ->where('request.payment_installment_id', $installmentId)
            ->where('request.operation_type', $type)
            ->orderByDesc('request.id')
            ->first(['request.id', 'state.status', 'state.stripe_object_id']);
    }

    private function operationId(int $paymentId, string $type): ?int
    {
        $id = DB::table('stripe_operation_requests as request')
            ->join('payment_installments as installment', 'installment.id', '=', 'request.payment_installment_id')
            ->where('installment.payment_id', $paymentId)
            ->where('request.operation_type', $type)
            ->value('request.id');

        return $id === null ? null : (int) $id;
    }

    private function existingRefundOperationId(int $paymentId): ?int
    {
        return $this->operationId($paymentId, self::REFUND_OPERATION);
    }

    private function completedReversalExists(int $installmentId): bool
    {
        $operation = $this->operationForInstallment($installmentId, self::REVERSAL_OPERATION);

        return $operation !== null && $operation->status === 'completed'
            && $this->stripeId($operation->stripe_object_id, 'trr_');
    }

    private function savedPayload(object $request, string $type, object $snapshot): ?array
    {
        try {
            $payload = $this->decryptPayload($request);
        } catch (RuntimeException) {
            return null;
        }
        $prefix = $type === self::REVERSAL_OPERATION
            ? 'trustfix_reversal_' : 'trustfix_refund_';

        return $payload === $this->payloadFor($type, $snapshot)
            && preg_match('/\A'.preg_quote($prefix, '/').'[a-f0-9]{32}\z/',
                $request->idempotency_key) === 1
            ? $payload : null;
    }

    private function decryptPayload(object $request): array
    {
        try {
            $plaintext = Crypt::decryptString($request->payload_encrypted);
            if (! hash_equals($request->payload_sha256, hash('sha256', $plaintext))) {
                throw new RuntimeException('Saved Stripe operation integrity check failed.');
            }
            $payload = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new RuntimeException('Saved Stripe operation payload is invalid.');
        }
        if (! is_array($payload)) {
            throw new RuntimeException('Saved Stripe operation payload is invalid.');
        }

        return $payload;
    }

    private function payloadFor(string $type, object $snapshot): array
    {
        $metadata = [
            'trustfix_job_id' => $snapshot->job_id,
            'trustfix_payment_id' => $snapshot->payment_id,
            'trustfix_installment_id' => $snapshot->installment_id,
            'trustfix_agreement_id' => $snapshot->agreement_id,
        ];
        if ($type === self::REVERSAL_OPERATION) {
            return [
                'amount_cents' => $snapshot->proceeds_cents,
                'currency' => $snapshot->currency,
                'transfer_id' => $snapshot->transfer_id,
                'metadata' => $metadata,
            ];
        }

        return [
            'amount_cents' => $snapshot->amount_cents,
            'currency' => $snapshot->currency,
            'payment_intent_id' => $snapshot->payment_intent_id,
            'charge_id' => $snapshot->charge_id,
            'reason' => 'requested_by_customer',
            'metadata' => $metadata,
        ];
    }

    private function sameSnapshot(object $left, object $right): bool
    {
        return $this->payloadFor(self::REFUND_OPERATION, $left)
                === $this->payloadFor(self::REFUND_OPERATION, $right)
            && ($left->transfer_id === null
                ? $right->transfer_id === null
                : $this->payloadFor(self::REVERSAL_OPERATION, $left)
                    === $this->payloadFor(self::REVERSAL_OPERATION, $right))
            && $left->platform_fee_cents === $right->platform_fee_cents
            && $left->destination === $right->destination;
    }

    private function assertMetadata(mixed $metadata, object $terms): void
    {
        $expected = [
            'trustfix_payment_model' => StripePaymentGateway::PAYMENT_MODEL,
            'trustfix_job_id' => (string) $terms->job_id,
            'trustfix_payment_id' => (string) $terms->payment_id,
            'trustfix_installment_id' => (string) $terms->installment_id,
            'trustfix_agreement_id' => (string) $terms->agreement_id,
        ];
        foreach ($expected as $field => $value) {
            if (! is_array($metadata) || ($metadata[$field] ?? null) !== $value) {
                throw new RuntimeException('Stripe refund metadata does not match reserved terms.');
            }
        }
    }

    private function journalOperation(
        object $request,
        string $type,
        string $entryType,
        string $suffix,
        ?string $stripeId,
        array $details
    ): void {
        $amount = $type === self::REVERSAL_OPERATION
            ? (int) $request->amount_cents - (int) $request->platform_fee_cents
            : (int) $request->amount_cents;
        DB::table('payment_journal_entries')->insert([
            'payment_id' => (int) $request->payment_id,
            'entry_key' => "stripe-operation:{$request->id}:{$suffix}",
            'entry_type' => $entryType,
            'amount_cents' => $amount,
            'currency' => $request->currency,
            'stripe_object_id' => $stripeId,
            'actor_id' => (int) $request->actor_id,
            'details' => json_encode($details + [
                'operation_request_id' => (int) $request->id,
                'operation_type' => $type,
                'platform_fee_cents' => (int) $request->platform_fee_cents,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function eventType(string $type, string $event): string
    {
        return $type === self::REVERSAL_OPERATION
            ? "contractor_transfer_reversal_{$event}"
            : "customer_full_refund_{$event}";
    }

    private function operationResult(
        string $status,
        object $request,
        ?string $objectId = null
    ): array {
        return [
            'status' => $status,
            'payment_id' => (int) $request->payment_id,
            'stripe_object_id' => $objectId,
        ];
    }

    private function workflowResult(
        string $status,
        int $paymentId,
        string $stage,
        ?string $reversalId = null,
        ?string $refundId = null
    ): array {
        return [
            'status' => $status,
            'stage' => $stage,
            'payment_id' => $paymentId,
            'full_refund' => true,
            'stripe_transfer_reversal_id' => $reversalId,
            'stripe_refund_id' => $refundId,
        ];
    }

    private function stripeId(mixed $id, string $prefix): bool
    {
        return is_string($id) && strlen($id) <= 191
            && preg_match('/\A'.preg_quote($prefix, '/').'[A-Za-z0-9_]+\z/', $id) === 1;
    }
}
