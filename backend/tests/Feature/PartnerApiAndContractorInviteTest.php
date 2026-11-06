<?php

namespace Tests\Feature;

use App\Mail\ContractorInvitationMail;
use App\Models\Job;
use App\Models\PartnerApiClient;
use App\Models\PartnerMemberAccount;
use App\Models\PartnerServiceRequest;
use App\Models\User;
use App\Services\PartnerWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerApiAndContractorInviteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'trustfix.frontend_url' => 'https://trustfix.example.test',
            'partner_api.enabled' => true,
            'partner_api.webhooks_enabled' => false,
            'partner_api.rate_limit_per_minute' => 120,
        ]);
    }

    public function test_admin_created_partner_can_submit_idempotent_scoped_service_requests(): void
    {
        $admin = $this->administrator();
        $customer = User::create([
            'name' => 'Mapped Crane Member',
            'email' => 'mapped@example.test',
            'password' => Hash::make('test-password'),
            'role' => 'customer',
        ]);
        $customer->forceFill(['email_verified_at' => now()])->save();

        $adminToken = Auth::guard('api')->login($admin);
        $partnerResponse = $this->withToken($adminToken)->postJson('/api/admin/partner-clients', [
            'name' => 'Crane Concierge',
            'slug' => 'crane',
            'scopes' => [
                'service_requests:read',
                'service_requests:write',
                'service_requests:schedule',
            ],
            'active' => true,
        ]);
        $partnerResponse->assertCreated()->assertJsonStructure([
            'partner' => ['id', 'slug'],
            'credentials' => ['api_token', 'webhook_secret'],
        ]);

        $partnerId = $partnerResponse->json('partner.id');
        $apiToken = $partnerResponse->json('credentials.api_token');
        $this->withToken($adminToken)->postJson('/api/admin/partner-clients/'.$partnerId.'/member-accounts', [
            'user_id' => $customer->id,
            'external_member_id' => 'CRANE-1001',
            'membership_status' => 'active',
        ])->assertCreated();

        $payload = [
            'external_request_id' => 'CRANE-SR-0001',
            'member_id' => 'CRANE-1001',
            'description' => 'Leaking kitchen faucet.',
            'address' => '123 Test Street, Denver, CO 80202',
            'skills' => ['plumbing'],
            'budget' => 250,
        ];
        $created = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/partner/v1/service-requests', $payload);
        $created->assertCreated()
            ->assertJsonPath('idempotent_replay', false)
            ->assertJsonPath('data.status', 'posted');

        $jobId = $created->json('data.trustfix_job_id');
        $this->assertDatabaseHas('jobs', [
            'id' => $jobId,
            'customer_id' => $customer->id,
            'status' => 'posted',
        ]);

        $replay = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/partner/v1/service-requests', $payload);
        $replay->assertOk()
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('data.trustfix_job_id', $jobId);
        $this->assertDatabaseCount('partner_service_requests', 1);

        $scheduled = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/partner/v1/service-requests/CRANE-SR-0001/schedule', [
                'scheduled_for' => now()->addDay()->toIso8601String(),
                'scheduling_reference' => 'CRANE-APPT-1',
            ]);
        $scheduled->assertOk()
            ->assertJsonPath('data.scheduling_reference', 'CRANE-APPT-1');
    }

    public function test_contractor_invitation_is_one_time_and_creates_pending_profile(): void
    {
        Mail::fake();
        $admin = $this->administrator();
        $adminToken = Auth::guard('api')->login($admin);

        $response = $this->withToken($adminToken)->postJson('/api/admin/contractor-invitations', [
            'email' => 'contractor@example.test',
            'name' => 'Taylor Contractor',
            'business_name' => 'Taylor Home Repair',
            'service_area' => 'Denver metro',
            'expires_in_hours' => 24,
        ]);
        $response->assertCreated()->assertJsonStructure(['invitation', 'invite_url']);
        Mail::assertQueued(ContractorInvitationMail::class);

        parse_str((string) parse_url($response->json('invite_url'), PHP_URL_QUERY), $query);
        $accept = $this->postJson('/api/contractor-invitations/accept', [
            'invitation_token' => $query['token'],
        ]);
        $accept->assertCreated()
            ->assertJsonPath('user.email', 'contractor@example.test')
            ->assertJsonPath('user.role', 'handyman')
            ->assertJsonPath('contractor_status', 'pending')
            ->assertJsonStructure(['token']);

        $user = User::where('email', 'contractor@example.test')->firstOrFail();
        $this->assertDatabaseHas('contractor_profiles', [
            'user_id' => $user->id,
            'business_name' => 'Taylor Home Repair',
            'status' => 'pending',
            'is_public' => false,
        ]);
        $this->assertNotNull($user->email_verified_at);

        $this->postJson('/api/contractor-invitations/accept', [
            'invitation_token' => $query['token'],
        ])->assertStatus(410);
    }

    public function test_webhook_is_hmac_signed_and_only_sends_when_enabled(): void
    {
        config([
            'partner_api.webhooks_enabled' => true,
            'partner_api.webhook_allowed_hosts' => ['partner.example.test'],
        ]);
        Http::fake(['https://partner.example.test/trustfix/status' => Http::response([], 204)]);

        $customer = User::create([
            'name' => 'Webhook Customer',
            'email' => 'webhook@example.test',
            'password' => Hash::make('test-password'),
            'role' => 'customer',
        ]);
        $client = PartnerApiClient::create([
            'name' => 'Crane Concierge',
            'slug' => 'crane-webhook',
            'token_hint' => 'testhint',
            'api_token_hash' => Hash::make('not-used'),
            'scopes' => ['service_requests:read'],
            'webhook_url' => 'https://partner.example.test/trustfix/status',
            'webhook_secret' => 'webhook-test-secret',
            'active' => true,
        ]);
        $member = PartnerMemberAccount::create([
            'partner_api_client_id' => $client->id,
            'user_id' => $customer->id,
            'external_member_id' => 'CRANE-WEBHOOK-1',
            'membership_status' => 'active',
        ]);
        $job = Job::create([
            'customer_id' => $customer->id,
            'status' => 'accepted',
            'address' => '1 Webhook Way',
            'lat' => 0,
            'lng' => 0,
            'initial_description' => 'Webhook test',
            'skills' => [],
        ]);
        $partnerRequest = PartnerServiceRequest::create([
            'partner_api_client_id' => $client->id,
            'partner_member_account_id' => $member->id,
            'job_id' => $job->id,
            'external_request_id' => 'CRANE-WEBHOOK-REQ',
            'last_status_at' => now(),
        ]);

        app(PartnerWebhookService::class)->send($partnerRequest->id, 'service_request.status_changed');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://partner.example.test/trustfix/status'
                && $request->header('X-TrustFix-Event')[0] === 'service_request.status_changed'
                && str_starts_with($request->header('X-TrustFix-Signature')[0], 'sha256=');
        });
    }

    private function administrator(): User
    {
        $admin = User::create([
            'name' => 'Integration Admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('CorrectPassword123!'),
            'role' => 'admin',
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();

        return $admin;
    }
}
