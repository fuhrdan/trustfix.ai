<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StripeApiException;
use App\Models\Job;
use Illuminate\Support\Facades\DB;

final class PaymentCheckoutService
{
    private const TERMINAL = ['succeeded', 'cancelled', 'refunded'];

    public function __construct(
        private readonly PaymentSafetyGuard $guard,
        private readonly PaymentAgreementService $agreements,
        private readonly PaymentCheckoutReservation $reservations,
        private readonly StripePaymentIntentExecutor $executor,
        private readonly StripePaymentGateway $gateway
    ) {
    }

    public function begin(int $jobId, int $agreementId, int $customerId, string $hash): array
    {
        $reservation = $this->reservations->reserveInitial($jobId, $agreementId, $customerId, $hash);
        $execution = $this->executor->executeInitial($reservation['operation_id'], $customerId);
        if ($execution['status'] !== 'completed') {
            return [
                'checkout_status' => $execution['status'],
                'payment' => ['id' => $reservation['payment_id']],
            ];
        }

        return $this->observe($jobId, $customerId, $reservation['payment_id']);
    }

    public function status(int $jobId, int $customerId): array
    {
        return $this->observe($jobId, $customerId);
    }

    private function observe(int $jobId, int $customerId, ?int $authorizedPaymentId = null): array
    {
        $checkout = $this->snapshot($jobId, $customerId, $authorizedPaymentId);
        if ($checkout->operation_status !== 'completed') {
            $status = match ($checkout->operation_status) {
                'pending' => 'pending',
                'processing' => 'busy',
                'reconciliation_required' => 'reconciliation_required',
                default => abort(409, 'Checkout operation requires reconciliation.'),
            };

            return $this->result($checkout, $status);
        }
        if (in_array($checkout->status, self::TERMINAL, true)) {
            return $this->result($checkout, $checkout->status);
        }

        try {
            $intent = $this->gateway->retrievePaymentIntent($checkout->stripe_payment_intent_id)['data'];
        } catch (StripeApiException) {
            abort(503, 'Stripe checkout status is temporarily unavailable.');
        }

        // The network read runs outside both job transactions.
        $current = $this->snapshot($jobId, $customerId, $authorizedPaymentId);
        abort_unless($current->id === $checkout->id && $current->operation_status === 'completed'
            && $current->stripe_payment_intent_id === $checkout->stripe_payment_intent_id,
            409, 'Checkout changed during the Stripe status read.');
        if (in_array($current->status, self::TERMINAL, true)) {
            return $this->result($current, $current->status);
        }

        $this->assertProviderTerms($intent, $current);
        $stripeStatus = $intent['status'] ?? null;
        $status = match ($stripeStatus) {
            'requires_payment_method', 'requires_confirmation', 'requires_action' => 'ready',
            'processing' => 'processing',
            'succeeded' => 'awaiting_confirmation',
            'canceled' => 'awaiting_cancellation',
            default => abort(409, 'Stripe checkout status requires reconciliation.'),
        };
        $result = $this->result($current, $status) + [
            'stripe_status' => $stripeStatus,
            'payment_failed' => $stripeStatus === 'requires_payment_method'
                && is_array($intent['last_payment_error'] ?? null),
        ];

        if ($status === 'ready' && $authorizedPaymentId !== null) {
            $secret = $intent['client_secret'] ?? null;
            $prefix = preg_quote($current->stripe_payment_intent_id . '_secret_', '/');
            abort_unless(is_string($secret) && preg_match('/\A' . $prefix . '[A-Za-z0-9_-]{1,255}\z/', $secret) === 1,
                409, 'Stripe checkout secret requires reconciliation.');
            $result['client_secret'] = $secret;
        }

        return $result;
    }

