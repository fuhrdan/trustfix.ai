<?php

namespace App\Http\Controllers;

use App\Models\ContractorProfile;
use App\Models\Job;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContractorDashboardController extends Controller
{
    public function show(Request $request)
    {
        $user = Auth::guard('api')->user();
        $validated = $request->validate([
            'contractor_profile_id' => ['nullable', 'integer'],
        ]);

        if ($user->role === 'admin' && !empty($validated['contractor_profile_id'])) {
            $profile = ContractorProfile::where('status', 'approved')
                ->find($validated['contractor_profile_id']);
        } else {
            $profile = ContractorProfile::where('user_id', $user->id)->first();
        }

        if (!$profile || $profile->status !== 'approved') {
            return response()->json([
                'error' => $user->role === 'admin'
                    ? 'Select an approved contractor.'
                    : 'Contractor approval is required.',
                'approval_status' => $profile->status ?? 'not_submitted',
            ], 403);
        }

        $contractorUserId = $profile->user_id;

        $jobs = Job::with(['customer:id,name', 'images', 'payments'])
            ->where('handyman_id', $contractorUserId)
            ->latest('updated_at')
            ->get();

        $succeeded = Payment::where('contractor_id', $contractorUserId)
            ->where('status', 'succeeded');

        $grossCents = (clone $succeeded)->sum('amount_cents');
        $feesCents = (clone $succeeded)->sum('platform_fee_cents');
        $monthly = (clone $succeeded)
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as month")
            ->selectRaw('SUM(amount_cents - platform_fee_cents) as net_cents')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', now()->subMonths(11)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'profile' => $profile,
            'viewing_as_admin' => $user->role === 'admin',
            'summary' => [
                'total_jobs' => $jobs->count(),
                'active_jobs' => $jobs->whereIn('status', ['accepted', 'scheduled', 'in_progress'])->count(),
                'completed_jobs' => $jobs->where('status', 'completed')->count(),
                'gross_earnings_cents' => $grossCents,
                'platform_fees_cents' => $feesCents,
                'net_earnings_cents' => $grossCents - $feesCents,
            ],
            'jobs' => $jobs,
            'monthly_earnings' => $monthly,
            'payouts' => [
                'details_submitted' => (bool)$profile->stripe_details_submitted,
                'charges_enabled' => (bool)$profile->stripe_charges_enabled,
                'payouts_enabled' => (bool)$profile->stripe_payouts_enabled,
            ],
        ]);
    }
}
