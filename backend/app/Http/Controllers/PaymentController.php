<?php

namespace App\Http\Controllers;

use App\Models\ContractorProfile;
use App\Models\Payment;
use App\Services\LifecycleNotificationService;
use App\Services\PaymentAgreementService;
use App\Services\PaymentCheckoutService;
use App\Services\PaymentSafetyGuard;
use App\Services\StripeEventInbox;
use App\Services\StripePaymentIntentReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(
        private readonly LifecycleNotificationService $notifications
    ) {
    }

    private function stripe()
    {
        $guard = app(PaymentSafetyGuard::class);
        $guard->assertPaymentsEnabled();
        $secret = $guard->apiSecret();

        return Http::asForm()
            ->withToken($secret)
            ->baseUrl('https://api.stripe.com/v1');
    }

    public function publicConfig()
    {
        $key = app(PaymentSafetyGuard::class)->publishableKey();

        return response()->json([
            'publishable_key' => $key,
            'enabled' => $key !== null,
        ]);
    }

    public function createConnectAccount()
    {
        app(PaymentSafetyGuard::class)->assertPaymentsEnabled();

        $user = Auth::guard('api')->user();
        $profile = ContractorProfile::where('user_id', $user->id)->firstOrFail();

        abort_unless($profile->status === 'approved', 403, 'Contractor approval is required.');

        if (!$profile->stripe_account_id) {
            $response = $this->stripe()->post('/accounts', [
                'type' => 'express',
                'country' => 'US',
                'email' => $user->email,
                'capabilities[card_payments][requested]' => 'true',
                'capabilities[transfers][requested]' => 'true',
                'business_profile[url]' => $profile->website ?: config('app.url'),
                'metadata[trustfix_user_id]' => $user->id,
            ])->throw()->json();

            $profile->stripe_account_id = $response['id'];
            $profile->save();
        }

        return $this->createAccountLink($profile);
    }

    public function refreshConnectAccount()
    {
        app(PaymentSafetyGuard::class)->assertPaymentsEnabled();

        $user = Auth::guard('api')->user();
        $profile = ContractorProfile::where('user_id', $user->id)->firstOrFail();

        abort_unless($profile->stripe_account_id, 409, 'Create a payout account first.');

        $account = $this->stripe()
            ->get('/accounts/' . $profile->stripe_account_id)
            ->throw()
            ->json();

        $profile->update([
            'stripe_details_submitted' => (bool)($account['details_submitted'] ?? false),
            'stripe_charges_enabled' => (bool)($account['charges_enabled'] ?? false),
            'stripe_payouts_enabled' => (bool)($account['payouts_enabled'] ?? false),
        ]);

        return response()->json($profile);
    }

    private function createAccountLink(ContractorProfile $profile)
    {
        $frontend = rtrim(config('services.stripe.frontend_url'), '/');
        $link = $this->stripe()->post('/account_links', [
            'account' => $profile->stripe_account_id,
            'refresh_url' => $frontend . '/contractor_payout_return.php?refresh=1',
            'return_url' => $frontend . '/contractor_payout_return.php',
            'type' => 'account_onboarding',
        ])->throw()->json();

        return response()->json(['url' => $link['url']]);
    }

    public function proposeAgreement(Request $request, $jobId): JsonResponse
    {
        $agreement = app(PaymentAgreementService::class)->proposeInitial(
            (int) $jobId, (int) $request->user('api')->id
        );
        $consents = [];
        foreach (['customer' => $agreement->customer_id, 'contractor' => $agreement->contractor_id] as $role => $id) {
            $latest = DB::table('payment_agreement_consents')->useWritePdo()
                ->where('payment_agreement_id', $agreement->id)->where('actor_id', $id)->orderByDesc('id')->first();
            $consents[$role] = $latest?->actor_role === $role && $latest->event_type === 'scope_accepted'
                && hash_equals($agreement->agreement_sha256, $latest->agreement_sha256);
        }

        return response()->json(['agreement' => [
            'id' => (int) $agreement->id,
            'job_id' => (int) $agreement->job_id,
            'version' => (int) $agreement->version,
            'agreement_sha256' => $agreement->agreement_sha256,
            'amount_cents' => (int) $agreement->amount_cents,
            'platform_fee_cents' => (int) $agreement->platform_fee_cents,
            'currency' => $agreement->currency,
            'checkout_reserved' => DB::table('payment_installments')->useWritePdo()
                ->where('payment_agreement_id', $agreement->id)->exists(),
            'scope' => json_decode($agreement->scope_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'consents' => $consents,
        ]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function recordAgreementDecision(Request $request, $agreementId): JsonResponse
    {
        $data = $request->validate([
            'agreement_sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'event_type' => ['required', 'in:scope_accepted,consent_revoked'],
            'decision_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9:_-]{16,191}\z/'],
        ]);
        $decision = app(PaymentAgreementService::class)->recordDecision(
            (int) $agreementId, (int) $request->user('api')->id,
            $data['agreement_sha256'], $data['event_type'], $data['decision_key']
        );

        return response()->json(['decision' => [
            'id' => (int) $decision->id,
            'agreement_id' => (int) $decision->payment_agreement_id,
            'actor_role' => $decision->actor_role,
            'event_type' => $decision->event_type,
        ]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function createIntent(Request $request, $jobId): JsonResponse
    {
        app(PaymentSafetyGuard::class)->assertPaymentsEnabled();
        $data = $request->validate([
            'agreement_id' => ['required', 'integer', 'min:1'],
            'agreement_sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);

        return $this->checkoutResponse(app(PaymentCheckoutService::class)->begin(
            (int) $jobId, (int) $data['agreement_id'], (int) $request->user('api')->id, $data['agreement_sha256']
        ));
    }

    public function checkoutStatus(Request $request, $jobId): JsonResponse
    {
        return $this->checkoutResponse(app(PaymentCheckoutService::class)->status(
            (int) $jobId, (int) $request->user('api')->id
        ));
    }

    private function checkoutResponse(#[\SensitiveParameter] array $data): JsonResponse
    {
        $status = match ($data['checkout_status']) {
            'busy', 'pending' => 202,
            'reconciliation_required' => 409,
            default => 200,
        };

        return response()->json($data, $status, ['Cache-Control' => 'private, no-store']);
    }

    public function webhook(Request $request)
    {
        $receiptId = app(StripeEventInbox::class)->receive(
            $request->getContent(), $request->header('Stripe-Signature', '')
        );

        try {
            $outcome = app(StripePaymentIntentReconciler::class)
                ->process($receiptId, $this->applyLegacyWebhook(...));
            $status = in_array($outcome, ['processed', 'ignored', 'already_processed'], true) ? 200 : 503;
        } catch (Throwable $exception) {
            Log::warning('Stripe event processing requires retry or review.', [
                'receipt_id' => $receiptId,
                'exception_type' => $exception::class,
            ]);
            $status = 503;
        }

        return response()->json(['received' => true], $status);
    }

    private function applyLegacyWebhook(#[\SensitiveParameter] array $event): string
    {
        $object = $event['data']['object'];
        if ($event['type'] === 'account.updated' && $object['object'] === 'account') {
            ContractorProfile::where('stripe_account_id', $object['id'])->update([
                'stripe_details_submitted' => (bool) ($object['details_submitted'] ?? false),
                'stripe_charges_enabled' => (bool) ($object['charges_enabled'] ?? false),
                'stripe_payouts_enabled' => (bool) ($object['payouts_enabled'] ?? false),
            ]);

            return 'processed';
        }

        $status = match ($event['type']) {
            'payment_intent.succeeded' => 'succeeded',
            'payment_intent.processing' => 'processing',
            'payment_intent.payment_failed' => 'failed',
            'payment_intent.canceled' => 'cancelled',
            default => null,
        };
        if ($status === null || $object['object'] !== 'payment_intent') {
            return 'ignored';
        }

        $jobId = Payment::where('stripe_payment_intent_id', $object['id'])->value('job_id');
        if ($jobId === null || !DB::table('jobs')->where('id', $jobId)->lockForUpdate()->first(['id'])) {
            return 'ignored';
        }

        $payment = Payment::where('job_id', $jobId)
            ->where('stripe_payment_intent_id', $object['id'])->lockForUpdate()->first();
        if (!$payment || $payment->status === $status
            || in_array($payment->status, ['succeeded', 'cancelled', 'refunded'], true)) {
            return 'ignored';
        }

        // Reserved payments use the funding reconciler. Keep other snapshots in the inbox.
        $reserved = DB::table('payment_installments')->where('payment_id', $payment->id)
            ->lockForUpdate()->first(['id']);
        if ($reserved) {
            return 'ignored';
        }

        $payment->update([
            'status' => $status,
            'paid_at' => $status === 'succeeded' ? now() : $payment->paid_at,
            'metadata' => ['stripe_event_id' => $event['id']],
        ]);
        DB::afterCommit(fn () => $this->notifications->paymentUpdated($payment));

        return 'processed';
    }
}
