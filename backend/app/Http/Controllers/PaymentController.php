<?php

namespace App\Http\Controllers;

use App\Models\ContractorProfile;
use App\Models\Job;
use App\Models\Payment;
use App\Services\LifecycleNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    public function __construct(
        private readonly LifecycleNotificationService $notifications
    ) {
    }

    private function stripe()
    {
        $secret = config('services.stripe.secret');

        abort_if(!$secret, 503, 'Stripe is not configured.');

        return Http::asForm()
            ->withToken($secret)
            ->baseUrl('https://api.stripe.com/v1');
    }

    public function publicConfig()
    {
        return response()->json([
            'publishable_key' => config('services.stripe.key'),
        ]);
    }

    public function createConnectAccount()
    {
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

    public function createIntent(Request $request, $jobId)
    {
        $user = Auth::guard('api')->user();
        $job = Job::with('handyman')->findOrFail($jobId);

        abort_unless($job->customer_id === $user->id, 403);
        abort_unless($job->handyman_id, 409, 'A contractor must accept this job first.');
        abort_unless(in_array($job->status, ['accepted', 'scheduled', 'in_progress', 'completed'], true), 409);

        $profile = ContractorProfile::where('user_id', $job->handyman_id)->firstOrFail();
        abort_unless($profile->stripe_charges_enabled && $profile->stripe_account_id, 409, 'Contractor payouts are not ready.');

        $amountCents = (int)round(((float)$job->agreed_price) * 100);
        abort_unless($amountCents >= 50, 422, 'The agreed job price must be at least $0.50.');

        $feeCents = (int)round($amountCents * config('services.stripe.platform_fee_percent', 10) / 100);

        abort_if(
            Payment::where('job_id', $job->id)->where('status', 'succeeded')->exists(),
            409,
            'This job has already been paid.'
        );

        $payment = Payment::where('job_id', $job->id)
            ->whereIn('status', ['requires_payment_method', 'requires_action', 'processing'])
            ->latest()
            ->first();

        if (!$payment) {
            $payment = Payment::create([
                'job_id' => $job->id,
                'customer_id' => $user->id,
                'contractor_id' => $job->handyman_id,
                'amount_cents' => $amountCents,
                'platform_fee_cents' => $feeCents,
                'currency' => 'usd',
            ]);
        }

        if (!$payment->stripe_payment_intent_id) {
            $intent = $this->stripe()->post('/payment_intents', [
                'amount' => $amountCents,
                'currency' => 'usd',
                'automatic_payment_methods[enabled]' => 'true',
                'application_fee_amount' => $feeCents,
                'transfer_data[destination]' => $profile->stripe_account_id,
                'metadata[trustfix_payment_id]' => $payment->id,
                'metadata[trustfix_job_id]' => $job->id,
                'description' => 'Trustfix job #' . $job->id,
            ])->throw()->json();

            $payment->update(['stripe_payment_intent_id' => $intent['id']]);
        } else {
            $intent = $this->stripe()
                ->get('/payment_intents/' . $payment->stripe_payment_intent_id)
                ->throw()
                ->json();
        }

        return response()->json([
            'client_secret' => $intent['client_secret'],
            'payment' => $payment,
        ]);
    }

    public function webhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        abort_unless($this->validSignature($payload, $signature), 400, 'Invalid Stripe signature.');

        $event = json_decode($payload, true);
        $intent = $event['data']['object'] ?? [];

        if (($event['type'] ?? '') === 'account.updated') {
            ContractorProfile::where('stripe_account_id', $intent['id'] ?? '')
                ->update([
                    'stripe_details_submitted' => (bool)($intent['details_submitted'] ?? false),
                    'stripe_charges_enabled' => (bool)($intent['charges_enabled'] ?? false),
                    'stripe_payouts_enabled' => (bool)($intent['payouts_enabled'] ?? false),
                ]);

            return response()->json(['received' => true]);
        }

        $payment = Payment::where('stripe_payment_intent_id', $intent['id'] ?? '')->first();

        if (!$payment) {
            return response()->json(['received' => true]);
        }

        $status = match ($event['type'] ?? '') {
            'payment_intent.succeeded' => 'succeeded',
            'payment_intent.processing' => 'processing',
            'payment_intent.payment_failed' => 'failed',
            'payment_intent.canceled' => 'cancelled',
            default => null,
        };

        if ($status && $payment->status !== $status) {
            $payment->update([
                'status' => $status,
                'paid_at' => $status === 'succeeded' ? now() : $payment->paid_at,
                'metadata' => ['stripe_event_id' => $event['id'] ?? null],
            ]);

            $this->notifications->paymentUpdated($payment->fresh());
        }

        return response()->json(['received' => true]);
    }

    private function validSignature(string $payload, string $header): bool
    {
        $secret = config('services.stripe.webhook_secret');
        preg_match('/(?:^|,)t=(\d+)/', $header, $timestamp);
        preg_match_all('/(?:^|,)v1=([a-f0-9]+)/', $header, $signatures);

        if (!$secret || empty($timestamp[1]) || empty($signatures[1]) || abs(time() - (int)$timestamp[1]) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp[1] . '.' . $payload, $secret);

        foreach ($signatures[1] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
