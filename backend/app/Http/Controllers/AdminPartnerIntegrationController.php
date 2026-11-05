<?php

namespace App\Http\Controllers;

use App\Models\PartnerApiClient;
use App\Models\PartnerMemberAccount;
use App\Services\PartnerWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminPartnerIntegrationController extends Controller
{
    public function __construct(private readonly PartnerWebhookService $webhooks) {}

    public function index()
    {
        return response()->json(
            PartnerApiClient::query()
                ->withCount(['memberAccounts', 'serviceRequests'])
                ->latest()
                ->get()
                ->map(fn (PartnerApiClient $client) => $this->present($client))
        );
    }

    public function store(Request $request)
    {
        $validated = $this->validateClient($request);
        $apiToken = $this->newApiTokenPlaceholder();
        $webhookSecret = $this->newSecret();

        $client = PartnerApiClient::create([
            'name' => $validated['name'],
            'slug' => strtolower($validated['slug']),
            'token_hint' => substr($apiToken, -8),
            'api_token_hash' => Hash::make($apiToken),
            'scopes' => $validated['scopes'],
            'webhook_url' => $validated['webhook_url'] ?? null,
            'webhook_secret' => $webhookSecret,
            'active' => $validated['active'] ?? true,
            'created_by' => $request->user('api')?->id,
        ]);

        // Prefix the token with the generated client ID so the middleware can
        // identify one password hash to verify without scanning every client.
        $apiToken = $this->newApiToken($client);
        $client->forceFill([
            'token_hint' => substr($apiToken, -8),
            'api_token_hash' => Hash::make($apiToken),
        ])->save();

        return response()->json([
            'partner' => $this->present($client),
            'credentials' => [
                'client_id' => $client->id,
                'api_token' => $apiToken,
                'webhook_secret' => $webhookSecret,
            ],
            'message' => 'Save the API token and webhook secret now. They are not shown again.',
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $client = PartnerApiClient::findOrFail($id);
        $validated = $this->validateClient($request, $client);

        $client->fill([
            'name' => $validated['name'],
            'slug' => strtolower($validated['slug']),
            'scopes' => $validated['scopes'],
            'webhook_url' => $validated['webhook_url'] ?? null,
            'active' => $validated['active'] ?? $client->active,
        ])->save();

        return response()->json(['partner' => $this->present($client->fresh())]);
    }

    public function rotateApiToken(string $id)
    {
        $client = PartnerApiClient::findOrFail($id);
        $apiToken = $this->newApiToken($client);
        $client->forceFill([
            'token_hint' => substr($apiToken, -8),
            'api_token_hash' => Hash::make($apiToken),
        ])->save();

        return response()->json([
            'client_id' => $client->id,
            'api_token' => $apiToken,
            'message' => 'Save the replacement API token now. The previous token is revoked.',
        ]);
    }

    public function rotateWebhookSecret(string $id)
    {
        $client = PartnerApiClient::findOrFail($id);
        $secret = $this->newSecret();
        $client->forceFill(['webhook_secret' => $secret])->save();

        return response()->json([
            'client_id' => $client->id,
            'webhook_secret' => $secret,
            'message' => 'Save the replacement webhook secret now. The previous secret is revoked.',
        ]);
    }

    public function memberAccounts(string $id)
    {
        $client = PartnerApiClient::findOrFail($id);

        return response()->json(
            $client->memberAccounts()
                ->with('user:id,name,email,account_status')
                ->latest()
                ->paginate(100)
        );
    }

    public function storeMemberAccount(Request $request, string $id)
    {
        $client = PartnerApiClient::findOrFail($id);
        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'external_member_id' => ['required', 'string', 'max:191'],
            'membership_status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $existing = PartnerMemberAccount::query()
            ->where('partner_api_client_id', $client->id)
            ->where('external_member_id', $validated['external_member_id'])
            ->first();
        if ($existing && (int) $existing->user_id !== (int) $validated['user_id']) {
            return response()->json([
                'message' => 'That partner member ID is already linked to another TrustFix account.',
            ], 409);
        }

        $membership = PartnerMemberAccount::updateOrCreate(
            [
                'partner_api_client_id' => $client->id,
                'external_member_id' => $validated['external_member_id'],
            ],
            [
                'user_id' => $validated['user_id'],
                'membership_status' => $validated['membership_status'] ?? 'active',
                'last_confirmed_at' => now(),
            ]
        );

        return response()->json([
            'member_account' => $membership->load('user:id,name,email,account_status'),
        ], $existing ? 200 : 201);
    }

    /** @return array<string,mixed> */
    private function validateClient(Request $request, ?PartnerApiClient $client = null): array
    {
        $allowedScopes = (array) config('partner_api.allowed_scopes', []);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('partner_api_clients', 'slug')->ignore($client?->id),
            ],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in($allowedScopes)],
            'webhook_url' => ['nullable', 'url', 'max:2048'],
            'active' => ['nullable', 'boolean'],
        ]);

        if (! empty($validated['webhook_url']) && ! $this->webhooks->isSafeWebhookUrl($validated['webhook_url'])) {
            abort(422, 'The webhook URL must be a permitted public HTTPS endpoint.');
        }

        $validated['scopes'] = array_values(array_unique($validated['scopes']));

        return $validated;
    }

    private function newApiTokenPlaceholder(): string
    {
        return 'tfp_pending_'.$this->newSecret();
    }

    private function newApiToken(PartnerApiClient $client): string
    {
        return 'tfp_'.$client->id.'_'.$this->newSecret();
    }

    private function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
    }

    /** @return array<string,mixed> */
    private function present(PartnerApiClient $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'slug' => $client->slug,
            'token_hint' => $client->token_hint,
            'scopes' => $client->scopes,
            'webhook_url' => $client->webhook_url,
            'active' => $client->active,
            'last_used_at' => $client->last_used_at?->toIso8601String(),
            'member_accounts_count' => $client->member_accounts_count,
            'service_requests_count' => $client->service_requests_count,
            'created_at' => $client->created_at?->toIso8601String(),
        ];
    }
}
