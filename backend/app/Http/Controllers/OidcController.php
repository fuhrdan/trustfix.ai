<?php

namespace App\Http\Controllers;

use App\Exceptions\OidcException;
use App\Models\OidcFlowToken;
use App\Models\OidcIdentity;
use App\Models\PartnerApiClient;
use App\Models\PartnerMemberAccount;
use App\Models\User;
use App\Services\OidcService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OidcController extends Controller
{
    public function __construct(private readonly OidcService $oidc) {}

    public function redirect(Request $request, string $provider)
    {
        try {
            return redirect()->away($this->oidc->authorizationUrl($request, $provider));
        } catch (OidcException $exception) {
            Log::warning('OIDC sign-in could not be started.', [
                'provider' => $provider,
                'status' => $exception->status,
            ]);

            return $this->frontendRedirect('login.php', [
                'oidc_error' => $exception->getMessage(),
            ]);
        }
    }

    public function callback(Request $request, string $provider)
    {
        try {
            $identity = $this->oidc->resolveCallback($request, $provider);
            $outcome = DB::transaction(function () use ($identity) {
                $existingIdentity = $this->findIdentity(
                    $identity['issuer'],
                    $identity['subject']
                );

                if ($existingIdentity) {
                    $user = $existingIdentity->user()->lockForUpdate()->first();
                    if (! $user) {
                        throw new OidcException('The linked TrustFix account is unavailable.', 409);
                    }

                    $this->assertActive($user);
                    $existingIdentity->forceFill([
                        'last_seen_email' => $identity['email'],
                        'last_login_at' => now(),
                    ])->save();
                    $this->syncPartnerMembership($identity, $user);

                    return [
                        'type' => 'login',
                        'token' => $this->issueLoginToken($user, $identity),
                    ];
                }

                $user = User::query()
                    ->where('email', $identity['email'])
                    ->lockForUpdate()
                    ->first();

                if ($user) {
                    $this->assertActive($user);
                    $existingProviderIdentity = OidcIdentity::query()
                        ->where('user_id', $user->id)
                        ->where('provider', $identity['provider'])
                        ->lockForUpdate()
                        ->first();

                    if ($existingProviderIdentity) {
                        throw new OidcException(
                            'This TrustFix account already has a different identity linked for this provider.',
                            409
                        );
                    }

                    return [
                        'type' => 'link',
                        'token' => $this->issueLinkToken($user, $identity),
                    ];
                }

                $user = User::create([
                    'name' => $identity['name'],
                    'email' => $identity['email'],
                    // New federated accounts do not receive a usable local
                    // password. A member can deliberately set one later via
                    // the standard password-reset workflow if needed.
                    'password' => Hash::make(Str::random(64)),
                    'role' => 'customer',
                    'account_status' => 'active',
                ]);
                if ($identity['email_verified']) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }

                $this->createIdentity($user, $identity);
                $this->syncPartnerMembership($identity, $user);

                return [
                    'type' => 'login',
                    'token' => $this->issueLoginToken($user, $identity),
                ];
            });

            if ($outcome['type'] === 'link') {
                return $this->frontendRedirect('oidc_link.php', [
                    'token' => $outcome['token'],
                ]);
            }

            return $this->frontendRedirect('oidc_complete.php', [
                'token' => $outcome['token'],
            ]);
        } catch (OidcException $exception) {
            Log::warning('OIDC callback was rejected.', [
                'provider' => $provider,
                'status' => $exception->status,
            ]);

            return $this->frontendRedirect('login.php', [
                'oidc_error' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            Log::error('OIDC callback failed unexpectedly.', [
                'provider' => $provider,
                'exception' => $exception->getMessage(),
            ]);

            return $this->frontendRedirect('login.php', [
                'oidc_error' => 'TrustFix could not complete single sign-on. Please try again.',
            ]);
        }
    }

    public function complete(Request $request)
    {
        $validated = $request->validate([
            'completion_token' => ['required', 'string', 'min:32', 'max:128'],
        ]);

        $flow = OidcFlowToken::consume($validated['completion_token'], 'login');
        if (! $flow || ! $flow->user_id) {
            return response()->json([
                'message' => 'This sign-in link is invalid or has expired. Start again from TrustFix.',
            ], 401);
        }

        $user = User::find($flow->user_id);
        if (! $user || ($user->account_status ?? 'active') !== 'active') {
            return response()->json([
                'message' => 'This TrustFix account is not currently active.',
            ], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = Auth::guard('api')->login($user);

        return response()->json([
            'user' => $user,
            'token' => $token,
            'token_type' => 'bearer',
        ]);
    }

    public function link(Request $request)
    {
        $validated = $request->validate([
            'link_token' => ['required', 'string', 'min:32', 'max:128'],
        ]);
        /** @var User $user */
        $user = $request->user('api');
        $flow = OidcFlowToken::consume($validated['link_token'], 'link');

        if (! $flow) {
            return response()->json([
                'message' => 'This account-linking request is invalid or has expired.',
            ], 401);
        }

        if (
            ($flow->user_id && (int) $flow->user_id !== (int) $user->id)
            || ! $flow->email_verified
            || ! hash_equals(strtolower((string) $user->email), strtolower((string) $flow->email))
        ) {
            return response()->json([
                'message' => 'Sign in with the existing TrustFix account that matches the provider’s verified email address.',
            ], 403);
        }

        try {
            DB::transaction(function () use ($flow, $user) {
                $existingIdentity = $this->findIdentity(
                    (string) $flow->issuer,
                    (string) $flow->subject
                );
                if ($existingIdentity && (int) $existingIdentity->user_id !== (int) $user->id) {
                    throw new OidcException('This provider identity is already linked to another TrustFix account.', 409);
                }

                $sameProvider = OidcIdentity::query()
                    ->where('user_id', $user->id)
                    ->where('provider', (string) $flow->provider)
                    ->lockForUpdate()
                    ->first();
                if ($sameProvider && (int) ($existingIdentity?->id ?? 0) !== (int) $sameProvider->id) {
                    throw new OidcException('This TrustFix account already has a different identity linked for this provider.', 409);
                }

                if (! $existingIdentity) {
                    $this->createIdentity($user, [
                        'provider' => (string) $flow->provider,
                        'issuer' => (string) $flow->issuer,
                        'subject' => (string) $flow->subject,
                        'email' => (string) $flow->email,
                    ]);
                }

                if ($user->email_verified_at === null && $flow->email_verified) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }

                $claims = is_array($flow->claims) ? $flow->claims : [];
                $this->syncPartnerMembership([
                    'provider' => (string) $flow->provider,
                    'member_id' => $claims['_trustfix_member_id'] ?? null,
                ], $user);
            });
        } catch (OidcException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->status);
        }

        return response()->json([
            'linked' => true,
            'message' => 'Your external identity is now linked to this TrustFix account.',
        ]);
    }

    private function issueLoginToken(User $user, array $identity): string
    {
        return OidcFlowToken::issue([
            'purpose' => 'login',
            'user_id' => $user->id,
            'provider' => $identity['provider'],
            'expires_at' => now()->addSeconds(
                max(60, (int) config('identity.oidc.completion_ttl_seconds', 300))
            ),
        ]);
    }

    private function issueLinkToken(User $user, array $identity): string
    {
        $claims = is_array($identity['claims'] ?? null) ? $identity['claims'] : [];
        $claims['_trustfix_member_id'] = $identity['member_id'] ?? null;

        return OidcFlowToken::issue([
            'purpose' => 'link',
            'user_id' => $user->id,
            'provider' => $identity['provider'],
            'issuer' => $identity['issuer'],
            'subject' => $identity['subject'],
            'email' => $identity['email'],
            'name' => $identity['name'],
            'email_verified' => $identity['email_verified'],
            'claims' => $claims,
            'expires_at' => now()->addSeconds(
                max(60, (int) config('identity.oidc.completion_ttl_seconds', 300))
            ),
        ]);
    }

    private function createIdentity(User $user, array $identity): OidcIdentity
    {
        return OidcIdentity::create([
            'user_id' => $user->id,
            'provider' => $identity['provider'],
            'issuer' => $identity['issuer'],
            'issuer_hash' => OidcIdentity::issuerHash($identity['issuer']),
            'subject' => $identity['subject'],
            'subject_hash' => OidcIdentity::subjectHash($identity['subject']),
            'last_seen_email' => $identity['email'],
            'last_login_at' => now(),
        ]);
    }

    private function findIdentity(string $issuer, string $subject): ?OidcIdentity
    {
        $identity = OidcIdentity::query()
            ->where('issuer_hash', OidcIdentity::issuerHash($issuer))
            ->where('subject_hash', OidcIdentity::subjectHash($subject))
            ->lockForUpdate()
            ->first();

        if (
            $identity
            && (! hash_equals($issuer, $identity->issuer) || ! hash_equals($subject, $identity->subject))
        ) {
            throw new OidcException('The identity provider account identifier could not be verified.', 401);
        }

        return $identity;
    }

    private function syncPartnerMembership(array $identity, User $user): void
    {
        $configuration = $this->oidc->provider((string) $identity['provider']);
        $slug = trim((string) ($configuration['partner_slug'] ?? ''));
        $memberId = trim((string) ($identity['member_id'] ?? ''));

        if ($slug === '' || $memberId === '') {
            return;
        }

        $partner = PartnerApiClient::query()->where('slug', $slug)->lockForUpdate()->first();
        if (! $partner) {
            // OIDC can safely go live before the partner API. An administrator
            // can create the matching client later, then map members manually.
            return;
        }

        $membership = PartnerMemberAccount::query()
            ->where('partner_api_client_id', $partner->id)
            ->where('external_member_id', $memberId)
            ->lockForUpdate()
            ->first();

        if ($membership && (int) $membership->user_id !== (int) $user->id) {
            throw new OidcException('This membership is already linked to another TrustFix account.', 409);
        }

        if (! $membership) {
            PartnerMemberAccount::create([
                'partner_api_client_id' => $partner->id,
                'user_id' => $user->id,
                'external_member_id' => $memberId,
                'membership_status' => 'active',
                'last_confirmed_at' => now(),
            ]);

            return;
        }

        $membership->forceFill([
            'membership_status' => 'active',
            'last_confirmed_at' => now(),
        ])->save();
    }

    private function assertActive(User $user): void
    {
        if (($user->account_status ?? 'active') !== 'active') {
            throw new OidcException('This TrustFix account is not currently active.', 403);
        }
    }

    private function frontendRedirect(string $path, array $query = [])
    {
        $url = rtrim((string) config('trustfix.frontend_url'), '/')
            .'/'
            .ltrim($path, '/');
        if ($query) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return redirect()->away($url);
    }
}
