<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StripePaymentIntentReconciler
{
    public function __construct(private readonly StripeEventProcessor $processor)
    {
    }

    public function process(int $receiptId): string
    {
        return $this->processor->process($receiptId, $this->recordSuccess(...));
    }

    private function recordSuccess(#[\SensitiveParameter] array $event, int $receiptId): string
    {
        if ($event['type'] !== 'payment_intent.succeeded') {
            return 'ignored';
        }

        $intent = $event['data']['object'];
        $intentId = $intent['id'];
        if (!$this->stripeId($intentId, 'pi_')) {
            throw new RuntimeException('Invalid Stripe PaymentIntent identifier.');
        }

        // The durable provider ID locates the payable. Metadata cannot bind it.
        $terms = DB::table('payments')
            ->join('payment_installments as installments', 'installments.payment_id', '=', 'payments.id')
            ->join('payment_agreements as agreements', 'agreements.id', '=', 'installments.payment_agreement_id')
            ->where('payments.stripe_payment_intent_id', $intentId)
            ->first([
                'installments.*', 'agreements.job_id', 'agreements.customer_id',
                'agreements.contractor_id', 'agreements.agreement_sha256', 'agreements.payment_model',
            ]);
        if ($terms === null) {
            if (($intent['metadata']['trustfix_payment_model'] ?? null) === StripePaymentGateway::PAYMENT_MODEL) {
                throw new RuntimeException('Stripe intent binding is pending.');
            }

            return 'ignored';
        }

        $job = DB::table('jobs')->where('id', $terms->job_id)->lockForUpdate()->first(['id']);
        $operation = DB::table('stripe_operation_requests as request')
            ->join('stripe_operation_processing as state', 'state.operation_request_id', '=', 'request.id')
            ->where('request.payment_installment_id', $terms->id)
            ->where('request.operation_type', PaymentCheckoutReservation::OPERATION)
            ->where('request.operation_key', $terms->payable_key . ':intent')
            ->lockForUpdate()->first([
                'request.actor_id', 'request.stripe_api_version', 'state.status', 'state.stripe_object_id',
            ]);
        if (!$job || !$operation || $operation->status !== 'completed'
            || $operation->stripe_object_id !== $intentId
            || (int) $operation->actor_id !== (int) $terms->customer_id) {
            throw new RuntimeException('Stripe intent operation requires reconciliation.');
        }

        $payment = DB::table('payments')->where('id', $terms->payment_id)->lockForUpdate()->first();
        if (!$payment || $payment->stripe_payment_intent_id !== $intentId
            || (int) $payment->job_id !== (int) $terms->job_id
            || (int) $payment->customer_id !== (int) $terms->customer_id
            || (int) $payment->contractor_id !== (int) $terms->contractor_id
            || (int) $payment->amount_cents !== (int) $terms->amount_cents
            || (int) $payment->platform_fee_cents !== (int) $terms->platform_fee_cents
            || $payment->currency !== $terms->currency) {
            throw new RuntimeException('Stripe funding does not match the local payment.');
        }

        $chargeId = $this->confirmedCharge($event, $terms, $operation->stripe_api_version);
        $key = "payment:{$payment->id}:succeeded";
        $previous = DB::table('payment_journal_entries')->where('entry_key', $key)->lockForUpdate()->first();
        if ($previous) {
            $details = json_decode((string) $previous->details, true, 512, JSON_THROW_ON_ERROR);
            if ((int) $previous->payment_id !== (int) $payment->id
                || $previous->entry_type !== 'payment_succeeded'
                || (int) $previous->amount_cents !== (int) $terms->amount_cents
                || $previous->currency !== $terms->currency
                || $previous->stripe_object_id !== $intentId
                || $previous->stripe_event_receipt_id === null
                || ($details['stripe_charge_id'] ?? null) !== $chargeId) {
                throw new RuntimeException('Conflicting Stripe funding journal entry.');
            }

            return 'ignored';
        }

        if (!in_array($payment->status, [
            'requires_payment_method', 'requires_action', 'processing', 'failed', 'succeeded',
        ], true)) {
            throw new RuntimeException('Stripe funding conflicts with the local payment state.');
        }

        $observedAt = now();
        DB::table('payment_journal_entries')->insert([
            'payment_id' => $payment->id,
            'entry_key' => $key,
            'entry_type' => 'payment_succeeded',
            'amount_cents' => (int) $terms->amount_cents,
            'currency' => $terms->currency,
            'stripe_object_id' => $intentId,
            'stripe_event_receipt_id' => $receiptId,
            'details' => json_encode(['stripe_charge_id' => $chargeId], JSON_THROW_ON_ERROR),
            'created_at' => $observedAt,
        ]);
        DB::table('payments')->where('id', $payment->id)->update([
            'status' => 'succeeded',
            'paid_at' => $payment->paid_at ?? $observedAt,
            'updated_at' => $observedAt,
        ]);

        return 'processed';
    }

    private function confirmedCharge(#[\SensitiveParameter] array $event, object $terms, string $apiVersion): string
    {
        $intent = $event['data']['object'];
        $charge = $intent['latest_charge'] ?? null;
        if (is_array($charge)) {
            $charge = ($charge['object'] ?? null) === 'charge' ? ($charge['id'] ?? null) : null;
        }

        if (($event['account'] ?? null) !== null || ($event['context'] ?? null) !== null
            || ($event['api_version'] ?? null) !== $apiVersion
            || $terms->payment_model !== StripePaymentGateway::PAYMENT_MODEL
            || ($intent['object'] ?? null) !== 'payment_intent'
            || ($intent['livemode'] ?? null) !== false
            || ($intent['status'] ?? null) !== 'succeeded'
            || ($intent['capture_method'] ?? null) !== 'automatic'
            || ($intent['amount'] ?? null) !== (int) $terms->amount_cents
            || ($intent['amount_received'] ?? null) !== (int) $terms->amount_cents
            || $terms->currency !== 'usd' || ($intent['currency'] ?? null) !== $terms->currency
            || ($intent['transfer_group'] ?? null) !== $terms->transfer_group
            || ($intent['transfer_data'] ?? null) !== null
            || ($intent['application_fee_amount'] ?? null) !== null
            || ($intent['on_behalf_of'] ?? null) !== null
            || !$this->stripeId($charge, 'ch_')) {
            throw new RuntimeException('Stripe funding snapshot does not match reserved terms.');
        }

        $expected = [
            'trustfix_payment_model' => StripePaymentGateway::PAYMENT_MODEL,
            'trustfix_job_id' => (string) $terms->job_id,
            'trustfix_payment_id' => (string) $terms->payment_id,
            'trustfix_installment_id' => (string) $terms->id,
            'trustfix_agreement_id' => (string) $terms->payment_agreement_id,
            'trustfix_agreement_sha256' => $terms->agreement_sha256,
        ];
        $metadata = $intent['metadata'] ?? null;
        foreach ($expected as $field => $value) {
            if (!is_array($metadata) || ($metadata[$field] ?? null) !== $value) {
                throw new RuntimeException('Stripe funding metadata does not match reserved terms.');
            }
        }

        return $charge;
    }

    private function stripeId(mixed $id, string $prefix): bool
    {
        return is_string($id) && strlen($id) <= 191
            && preg_match('/\A' . preg_quote($prefix, '/') . '[A-Za-z0-9_]+\z/', $id) === 1;
    }
}