    private function snapshot(int $jobId, int $customerId, ?int $authorizedPaymentId): object
    {
        $credentialHash = hash('sha256', $this->guard->apiSecret());

        return DB::transaction(function () use ($jobId, $customerId, $authorizedPaymentId, $credentialHash): object {
            $job = Job::query()->lockForUpdate()->findOrFail($jobId);
            abort_unless((int) $job->customer_id === $customerId, 403, 'Only the customer may view this checkout.');

            $checkout = DB::table('payment_installments as installment')
                ->join('payment_agreements as agreement', 'agreement.id', '=', 'installment.payment_agreement_id')
                ->join('stripe_operation_requests as request', 'request.payment_installment_id', '=', 'installment.id')
                ->join('stripe_operation_processing as state', 'state.operation_request_id', '=', 'request.id')
                ->join('payments as payment', 'payment.id', '=', 'installment.payment_id')
                ->where('agreement.job_id', $jobId)
                ->where('agreement.customer_id', $customerId)
                ->where('agreement.contractor_id', $job->handyman_id)
                ->where('agreement.agreement_kind', 'initial')
                ->where('agreement.payment_model', StripePaymentGateway::PAYMENT_MODEL)
                ->where('installment.payable_key', "job:{$jobId}:initial")
                ->where('request.operation_type', PaymentCheckoutReservation::OPERATION)
                ->where('request.operation_key', "job:{$jobId}:initial:intent")
                ->where('request.actor_id', $customerId)
                ->whereColumn([
                    ['payment.job_id', '=', 'agreement.job_id'],
                    ['payment.customer_id', '=', 'agreement.customer_id'],
                    ['payment.contractor_id', '=', 'agreement.contractor_id'],
                    ['payment.amount_cents', '=', 'installment.amount_cents'],
                    ['payment.platform_fee_cents', '=', 'installment.platform_fee_cents'],
                    ['payment.currency', '=', 'installment.currency'],
                    ['installment.amount_cents', '=', 'agreement.amount_cents'],
                    ['installment.platform_fee_cents', '=', 'agreement.platform_fee_cents'],
                    ['installment.currency', '=', 'agreement.currency'],
                ])->lockForUpdate()->first([
                    'payment.id', 'payment.job_id', 'payment.status', 'payment.amount_cents', 'payment.currency',
                    'payment.stripe_payment_intent_id', 'installment.id as installment_id', 'installment.transfer_group',
                    'agreement.id as agreement_id', 'agreement.agreement_sha256',
                    'request.credential_sha256', 'request.stripe_api_version', 'state.status as operation_status',
                    'state.stripe_object_id',
                ]);
            abort_unless($checkout, 404, 'A reserved checkout was not found.');
            abort_unless($checkout->stripe_api_version === StripePaymentGateway::API_VERSION
                && hash_equals($checkout->credential_sha256, $credentialHash) && $checkout->currency === 'usd',
                409, 'Checkout configuration requires reconciliation.');

            if ($authorizedPaymentId !== null) {
                abort_unless((int) $checkout->id === $authorizedPaymentId, 409, 'The authorized checkout changed.');
                $this->agreements->lockApprovedInitial(
                    (int) $checkout->agreement_id, $customerId, $checkout->agreement_sha256
                );
            }
            if ($checkout->operation_status === 'completed') {
                $intentId = $checkout->stripe_payment_intent_id;
                abort_unless(is_string($intentId) && preg_match('/\Api_[A-Za-z0-9_]{1,188}\z/', $intentId) === 1
                    && $intentId === $checkout->stripe_object_id, 409, 'Checkout intent binding requires reconciliation.');
            }
            if ($checkout->status === 'succeeded') {
                $funded = DB::table('payment_journal_entries as journal')
                    ->join('stripe_event_receipts as receipt', 'receipt.id', '=', 'journal.stripe_event_receipt_id')
                    ->where('journal.payment_id', $checkout->id)->where('journal.entry_type', 'payment_succeeded')
                    ->where('journal.amount_cents', $checkout->amount_cents)->where('journal.currency', $checkout->currency)
                    ->where('journal.stripe_object_id', $checkout->stripe_payment_intent_id)
                    ->where('receipt.event_type', 'payment_intent.succeeded')->where('receipt.livemode', false)
                    ->lockForUpdate()->first(['journal.id']);
                abort_unless($funded, 409, 'Checkout funding requires reconciliation.');
            }

            return $checkout;
        }, 3);
    }

    private function assertProviderTerms(#[\SensitiveParameter] array $intent, object $checkout): void
    {
        $amount = (int) $checkout->amount_cents;
        $received = $intent['amount_received'] ?? null;
        $validReceived = match ($intent['status'] ?? null) {
            'succeeded' => $received === $amount,
            'processing' => is_int($received) && $received >= 0 && $received <= $amount,
            default => $received === 0,
        };
        abort_unless(($intent['amount'] ?? null) === $amount
            && $validReceived && ($intent['currency'] ?? null) === $checkout->currency
            && ($intent['transfer_group'] ?? null) === $checkout->transfer_group
            && ($intent['capture_method'] ?? null) === 'automatic'
            && ($intent['transfer_data'] ?? null) === null && ($intent['application_fee_amount'] ?? null) === null
            && ($intent['on_behalf_of'] ?? null) === null, 409, 'Stripe checkout terms require reconciliation.');

        $expected = [
            'trustfix_payment_model' => StripePaymentGateway::PAYMENT_MODEL,
            'trustfix_job_id' => (string) $checkout->job_id,
            'trustfix_payment_id' => (string) $checkout->id,
            'trustfix_installment_id' => (string) $checkout->installment_id,
            'trustfix_agreement_id' => (string) $checkout->agreement_id,
            'trustfix_agreement_sha256' => $checkout->agreement_sha256,
        ];
        $metadata = $intent['metadata'] ?? null;
        foreach ($expected as $field => $value) {
            abort_unless(is_array($metadata) && ($metadata[$field] ?? null) === $value,
                409, 'Stripe checkout identity requires reconciliation.');
        }
    }

    private function result(object $checkout, string $status): array
    {
        return [
            'checkout_status' => $status,
            'payment' => [
                'id' => (int) $checkout->id,
                'job_id' => (int) $checkout->job_id,
                'status' => $checkout->status,
                'amount_cents' => (int) $checkout->amount_cents,
                'currency' => $checkout->currency,
            ],
        ];
    }
}
