<?php

namespace Tests\Feature;

use App\Models\ContractorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractorOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'testing',
            'payments.enabled' => true,
            'payments.test_mode_only' => true,
            'payments.releases_enabled' => true,
            'payments.refunds_enabled' => true,
            'payments.auto_release_enabled' => false,
            'services.stripe.key' => 'pk_test_contractor_onboarding',
            'services.stripe.secret' => 'sk_test_contractor_onboarding',
            'services.stripe.webhook_secret' =>
                'whsec_contractor_onboarding',
            'services.stripe.frontend_url' =>
                'https://trustfix.example.test',
        ]);
    }

    public function test_verified_contractor_starts_onboarding_in_one_click(): void
    {
        $user = $this->user('handyman', true);
        $accountId = 'acct_onboarding_' . $user->id;
        $onboardingUrl =
            'https://connect.stripe.com/setup/s/trustfix_test';

        Http::fake(function (ClientRequest $request) use (
            $accountId,
            $onboardingUrl
        ) {
            if ($request->url() === 'https://api.stripe.com/v1/accounts') {
                return Http::response([
                    'id' => $accountId,
                    'object' => 'account',
                    'details_submitted' => false,
                    'charges_enabled' => false,
                    'payouts_enabled' => false,
                ]);
            }

            if (
                $request->url()
                    === 'https://api.stripe.com/v1/account_links'
            ) {
                return Http::response([
                    'object' => 'account_link',
                    'url' => $onboardingUrl,
                ]);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $response = $this->withToken($this->token($user))
            ->postJson('/api/contractor/payout-account');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('url', $onboardingUrl)
            ->assertJsonPath('details_submitted', false)
            ->assertJsonPath('charges_enabled', false)
            ->assertJsonPath('payouts_enabled', false);

        $this->assertDatabaseHas('contractor_profiles', [
            'user_id' => $user->id,
            'business_name' => $user->name,
            'status' => 'pending',
            'is_public' => false,
            'is_verified' => false,
            'stripe_account_id' => $accountId,
        ]);

        Http::assertSentCount(2);

        Http::assertSent(function (ClientRequest $request) use (
            $user
        ): bool {
            if ($request->url() !== 'https://api.stripe.com/v1/accounts') {
                return false;
            }

            return ($request->header('Idempotency-Key')[0] ?? null)
                === 'trustfix-connect-account-user-' . $user->id;
        });
    }

    public function test_existing_connected_account_is_reused(): void
    {
        $user = $this->user('handyman', true);

        ContractorProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Existing Contractor',
            'status' => 'pending',
            'is_public' => false,
            'stripe_account_id' => 'acct_existing_contractor',
        ]);

        Http::fake([
            'https://api.stripe.com/v1/account_links' =>
                Http::response([
                    'object' => 'account_link',
                    'url' =>
                        'https://connect.stripe.com/setup/s/existing',
                ]),
        ]);

        $this->withToken($this->token($user))
            ->postJson('/api/contractor/payout-account')
            ->assertOk()
            ->assertJsonPath(
                'url',
                'https://connect.stripe.com/setup/s/existing'
            );

        Http::assertSentCount(1);

        Http::assertSent(
            fn (ClientRequest $request): bool =>
                $request->url()
                    === 'https://api.stripe.com/v1/account_links'
        );

        $this->assertDatabaseCount('contractor_profiles', 1);
    }

    public function test_unverified_contractor_cannot_start_onboarding(): void
    {
        $user = $this->user('handyman', false);
        Http::fake();

        $this->withToken($this->token($user))
            ->postJson('/api/contractor/payout-account')
            ->assertForbidden();

        $this->assertDatabaseCount('contractor_profiles', 0);
        Http::assertNothingSent();
    }

    public function test_customer_cannot_start_contractor_onboarding(): void
    {
        $user = $this->user('customer', true);
        Http::fake();

        $this->withToken($this->token($user))
            ->postJson('/api/contractor/payout-account')
            ->assertForbidden();

        $this->assertDatabaseCount('contractor_profiles', 0);
        Http::assertNothingSent();
    }

    public function test_return_refresh_updates_connected_account_status(): void
    {
        $user = $this->user('handyman', true);

        $profile = ContractorProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Ready Contractor',
            'status' => 'pending',
            'is_public' => false,
            'stripe_account_id' => 'acct_status_refresh',
        ]);

        Http::fake([
            'https://api.stripe.com/v1/accounts/acct_status_refresh' =>
                Http::response([
                    'id' => 'acct_status_refresh',
                    'object' => 'account',
                    'details_submitted' => true,
                    'charges_enabled' => true,
                    'payouts_enabled' => true,
                ]),
        ]);

        $this->withToken($this->token($user))
            ->json('POST', '/api/contractor/payout-account/refresh')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('id', $profile->id)
            ->assertJsonPath('stripe_details_submitted', true)
            ->assertJsonPath('stripe_charges_enabled', true)
            ->assertJsonPath('stripe_payouts_enabled', true);

        $this->assertDatabaseHas('contractor_profiles', [
            'id' => $profile->id,
            'stripe_details_submitted' => true,
            'stripe_charges_enabled' => true,
            'stripe_payouts_enabled' => true,
        ]);

        Http::assertSentCount(1);
    }

    public function test_untrusted_stripe_redirect_is_rejected(): void
    {
        $user = $this->user('handyman', true);

        Http::fake(function (ClientRequest $request) use ($user) {
            if ($request->url() === 'https://api.stripe.com/v1/accounts') {
                return Http::response([
                    'id' => 'acct_invalid_link_' . $user->id,
                    'object' => 'account',
                ]);
            }

            if (
                $request->url()
                    === 'https://api.stripe.com/v1/account_links'
            ) {
                return Http::response([
                    'object' => 'account_link',
                    'url' => 'https://attacker.example/onboarding',
                ]);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $this->withToken($this->token($user))
            ->postJson('/api/contractor/payout-account')
            ->assertStatus(502);
    }

    private function user(string $role, bool $verified): User
    {
        $user = new User();

        $user->forceFill([
            'name' => 'One Click Contractor',
            'email' =>
                'onboarding-'
                . bin2hex(random_bytes(6))
                . '@example.test',
            'password' => Hash::make('test-password'),
            'role' => $role,
            'email_verified_at' => $verified ? now() : null,
        ]);

        $user->save();

        return $user;
    }

    private function token(User $user): string
    {
        return (string) Auth::guard('api')->login($user);
    }
}