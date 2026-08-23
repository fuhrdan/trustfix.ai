<?php

namespace Tests\Feature;

use App\Models\BlockedIp;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_login_is_recorded(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'security@example.com',
            'password' => Hash::make('CorrectPassword123!'),
            'role' => 'customer',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(401);
        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => '203.0.113.20',
            'successful' => false,
            'outcome' => 'invalid_credentials',
        ]);
    }

    public function test_successful_login_is_recorded(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'success@example.com',
            'password' => Hash::make('CorrectPassword123!'),
            'role' => 'customer',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'CorrectPassword123!',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'ip_address' => '203.0.113.21',
            'successful' => true,
            'outcome' => 'success',
        ]);
    }

    public function test_blocked_ip_is_rejected_before_authentication(): void
    {
        BlockedIp::create([
            'ip_address' => '203.0.113.22',
            'reason' => 'Test block',
            'blocked_at' => now(),
            'blocked_until' => now()->addDay(),
            'active' => true,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.22'])
            ->postJson('/api/login', [
                'email' => 'someone@example.com',
                'password' => 'anything',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('login_attempts', [
            'ip_address' => '203.0.113.22',
            'successful' => false,
            'outcome' => 'blocked_ip',
        ]);
    }

    public function test_risk_becomes_high_after_repeated_failures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            LoginAttempt::create([
                'email' => 'target@example.com',
                'ip_address' => '203.0.113.23',
                'successful' => false,
                'outcome' => 'invalid_credentials',
                'risk_level' => 'normal',
                'risk_score' => 10,
                'recent_ip_failures' => $i + 1,
                'targeted_accounts' => 1,
                'created_at' => now(),
            ]);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.23'])
            ->postJson('/api/login', [
                'email' => 'another@example.com',
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(401);
        $latest = LoginAttempt::latest('id')->first();
        $this->assertSame('high', $latest->risk_level);
    }
}
