<?php

namespace App\Http\Controllers;

use App\Models\ContractorProfile;
use App\Models\Job;
use App\Models\Report;
use App\Models\User;
use App\Services\LifecycleNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function __construct(
        private readonly LifecycleNotificationService $notifications,
    ) {
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reported_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'contractor_profile_id' => ['nullable', 'integer', 'exists:contractor_profiles,id'],
            'job_id' => ['nullable', 'integer', 'exists:jobs,id'],
            'reason' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);

        if (
            empty($validated['reported_user_id']) &&
            empty($validated['contractor_profile_id']) &&
            empty($validated['job_id'])
        ) {
            return response()->json([
                'error' => 'A report must include a reported user, contractor profile, or job.',
            ], 422);
        }

        $user = Auth::guard('api')->user();

        if (!empty($validated['reported_user_id']) && (int) $validated['reported_user_id'] === $user->id) {
            return response()->json(['error' => 'You cannot report yourself'], 422);
        }

        $report = Report::create([
            'reporter_id' => $user->id,
            'reported_user_id' => $validated['reported_user_id'] ?? null,
            'contractor_profile_id' => $validated['contractor_profile_id'] ?? null,
            'job_id' => $validated['job_id'] ?? null,
            'reason' => $validated['reason'],
            'details' => $validated['details'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json($report->load(['reporter', 'reportedUser', 'contractorProfile', 'job']), 201);
    }

    public function myReports()
    {
        $user = Auth::guard('api')->user();

        $reports = Report::with(['reportedUser', 'contractorProfile', 'job', 'reviewer'])
            ->where('reporter_id', $user->id)
            ->latest()
            ->get();

        return response()->json($reports);
    }

    public function adminIndex(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'reviewing', 'resolved', 'dismissed'])],
            'reported_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'contractor_profile_id' => ['nullable', 'integer', 'exists:contractor_profiles,id'],
            'job_id' => ['nullable', 'integer', 'exists:jobs,id'],
        ]);

        $query = Report::with(['reporter', 'reportedUser', 'contractorProfile', 'job', 'reviewer']);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['reported_user_id'])) {
            $query->where('reported_user_id', $validated['reported_user_id']);
        }

        if (!empty($validated['contractor_profile_id'])) {
            $query->where('contractor_profile_id', $validated['contractor_profile_id']);
        }

        if (!empty($validated['job_id'])) {
            $query->where('job_id', $validated['job_id']);
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function adminUpdateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['reviewing', 'resolved', 'dismissed'])],
            'admin_notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $admin = Auth::guard('api')->user();
        $report = Report::findOrFail($id);

        $report->update([
            'status' => $validated['status'],
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => $validated['admin_notes'] ?? null,
        ]);

        return response()->json($report->fresh(['reporter', 'reportedUser', 'contractorProfile', 'job', 'reviewer']));
    }

    public function adminSuspendUser(Request $request, $id)
    {
        $validated = $request->validate([
            'account_status' => ['required', Rule::in(['active', 'suspended', 'banned'])],
            'suspension_reason' => ['nullable', 'string', 'max:3000'],
        ]);

        $admin = Auth::guard('api')->user();
        $user = User::findOrFail($id);
        $previousStatus = $user->account_status ?? 'active';

        if ($user->id === $admin->id) {
            return response()->json(['error' => 'You cannot change your own account status'], 422);
        }

        $user->update([
            'account_status' => $validated['account_status'],
            'suspended_at' => $validated['account_status'] === 'active' ? null : now(),
            'suspended_by' => $validated['account_status'] === 'active' ? null : $admin->id,
            'suspension_reason' => $validated['account_status'] === 'active'
                ? null
                : ($validated['suspension_reason'] ?? null),
        ]);

        if ($previousStatus !== $validated['account_status']) {
            $this->notifications->accountStatus(
                $user,
                $validated['account_status'],
                $validated['suspension_reason'] ?? null
            );
        }

        return response()->json($user);
    }

    public function adminUpdateContractorProfileStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['draft', 'pending', 'approved', 'rejected', 'suspended'])],
            'is_public' => ['nullable', 'boolean'],
        ]);

        $profile = ContractorProfile::findOrFail($id);
        $previousStatus = $profile->status;

        $profile->update([
            'status' => $validated['status'],
            'is_public' => $validated['is_public'] ?? $profile->is_public,
            'is_verified' => $validated['status'] === 'approved',
        ]);

        if ($validated['status'] === 'approved') {
            $profile->user()->update(['role' => 'handyman']);
        }

        if ($previousStatus !== $validated['status']) {
            $profile->load('user');

            if ($profile->user) {
                $this->notifications->contractorProfileStatus($profile->user, $validated['status']);
            }
        }

        return response()->json($profile);
    }
}
