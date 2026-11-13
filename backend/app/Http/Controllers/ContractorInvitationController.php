<?php

namespace App\Http\Controllers;

use App\Mail\ContractorInvitationMail;
use App\Models\ContractorInvitation;
use App\Models\ContractorProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ContractorInvitationController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:active,accepted,revoked,expired'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ContractorInvitation::query()->with('inviter:id,name,email');
        $status = $validated['status'] ?? null;
        if ($status === 'active') {
            $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
        } elseif ($status === 'accepted') {
            $query->whereNotNull('accepted_at');
        } elseif ($status === 'revoked') {
            $query->whereNotNull('revoked_at');
        } elseif ($status === 'expired') {
            $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<=', now());
        }

        return response()->json($query->latest()->paginate($validated['per_page'] ?? 25));
    }

    public function store(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'name' => ['nullable', 'string', 'max:191'],
            'business_name' => ['nullable', 'string', 'max:191'],
            'service_area' => ['nullable', 'string', 'max:191'],
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $rawToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $expiresAt = now()->addHours($validated['expires_in_hours'] ?? 168);

        $invite = DB::transaction(function () use ($validated, $rawToken, $expiresAt, $request) {
            ContractorInvitation::query()
                ->where('email', $validated['email'])
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now()]);

            return ContractorInvitation::create([
                'email' => $validated['email'],
                'name' => $validated['name'] ?? null,
                'business_name' => $validated['business_name'] ?? null,
                'service_area' => $validated['service_area'] ?? null,
                'token_hash' => ContractorInvitation::hashToken($rawToken),
                'invited_by' => $request->user('api')?->id,
                'expires_at' => $expiresAt,
            ]);
        });

        $inviteUrl = $this->frontendUrl('contractor_join.php', ['token' => $rawToken]);
        $mailQueued = false;
        try {
            Mail::to($invite->email)->queue(new ContractorInvitationMail(
                recipientName: (string) ($invite->name ?? ''),
                inviteUrl: $inviteUrl,
                expiresAt: $expiresAt->timezone('America/Denver')->format('M j, Y g:i A T'),
            ));
            $invite->forceFill(['sent_at' => now()])->save();
            $mailQueued = true;
        } catch (\Throwable $exception) {
            Log::error('Contractor invitation email could not be queued.', [
                'contractor_invitation_id' => $invite->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'invitation' => $invite->fresh(),
            // The raw URL is intentionally returned only on creation so an
            // administrator can deliver it through an approved channel if
            // SMTP is not yet configured in the test environment.
            'invite_url' => $inviteUrl,
            'email_queued' => $mailQueued,
        ], 201);
    }

    public function revoke(int $id)
    {
        $invite = ContractorInvitation::findOrFail($id);
        if ($invite->accepted_at !== null) {
            return response()->json([
                'message' => 'An accepted contractor invitation cannot be revoked.',
            ], 422);
        }

        if ($invite->revoked_at === null) {
            $invite->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json([
            'revoked' => true,
            'invitation' => $invite->fresh(),
        ]);
    }

    public function accept(Request $request)
    {
        $validated = $request->validate([
            'invitation_token' => ['required', 'string', 'min:32', 'max:128'],
        ]);

        $result = DB::transaction(function () use ($validated) {
            $invite = ContractorInvitation::query()
                ->where('token_hash', ContractorInvitation::hashToken($validated['invitation_token']))
                ->lockForUpdate()
                ->first();

            if (! $invite || ! $invite->isUsable()) {
                return [
                    'status' => 410,
                    'message' => 'This contractor invitation is invalid, expired, or already used.',
                ];
            }

            $user = User::query()->where('email', $invite->email)->lockForUpdate()->first();
            if ($user && ($user->account_status ?? 'active') !== 'active') {
                return [
                    'status' => 403,
                    'message' => 'This TrustFix account is not currently active. Contact TrustFix for assistance.',
                ];
            }
            if ($user && $user->hasRole('admin')) {
                return [
                    'status' => 422,
                    'message' => 'Administrator accounts cannot be converted through a contractor invitation.',
                ];
            }

            if (! $user) {
                // A brand-new invitee may start with contractor as primary.
                $user = User::create([
                    'name' => $invite->name ?: strstr($invite->email, '@', true),
                    'email' => $invite->email,
                    'password' => Hash::make(Str::random(64)),
                    'role' => 'handyman',
                    'account_status' => 'active',
                ]);
            } elseif ($user->hasRole('customer')) {
                // An existing homeowner keeps customer capability while the
                // contractor role becomes the legacy/default experience.
                $user->grantRole('handyman', true);
            }

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            ContractorProfile::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'business_name' => $invite->business_name ?: $user->name,
                    'service_area' => $invite->service_area,
                    'status' => 'pending',
                    'is_public' => false,
                ]
            );

            $invite->forceFill([
                'accepted_at' => now(),
                'accepted_user_id' => $user->id,
            ])->save();

            return [
                'status' => 201,
                'user' => $user->fresh(),
            ];
        });

        if ($result['status'] !== 201) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        /** @var User $user */
        $user = $result['user'];
        $token = Auth::guard('api')->login($user);

        return response()->json([
            'user' => $user,
            'token' => $token,
            'token_type' => 'bearer',
            'contractor_status' => 'pending',
            'message' => 'Your contractor account is active. Complete profile and document review before accepting jobs.',
        ], 201);
    }

    private function frontendUrl(string $path, array $query = []): string
    {
        $url = rtrim((string) config('trustfix.frontend_url'), '/').'/'.ltrim($path, '/');

        return $query
            ? $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            : $url;
    }
}
