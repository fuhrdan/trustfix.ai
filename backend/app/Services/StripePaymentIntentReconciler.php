<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StripePaymentIntentReconciler
{
    public function __construct(private readonly StripeEventProcessor $processor)
    {
    }

    /** @param null|callable(array): string $legacyHandler */
    public function process(int $receiptId, ?callable $legacyHandler = null): string
    {
        return $this->processor->process(
            $receiptId,
            fn (array $event, int $id): string => $this->reconcileTerminalEvent($event, $id)
                ?? ($legacyHandler === null ? 'ignored' : $legacyHandler($event))
        );
    }

    private function reconcileTerminalEvent(#[\SensitiveParameter] array $event, int $receiptId): ?string
    {
        $status = match ($event['type']) {
            'payment_intent.succeeded' => 'succeeded',
            'payment_intent.canceled' => 'canceled',
            default => null,
        };
        if ($status === null) {
            return null;
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

            return null;
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

        $this->assertTerminalSnapshot($event, $terms, $operation->stripe_api_version, $status);
        if ($status === 'canceled') {
            return $this->recordCancellation($payment, $intent, $receiptId);
        }

        $chargeId = $intent['latest_charge'] ?? null;
        if (is_array($chargeId)) {
            $chargeId = ($chargeId['object'] ?? null) === 'charge' ? ($chargeId['id'] ?? null) : null;
        }
        if (!$this->stripeId($chargeId, 'ch_')) {
            throw new RuntimeException('Stripe funding snapshot does not match reserved terms.');
        }
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

    private function recordCancellation(object $payment, #[\SensitiveParameter] array $intent, int $receiptId): string
    {
        $canceledAt = $intent['canceled_at'] ?? null;
        if (!is_int($canceledAt) || $canceledAt < 1) {
            throw new RuntimeException('Stripe cancellation time is invalid.');
        }

        $key = "payment:{$payment->id}:cancelled";
        $fundedKey = "payment:{$payment->id}:succeeded";
        $entries = DB::table('payment_journal_entries')->whereIn('entry_key', [$key, $fundedKey])
            ->lockForUpdate()->get()->keyBy('entry_key');
        if ($entries->has($fundedKey) || $payment->paid_at !== null
            || !in_array($payment->status, [
                'requires_payment_method', 'requires_action', 'processing', 'failed', 'cancelled',
            ], true)) {
            throw new RuntimeException('Stripe cancellation conflicts with the local payment state.');
        }

        $details = ['stripe_canceled_at' => $canceledAt];
        $previous = $entries->get($key);
        if ($previous) {
            if ((int) $previous->payment_id !== (int) $payment->id
                || $previous->entry_type !== 'payment_intent_cancelled'
                || (int) $previous->amount_cents !== 0
                || $previous->currency !== $payment->currency
                || $previous->stripe_object_id !== $intent['id']
                || $previous->stripe_event_receipt_id === null
                || json_decode((string) $previous->details, true, 512, JSON_THROW_ON_ERROR) !== $details
                || $payment->status !== 'cancelled') {
                throw new RuntimeException('Conflicting Stripe cancellation journal entry.');
            }

            return 'ignored';
        }

        $now = now();
        DB::table('payment_journal_entries')->insert([
            'payment_id' => $payment->id,
            'entry_key' => $key,
            'entry_type' => 'payment_intent_cancelled',
            'amount_cents' => 0,
            'currency' => $payment->currency,
            'stripe_object_id' => $intent['id'],
            'stripe_event_receipt_id' => $receiptId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);
        DB::table('payments')->where('id', $payment->id)->update([
            'status' => 'cancelled',
            'updated_at' => $now,
        ]);

        return 'processed';
    }

    private function assertTerminalSnapshot(
        #[\SensitiveParameter] array $event, object $terms, string $apiVersion, string $status
    ): void {
        $intent = $event['data']['object'];
        $kind = $status === 'succeeded' ? 'funding' : 'cancellation';
        $received = $status === 'succeeded' ? (int) $terms->amount_cents : 0;

        if (($event['account'] ?? null) !== null || ($event['context'] ?? null) !== null
            || ($event['api_version'] ?? null) !== $apiVersion
            || $terms->payment_model !== StripePaymentGateway::PAYMENT_MODEL
            || ($intent['object'] ?? null) !== 'payment_intent'
            || ($intent['livemode'] ?? null) !== false
            || ($intent['status'] ?? null) !== $status
            || ($intent['capture_method'] ?? null) !== 'automatic'
            || ($intent['amount'] ?? null) !== (int) $terms->amount_cents
            || ($intent['amount_received'] ?? null) !== $received
            || $terms->currency !== 'usd' || ($intent['currency'] ?? null) !== $terms->currency
            || ($intent['transfer_group'] ?? null) !== $terms->transfer_group
            || ($intent['transfer_data'] ?? null) !== null
            || ($intent['application_fee_amount'] ?? null) !== null
            || ($intent['on_behalf_of'] ?? null) !== null) {
            throw new RuntimeException("Stripe $kind snapshot does not match reserved terms.");
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
                throw new RuntimeException("Stripe $kind metadata does not match reserved terms.");
            }
        }
    }

    private function stripeId(mixed $id, string $prefix): bool
    {
        return is_string($id) && strlen($id) <= 191
            && preg_match('/\A' . preg_quote($prefix, '/') . '[A-Za-z0-9_]+\z/', $id) === 1;
    }
}
