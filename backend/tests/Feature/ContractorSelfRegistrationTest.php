<?php

namespace Tests\Feature;

use App\Models\ContractorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContractorSelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake();

        config([
            'app.env' => 'testing',
            'payments.enabled' => true,
            'payments.test_mode_only' => true,
            'payments.releases_enabled' => true,
            'payments.refunds_enabled' => true,
            'payments.auto_release_enabled' => false,
            'services.stripe.key' => 'pk_test_self_registration',
            'services.stripe.secret' => 'sk_test_self_registration',
            'services.stripe.webhook_secret' => 'whsec_self_registration',
            'services.stripe.frontend_url' => 'https://trustfix.example.test',
        ]);
    }

    public function test_contractor_registration_creates_a_pending_nonpublic_profile(): void
    {
        $response = $this->postJson(
            '/api/register',
            $this->contractorPayload([
                'role' => 'admin',
            ])
        );

        $response->assertCreated()
            ->assertJsonPath('user.email', 'new-contractor@example.test')
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonPath('registration_type', 'contractor')
            ->assertJsonPath('contractor_status', 'pending')
            ->assertJsonPath('requires_email_verification', true);

        $this->assertNull($response->json('token'));

        $user = User::where('email', 'new-contractor@example.test')
            ->firstOrFail();

        $this->assertSame('customer', $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('contractor_profiles', [
            'user_id' => $user->id,
            'business_name' => 'Safe Home Repair',
            'business_phone' => '303-555-0100',
            'service_area' => 'Denver metro',
            'phone' => '303-555-0100',
            'status' => 'pending',
            'is_public' => false,
            'is_verified' => false,
        ]);
    }

    public function test_standard_registration_remains_a_customer_without_a_profile(): void
    {
        $payload = $this->contractorPayload([
            'email' => 'homeowner@example.test',
            'account_type' => 'customer',
            'business_name' => null,
            'service_area' => null,
        ]);

        $this->postJson('/api/register', $payload)
            ->assertCreated()
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonPath('registration_type', 'customer')
            ->assertJsonPath('contractor_status', null);

        $this->assertDatabaseHas('users', [
            'email' => 'homeowner@example.test',
            'role' => 'customer',
        ]);
        $this->assertDatabaseCount('contractor_profiles', 0);
    }

    public function test_invalid_account_type_is_rejected_without_creating_an_account(): void
    {
        $payload = $this->contractorPayload([
            'email' => 'invalid-role@example.test',
            'account_type' => 'admin',
        ]);

        $this->postJson('/api/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_type');

        $this->assertDatabaseMissing('users', [
            'email' => 'invalid-role@example.test',
        ]);
        $this->assertDatabaseCount('contractor_profiles', 0);
    }

    public function test_contractor_registration_requires_business_and_service_area(): void
    {
        $payload = $this->contractorPayload([
            'email' => 'missing-profile@example.test',
        ]);
        unset($payload['business_name'], $payload['service_area']);

        $this->postJson('/api/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'business_name',
                'service_area',
            ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'missing-profile@example.test',
        ]);
    }

    public function test_pending_contractor_has_no_job_or_stripe_access_until_approval(): void
    {
        $this->postJson('/api/register', $this->contractorPayload())
            ->assertCreated();

        $user = User::where('email', 'new-contractor@example.test')
            ->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        $pendingToken = (string) Auth::guard('api')->login($user->fresh());

        $this->withToken($pendingToken)
            ->getJson('/api/jobs/available')
            ->assertForbidden();
        $this->withToken($pendingToken)
            ->getJson('/api/contractor/dashboard')
            ->assertForbidden();
        $this->withToken($pendingToken)
            ->postJson('/api/contractor/payout-account')
            ->assertForbidden();
        Http::assertNothingSent();

        $admin = $this->administrator();
        $profile = ContractorProfile::where('user_id', $user->id)
            ->firstOrFail();

        $this->actingAs($admin, 'api')
            ->postJson(
                "/api/admin/contractor-profiles/{$profile->id}/status",
                [
                    'status' => 'approved',
                    'is_public' => false,
                ]
            )
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('is_verified', true);

        $this->assertSame('handyman', $user->fresh()->role);
        $this->assertDatabaseHas('contractor_profiles', [
            'id' => $profile->id,
            'status' => 'approved',
            'is_public' => false,
            'is_verified' => true,
        ]);

        Auth::forgetGuards();
        $approvedToken = (string) Auth::guard('api')->login($user->fresh());

        $this->withToken($approvedToken)
            ->getJson('/api/jobs/available')
            ->assertOk();
        Http::assertNothingSent();
    }

    public function test_frontend_exposes_guarded_customer_and_contractor_entries(): void
    {
        $frontend = dirname(base_path()).'/frontend';
        $registerPath = $frontend.'/register.php';
        $entryScriptPath = $frontend.'/contractor_registration_entry.js';
        $homepagePath = $frontend.'/index.php';

        $this->assertFileExists($registerPath);
        $this->assertFileExists($entryScriptPath);
        $this->assertFileExists($homepagePath);

        $register = file_get_contents($registerPath);
        $entryScript = file_get_contents($entryScriptPath);
        $homepage = file_get_contents($homepagePath);

        $this->assertIsString($register);
        $this->assertIsString($entryScript);
        $this->assertIsString($homepage);
        $this->assertStringContainsString('name="account_type"', $register);
        $this->assertStringContainsString('name="business_name"', $register);
        $this->assertStringContainsString('name="service_area"', $register);
        $this->assertStringContainsString(
            'register.php?account=contractor',
            $entryScript
        );
        $this->assertStringContainsString(
            'contractor_registration_entry.js?v=20261110',
            $homepage
        );
    }

    private function contractorPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Taylor Contractor',
            'email' => 'new-contractor@example.test',
            'phone' => '303-555-0100',
            'password' => 'CorrectPassword123!',
            'password_confirmation' => 'CorrectPassword123!',
            'account_type' => 'contractor',
            'business_name' => 'Safe Home Repair',
            'service_area' => 'Denver metro',
        ], $overrides);
    }

    private function administrator(): User
    {
        $admin = User::create([
            'name' => 'Registration Admin',
            'email' => 'registration-admin@example.test',
            'password' => Hash::make('CorrectPassword123!'),
            'role' => 'admin',
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();

        return $admin;
    }
}
