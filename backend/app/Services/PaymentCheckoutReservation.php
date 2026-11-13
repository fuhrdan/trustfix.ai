<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class PaymentCheckoutReservation
{
    public const OPERATION = 'create_payment_intent_v1';

    public function __construct(
        private readonly PaymentAgreementService $agreements,
        private readonly PaymentSafetyGuard $guard
    ) {
    }

    /** @return array{payment_id: int, installment_id: int, operation_id: int} */
    public function reserveInitial(int $jobId, int $agreementId, int $customerId, string $expectedHash): array
    {
        $this->guard->assertPaymentsEnabled();
        $credentialHash = hash('sha256', $this->guard->apiSecret());

        return DB::transaction(function () use ($jobId, $agreementId, $customerId, $expectedHash, $credentialHash): array {
            $agreement = $this->agreements->lockApprovedInitial($agreementId, $customerId, $expectedHash);
            abort_unless((int) $agreement->job_id === $jobId, 409, 'Agreement does not belong to the requested job.');

            $payableKey = "job:{$jobId}:initial";
            $operationKey = "{$payableKey}:intent";
            $existing = DB::table('payment_installments')
                ->join('payment_agreements', 'payment_agreements.id', '=', 'payment_installments.payment_agreement_id')
                ->where('payment_agreements.job_id', $jobId)
                ->where('payment_agreements.agreement_kind', 'initial')
                ->orderBy('payment_installments.id')->lockForUpdate()->first(['payment_installments.*']);
            if ($existing !== null) {
                abort_unless((int) $existing->payment_agreement_id === $agreementId && $existing->payable_key === $payableKey,
                    409, 'An initial payable already exists and requires reconciliation.');

                return $this->existingReservation($existing, $operationKey, $credentialHash);
            }

            $now = now();
            $amount = (int) $agreement->amount_cents;
            $fee = (int) $agreement->platform_fee_cents;
            $currency = $agreement->currency;
            $paymentId = (int) DB::table('payments')->insertGetId([
                'job_id' => $jobId,
                'customer_id' => $customerId,
                'contractor_id' => (int) $agreement->contractor_id,
                'amount_cents' => $amount,
                'platform_fee_cents' => $fee,
                'currency' => $currency,
                'status' => 'requires_payment_method',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $transferGroup = 'trustfix_' . bin2hex(random_bytes(16));
            $installment = [
                'payment_agreement_id' => $agreementId,
                'payment_id' => $paymentId,
                'source_change_order_id' => null,
                'payable_key' => $payableKey,
                'sequence' => 1,
                'milestone_key' => 'upfront',
                'collection_trigger' => 'customer_authorized',
                'amount_cents' => $amount,
                'platform_fee_cents' => $fee,
                'currency' => $currency,
                'transfer_group' => $transferGroup,
            ];
            $installmentId = (int) DB::table('payment_installments')->insertGetId($installment + [
                'installment_sha256' => hash('sha256', json_encode($installment, JSON_THROW_ON_ERROR)),
                'created_at' => $now,
            ]);

            $payload = json_encode([
                'amount_cents' => $amount,
                'transfer_group' => $transferGroup,
                'description' => "TrustFix job #{$jobId}",
                'allow_redirects' => StripePaymentGateway::CHECKOUT_REDIRECTS,
                'metadata' => [
                    'trustfix_job_id' => $jobId,
                    'trustfix_payment_id' => $paymentId,
                    'trustfix_installment_id' => $installmentId,
                    'trustfix_agreement_id' => $agreementId,
                    'trustfix_agreement_sha256' => $expectedHash,
                ],
            ], JSON_THROW_ON_ERROR);
            $operationId = (int) DB::table('stripe_operation_requests')->insertGetId([
                'payment_installment_id' => $installmentId,
                'operation_type' => self::OPERATION,
                'operation_key' => $operationKey,
                'idempotency_key' => 'trustfix_intent_' . bin2hex(random_bytes(16)),
                'stripe_api_version' => StripePaymentGateway::API_VERSION,
                'credential_sha256' => $credentialHash,
                'payload_encrypted' => Crypt::encryptString($payload),
                'payload_sha256' => hash('sha256', $payload),
                'actor_id' => $customerId,
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
                'payment_id' => $paymentId,
                'entry_key' => "payment:{$paymentId}:collection_authorized",
                'entry_type' => 'collection_authorized',
                'amount_cents' => $amount,
                'currency' => $currency,
                'actor_id' => $customerId,
                'details' => json_encode([
                    'agreement_id' => $agreementId,
                    'agreement_sha256' => $expectedHash,
                    'installment_id' => $installmentId,
                    'operation_id' => $operationId,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);

            return ['payment_id' => $paymentId, 'installment_id' => $installmentId, 'operation_id' => $operationId];
        }, 3);
    }

    private function existingReservation(object $installment, string $operationKey, string $credentialHash): array
    {
        $request = DB::table('stripe_operation_requests')
            ->join('stripe_operation_processing', 'stripe_operation_processing.operation_request_id', '=', 'stripe_operation_requests.id')
            ->where('stripe_operation_requests.payment_installment_id', $installment->id)
            ->where('stripe_operation_requests.operation_type', self::OPERATION)
            ->where('stripe_operation_requests.operation_key', $operationKey)
            ->where('stripe_operation_requests.stripe_api_version', StripePaymentGateway::API_VERSION)
            ->where('stripe_operation_requests.credential_sha256', $credentialHash)
            ->lockForUpdate()->first(['stripe_operation_requests.id']);
        abort_if($request === null, 409, 'Existing checkout requires reconciliation.');

        return [
            'payment_id' => (int) $installment->payment_id,
            'installment_id' => (int) $installment->id,
            'operation_id' => (int) $request->id,
        ];
    }
}
