<?php

namespace App\Http\Controllers;

use App\Models\BlockedIp;
use App\Models\LoginAttempt;
use App\Services\LoginSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class LoginSecurityController extends Controller
{
    public function __construct(
        private readonly LoginSecurityService $loginSecurity,
    ) {
    }

    public function summary()
    {
        $since24Hours = now()->subHours(24);
        $sinceHour = now()->subHour();

        return response()->json([
            'attempts_24h' => LoginAttempt::where('created_at', '>=', $since24Hours)->count(),
            'successful_24h' => LoginAttempt::where('successful', true)->where('created_at', '>=', $since24Hours)->count(),
            'failed_24h' => LoginAttempt::where('successful', false)->where('created_at', '>=', $since24Hours)->count(),
            'failed_1h' => LoginAttempt::where('successful', false)->where('created_at', '>=', $sinceHour)->count(),
            'high_risk_24h' => LoginAttempt::where('risk_level', 'high')->where('created_at', '>=', $since24Hours)->count(),
            'unique_ips_24h' => LoginAttempt::where('created_at', '>=', $since24Hours)->distinct()->count('ip_address'),
            'active_blocks' => BlockedIp::where('active', true)
                ->where(function ($query): void {
                    $query->whereNull('blocked_until')->orWhere('blocked_until', '>', now());
                })
                ->count(),
            'retention_days' => (int) config('login_security.retention_days', 90),
        ]);
    }

    public function attempts(Request $request)
    {
        $validated = $request->validate([
            'ip' => ['nullable', 'ip'],
            'email' => ['nullable', 'string', 'max:255'],
            'result' => ['nullable', Rule::in(['success', 'failure'])],
            'risk' => ['nullable', Rule::in(['normal', 'elevated', 'high'])],
            'outcome' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = LoginAttempt::with('user:id,name,email,role')->latest('id');

        if (!empty($validated['ip'])) {
            $query->where('ip_address', $validated['ip']);
        }

        if (!empty($validated['email'])) {
            $query->where('email', 'like', '%'.$validated['email'].'%');
        }

        if (($validated['result'] ?? null) === 'success') {
            $query->where('successful', true);
        } elseif (($validated['result'] ?? null) === 'failure') {
            $query->where('successful', false);
        }

        if (!empty($validated['risk'])) {
            $query->where('risk_level', $validated['risk']);
        }

        if (!empty($validated['outcome'])) {
            $query->where('outcome', $validated['outcome']);
        }

        if (!empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }

        if (!empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to']);
        }

        return response()->json($query->paginate(50));
    }

    public function blockedIps()
    {
        $blocks = BlockedIp::with([
            'administrator:id,name,email',
            'unblockedByAdministrator:id,name,email',
        ])->latest('id')->paginate(50);

        return response()->json($blocks);
    }

    public function block(Request $request)
    {
        $validated = $request->validate([
            'ip_address' => ['required', 'ip'],
            'duration' => ['required', Rule::in(['1h', '24h', '7d', 'permanent'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $administrator = Auth::guard('api')->user();
        $ipAddress = $validated['ip_address'];

        if (hash_equals($this->loginSecurity->clientIp($request), $ipAddress)) {
            return response()->json([
                'message' => 'You cannot block the IP address you are currently using.',
            ], 422);
        }

        $blockedUntil = match ($validated['duration']) {
            '1h' => now()->addHour(),
            '24h' => now()->addDay(),
            '7d' => now()->addDays(7),
            'permanent' => null,
        };

        $block = BlockedIp::updateOrCreate(
            ['ip_address' => $ipAddress],
            [
                'reason' => $validated['reason'] ?? null,
                'blocked_by' => $administrator->id,
                'blocked_at' => now(),
                'blocked_until' => $blockedUntil,
                'active' => true,
                'unblocked_at' => null,
                'unblocked_by' => null,
            ]
        );

        return response()->json([
            'message' => 'IP address blocked.',
            'block' => $block->fresh('administrator:id,name,email'),
        ], 201);
    }

    public function unblock(Request $request, $id)
    {
        $administrator = Auth::guard('api')->user();
        $block = BlockedIp::findOrFail($id);

        $block->update([
            'active' => false,
            'unblocked_at' => now(),
            'unblocked_by' => $administrator->id,
        ]);

        return response()->json([
            'message' => 'IP address unblocked.',
            'block' => $block->fresh([
                'administrator:id,name,email',
                'unblockedByAdministrator:id,name,email',
            ]),
        ]);
    }
}
