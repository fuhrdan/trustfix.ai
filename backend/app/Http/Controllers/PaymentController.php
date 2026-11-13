<?php

namespace App\Http\Controllers;

use App\Models\ContractorProfile;
use App\Models\Payment;
use App\Models\User;
use App\Services\LifecycleNotificationService;
use App\Services\ManualPaymentRefundService;
use App\Services\ManualPaymentReleaseService;
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
    private const STRIPE_ACCOUNTS_V2_VERSION = '2026-10-28.endive';

    public function __construct(
        private readonly LifecycleNotificationService $notifications
    ) {}

    private function stripe()
    {
        $guard = app(PaymentSafetyGuard::class);
        $guard->assertPaymentsEnabled();
        $secret = $guard->apiSecret();

        return Http::asForm()
            ->withToken($secret)
            ->baseUrl('https://api.stripe.com/v1');
    }

    private function stripeAccountsV2()
    {
        $guard = app(PaymentSafetyGuard::class);
        $guard->assertPaymentsEnabled();
        $secret = $guard->apiSecret();

        return Http::acceptJson()
            ->withToken($secret)
            ->withHeaders([
                'Stripe-Version' => self::STRIPE_ACCOUNTS_V2_VERSION,
            ])
            ->baseUrl('https://api.stripe.com/v2');
    }

    public function publicConfig()
    {
        $key = app(PaymentSafetyGuard::class)->publishableKey();

        return response()->json([
            'publishable_key' => $key,
            'enabled' => $key !== null,
        ]);
    }

    public function createConnectAccount(): JsonResponse
    {
        app(PaymentSafetyGuard::class)->assertPaymentsEnabled();

        $user = $this->onboardingUser();
        $profile = $this->profileForOnboarding($user);

        if (! $profile->stripe_account_id) {
            $account = $this->stripeAccountsV2()
                ->withHeaders([
                    'Idempotency-Key' => 'trustfix-connect-account-v3-user-'.$user->id,
                ])
                ->post('/core/accounts', [
                    'contact_email' => $user->email,
                    'display_name' => $profile->business_name,
                    'dashboard' => 'express',
                    'identity' => [
                        'country' => 'us',
                    ],
                    'configuration' => [
                        'recipient' => [
                            'capabilities' => [
                                'stripe_balance' => [
                                    'stripe_transfers' => [
                                        'requested' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'defaults' => [
                        'currency' => 'usd',
                        'responsibilities' => [
                            'fees_collector' => 'application',
                            'losses_collector' => 'application',
                        ],
                    ],
                    'include' => [
                        'configuration.recipient',
                        'defaults',
                        'identity',
                        'requirements',
                    ],
                    'metadata' => [
                        'trustfix_user_id' => (string) $user->id,
                    ],
                ])
                ->throw()
                ->json();

            $accountId = (string) ($account['id'] ?? '');

            abort_unless(
                preg_match('/\Aacct_[A-Za-z0-9_]+\z/', $accountId) === 1,
                502,
                'Stripe returned an invalid connected-account identifier.'
            );

            $profile = DB::transaction(function () use (
                $profile,
                $account,
                $accountId
            ): ContractorProfile {
                $locked = ContractorProfile::whereKey($profile->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->stripe_account_id === null) {
                    $locked->update([
                        'stripe_account_id' => $accountId,
                        'stripe_details_submitted' => (bool) ($account['details_submitted'] ?? false),
                        'stripe_charges_enabled' => (bool) ($account['charges_enabled'] ?? false),
                        'stripe_payouts_enabled' => (bool) ($account['payouts_enabled'] ?? false),
                    ]);
                } elseif (! hash_equals(
                    (string) $locked->stripe_account_id,
                    $accountId
                )) {
                    throw new \LogicException(
                        'Concurrent Stripe onboarding returned a different account.'
                    );
                }

                return $locked->fresh();
            });
        }

        return $this->createAccountLink($profile);
    }

    public function refreshConnectAccount(): JsonResponse
    {
        app(PaymentSafetyGuard::class)->assertPaymentsEnabled();

        $user = $this->onboardingUser();
        $profile = ContractorProfile::where('user_id', $user->id)->first();

        abort_unless(
            $profile && $profile->stripe_account_id,
            409,
            'Start payout onboarding first.'
        );

        $account = $this->stripe()
            ->get('/accounts/'.$profile->stripe_account_id)
            ->throw()
            ->json();

        abort_unless(
            hash_equals(
                (string) $profile->stripe_account_id,
                (string) ($account['id'] ?? '')
            ),
            502,
            'Stripe returned a different connected account.'
        );

        $profile->update([
            'stripe_details_submitted' => (bool) ($account['details_submitted'] ?? false),
            'stripe_charges_enabled' => (bool) ($account['charges_enabled'] ?? false),
            'stripe_payouts_enabled' => (bool) ($account['payouts_enabled'] ?? false),
        ]);

        return response()->json(
            $profile->fresh(),
            200,
            ['Cache-Control' => 'private, no-store']
        );
    }

    private function onboardingUser(): User
    {
        $user = Auth::guard('api')->user();

        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        abort_unless(
            $user->hasAnyRole(['handyman', 'company']),
            403,
            'A contractor account is required.'
        );

        abort_unless(
            $user->email_verified_at !== null,
            403,
            'Verify your email before starting payout onboarding.'
        );

        return $user;
    }

    private function profileForOnboarding(User $user): ContractorProfile
    {
        return DB::transaction(function () use ($user): ContractorProfile {
            DB::table('users')
                ->where('id', $user->id)
                ->lockForUpdate()
                ->first(['id']);

            return ContractorProfile::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'business_name' => trim((string) $user->name) !== ''
                            ? trim((string) $user->name)
                            : 'Contractor Profile',
                    'status' => 'pending',
                    'is_public' => false,
                    'is_verified' => false,
                    'stripe_details_submitted' => false,
                    'stripe_charges_enabled' => false,
                    'stripe_payouts_enabled' => false,
                ]
            );
        });
    }

    private function createAccountLink(
        ContractorProfile $profile
    ): JsonResponse {
        $frontend = rtrim(
            (string) config('services.stripe.frontend_url'),
            '/'
        );

        abort_unless(
            filter_var($frontend, FILTER_VALIDATE_URL) !== false &&
                strtolower((string) parse_url($frontend, PHP_URL_SCHEME))
                    === 'https',
            500,
            'The contractor onboarding return URL is invalid.'
        );

        $link = $this->stripe()->post('/account_links', [
            'account' => $profile->stripe_account_id,
            'refresh_url' => $frontend.'/contractor_payout_return.php?refresh=1',
            'return_url' => $frontend.'/contractor_payout_return.php',
            'type' => 'account_onboarding',
        ])->throw()->json();

        $url = (string) ($link['url'] ?? '');

        abort_unless(
            $this->isStripeOnboardingUrl($url),
            502,
            'Stripe returned an invalid onboarding URL.'
        );

        return response()->json(
            [
                'url' => $url,
                'details_submitted' => (bool) $profile->stripe_details_submitted,
                'charges_enabled' => (bool) $profile->stripe_charges_enabled,
                'payouts_enabled' => (bool) $profile->stripe_payouts_enabled,
            ],
            200,
            ['Cache-Control' => 'private, no-store']
        );
    }

    private function isStripeOnboardingUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? ''))
                === 'connect.stripe.com'
            && ! isset($parts['user'], $parts['pass'], $parts['port'])
            && str_starts_with((string) ($parts['path'] ?? ''), '/');
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

    public function releaseStatus(Request $request, $jobId): JsonResponse
    {
        $data = app(ManualPaymentReleaseService::class)->status((int) $jobId);
        $data['refund'] = app(ManualPaymentRefundService::class)->status((int) $jobId);

        return response()->json(
            $data,
            200,
            ['Cache-Control' => 'private, no-store']
        );
    }

    public function releasePayment(Request $request, $paymentId): JsonResponse
    {
        $request->validate([
            'confirmation' => [
                'required',
                'in:release_contractor_proceeds',
            ],
        ]);

        $data = app(ManualPaymentReleaseService::class)->release(
            (int) $paymentId,
            (int) $request->user('api')->id
        );
        $status = match ($data['status']) {
            'busy', 'pending' => 202,
            'reconciliation_required' => 409,
            default => 200,
        };

        return response()->json(
            $data,
            $status,
            ['Cache-Control' => 'private, no-store']
        );
    }

    public function refundPayment(Request $request, $paymentId): JsonResponse
    {
        $request->validate([
            'confirmation' => [
                'required',
                'in:refund_full_customer_payment',
            ],
        ]);

        $data = app(ManualPaymentRefundService::class)->refund(
            (int) $paymentId,
            (int) $request->user('api')->id
        );
        $status = match ($data['status']) {
            'busy', 'pending' => 202,
            'reconciliation_required' => 409,
            default => 200,
        };

        return response()->json(
            $data,
            $status,
            ['Cache-Control' => 'private, no-store']
        );
    }

    public function webhook(Request $request)
    {
        $receiptId = app(StripeEventInbox::class)->receive(
            $request->getContent(), $request->header('Stripe-Signature', '')
        );

        try {
            $refunds = app(ManualPaymentRefundService::class);
            $outcome = app(StripePaymentIntentReconciler::class)
                ->process(
                    $receiptId,
                    $this->applyLegacyWebhook(...),
                    $refunds->reconcileEvent(...)
                );
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
        if ($jobId === null || ! DB::table('jobs')->where('id', $jobId)->lockForUpdate()->first(['id'])) {
            return 'ignored';
        }

        $payment = Payment::where('job_id', $jobId)
            ->where('stripe_payment_intent_id', $object['id'])->lockForUpdate()->first();
        if (! $payment || $payment->status === $status
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
