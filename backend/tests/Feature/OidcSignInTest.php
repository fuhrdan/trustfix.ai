<?php

namespace Tests\Feature;

use App\Models\PartnerApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OidcSignInTest extends TestCase
{
    use RefreshDatabase;

    private string $issuer = 'https://idp.crane.test';

    private string $privateKey = '';

    private ?string $currentNonce = null;

    /** @var array<string, string> */
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $this->privateKey);
        $details = openssl_pkey_get_details($resource);
        $this->jwk = [
            'kty' => 'RSA',
            'kid' => 'crane-test-key',
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ];

        config([
            'trustfix.frontend_url' => 'https://trustfix.example.test',
            'identity.oidc.enabled' => true,
            'identity.oidc.state_ttl_seconds' => 600,
            'identity.oidc.completion_ttl_seconds' => 300,
            'identity.oidc.providers.crane' => [
                'enabled' => true,
                'display_name' => 'Crane',
                'issuer' => $this->issuer,
                'client_id' => 'trustfix-test-client',
                'client_secret' => 'test-client-secret',
                'redirect_uri' => 'https://api.trustfix.example.test/auth/oidc/crane/callback',
                'scopes' => ['openid', 'profile', 'email'],
                'required_claim' => 'membership.active',
                'required_claim_value' => 'true',
                'member_id_claim' => 'member_id',
                'partner_slug' => 'crane',
                'require_verified_email' => true,
                'allowed_signing_algorithms' => ['RS256'],
                'token_endpoint_auth_method' => 'client_secret_basic',
            ],
        ]);
    }

    public function test_verified_crane_member_is_created_linked_and_signed_in(): void
    {
        $partner = PartnerApiClient::create([
            'name' => 'Crane Concierge',
            'slug' => 'crane',
            'token_hint' => 'testhint',
            'api_token_hash' => Hash::make('not-used-in-this-test'),
            'scopes' => ['service_requests:read'],
            'active' => true,
        ]);

        $completionToken = $this->completeCallback('member@example.test', 'crane-member-42');

        $response = $this->postJson('/api/auth/oidc/complete', [
            'completion_token' => $completionToken,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'member@example.test')
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonStructure(['token', 'token_type']);

        $user = User::where('email', 'member@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('oidc_identities', [
            'user_id' => $user->id,
            'provider' => 'crane',
            'issuer' => $this->issuer,
            'subject' => 'crane-member-42',
        ]);
        $this->assertDatabaseHas('partner_member_accounts', [
            'partner_api_client_id' => $partner->id,
            'user_id' => $user->id,
            'external_member_id' => 'CRANE-0042',
            'membership_status' => 'active',
        ]);
    }

    public function test_existing_matching_account_requires_authenticated_linking(): void
    {
        $user = User::create([
            'name' => 'Existing Member',
            'email' => 'existing@example.test',
            'password' => Hash::make('CorrectPassword123!'),
            'role' => 'customer',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $linkToken = $this->completeCallback('existing@example.test', 'crane-existing-77', true);

        $this->assertDatabaseCount('oidc_identities', 0);

        $jwt = Auth::guard('api')->login($user);
        $response = $this->withToken($jwt)->postJson('/api/auth/oidc/link', [
            'link_token' => $linkToken,
        ]);

        $response->assertOk()->assertJsonPath('linked', true);
        $this->assertDatabaseHas('oidc_identities', [
            'user_id' => $user->id,
            'provider' => 'crane',
            'subject' => 'crane-existing-77',
        ]);
    }

    public function test_callback_rejects_an_invalid_state_without_creating_an_account(): void
    {
        $this->fakeProvider('unused@example.test', 'unused-subject');
        $start = $this->get('/auth/oidc/crane');
        $start->assertRedirect();

        $response = $this->get('/auth/oidc/crane/callback?code=test-code&state=wrong-state');

        $response->assertRedirectContains('login.php?oidc_error=');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('oidc_identities', 0);
    }

    private function completeCallback(string $email, string $subject, bool $link = false): string
    {
        $this->currentNonce = null;
        $this->fakeProvider($email, $subject);
        $start = $this->get('/auth/oidc/crane');
        $start->assertRedirect();

        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('state', $query);
        $nonce = session('oidc.pending.crane.nonce');
        $this->assertIsString($nonce);

        $this->currentNonce = $nonce;
        $callback = $this->get('/auth/oidc/crane/callback?'.http_build_query([
            'code' => 'authorisation-code',
            'state' => $query['state'],
        ]));
        $callback->assertRedirect();

        $location = $callback->headers->get('Location');
        $expectedPath = $link ? '/oidc_link.php' : '/oidc_complete.php';
        $this->assertStringContainsString($expectedPath.'?token=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $resultQuery);
        $this->assertArrayHasKey('token', $resultQuery);

        return (string) $resultQuery['token'];
    }

    private function fakeProvider(string $email, string $subject): void
    {
        Http::fake([
            $this->issuer.'/.well-known/openid-configuration' => Http::response([
                'issuer' => $this->issuer,
                'authorization_endpoint' => $this->issuer.'/authorize',
                'token_endpoint' => $this->issuer.'/token',
                'jwks_uri' => $this->issuer.'/keys',
                'userinfo_endpoint' => $this->issuer.'/userinfo',
            ]),
            $this->issuer.'/token' => function () use ($email, $subject) {
                return Http::response([
                    'access_token' => 'test-access-token',
                    'token_type' => 'Bearer',
                    'id_token' => $this->signedJwt([
                        'iss' => $this->issuer,
                        'sub' => $subject,
                        'aud' => 'trustfix-test-client',
                        'exp' => now()->addMinutes(5)->timestamp,
                        'iat' => now()->timestamp,
                        'nonce' => $this->currentNonce,
                        'email' => $email,
                        'email_verified' => true,
                        'name' => 'Crane Test Member',
                        'membership' => ['active' => 'true'],
                        'member_id' => 'CRANE-0042',
                    ]),
                ]);
            },
            $this->issuer.'/keys' => Http::response(['keys' => [$this->jwk]]),
            $this->issuer.'/userinfo' => Http::response([
                'sub' => $subject,
                'email' => $email,
                'email_verified' => true,
                'name' => 'Crane Test Member',
                'membership' => ['active' => 'true'],
                'member_id' => 'CRANE-0042',
            ]),
        ]);
    }

    /** @param array<string,mixed> $claims */
    private function signedJwt(array $claims): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => 'crane-test-key',
        ], JSON_THROW_ON_ERROR));
        $payload = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        openssl_sign($header.'.'.$payload, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $header.'.'.$payload.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
