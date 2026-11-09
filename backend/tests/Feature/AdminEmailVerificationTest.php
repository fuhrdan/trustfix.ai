<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_verify_and_unverify_another_user(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('customer', false);

        $verified = $this->updateVerification($admin, $target, true);
        $verified
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.id', $target->id);
        $this->assertIsString($verified->json('user.email_verified_at'));
        $this->assertNotNull($target->fresh()->email_verified_at);

        $unverified = $this->updateVerification($admin, $target, false);
        $unverified
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email_verified_at', null);
        $this->assertNull($target->fresh()->email_verified_at);
    }

    public function test_non_admin_cannot_change_email_verification(): void
    {
        $customer = $this->user('customer', true);
        $target = $this->user('customer', false);

        $this->updateVerification($customer, $target, true)
            ->assertForbidden();

        $this->assertNull($target->fresh()->email_verified_at);
    }

    public function test_current_admin_cannot_unverify_their_own_account(): void
    {
        $admin = $this->user('admin', true);

        $this->updateVerification($admin, $admin, false)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'You cannot mark the administrator account you are currently using as unverified.'
            );

        $this->assertNotNull($admin->fresh()->email_verified_at);
    }

    public function test_email_verification_value_must_be_boolean(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('customer', false);
        $payload = $this->payload($target, false);
        $payload['email_verified'] = 'not-a-boolean';

        $this->actingAs($admin, 'api')
            ->putJson("/api/admin/users/{$target->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email_verified');

        $this->assertNull($target->fresh()->email_verified_at);
    }

    public function test_existing_admin_updates_preserve_verification_when_field_is_omitted(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('customer', true);
        $payload = $this->payload($target, true);
        unset($payload['email_verified']);

        $this->actingAs($admin, 'api')
            ->putJson("/api/admin/users/{$target->id}", $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($target->fresh()->email_verified_at);
    }

    private function updateVerification(User $actor, User $target, bool $verified)
    {
        return $this->actingAs($actor, 'api')->putJson(
            "/api/admin/users/{$target->id}",
            $this->payload($target, $verified)
        );
    }

    private function payload(User $target, bool $verified): array
    {
        return [
            'name' => $target->name,
            'email' => $target->email,
            'phone' => $target->phone,
            'address' => $target->address,
            'role' => $target->role,
            'email_verified' => $verified,
        ];
    }

    private function user(string $role, bool $verified): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Verification Test User',
            'email' => 'verification-'.Str::lower(Str::random(20)).'@example.com',
            'password' => Hash::make('verification-test-password'),
            'role' => $role,
            'account_status' => 'active',
            'email_verified_at' => $verified ? now() : null,
        ]);
        $user->save();

        return $user;
    }
}
