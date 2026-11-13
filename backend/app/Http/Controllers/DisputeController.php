<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\Job;
use App\Services\PaymentAgreementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DisputeController extends Controller
{
    public function store(Request $request, $jobId)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);

        $user = Auth::guard('api')->user();

        return DB::transaction(function () use ($jobId, $user, $validated) {
            $job = Job::query()->lockForUpdate()->findOrFail($jobId);

            $isCustomer = $job->customer_id === $user->id;
            $isAssignedHandyman = $job->handyman_id === $user->id;

            if (!$isCustomer && !$isAssignedHandyman) {
                return response()->json(['error' => 'Forbidden'], 403);
            }

            $reserved = app(PaymentAgreementService::class)->jobHasReservedPayable((int) $job->id);
            if ($job->status === 'cancelled' || ($job->status === 'completed' && !$reserved)) {
                return response()->json(['error' => 'This job can no longer be disputed'], 409);
            }

            $dispute = Dispute::updateOrCreate(
                [
                    'job_id' => $job->id,
                    'opened_by' => $user->id,
                ],
                [
                    'reason' => $validated['reason'],
                    'details' => $validated['details'] ?? null,
                    'status' => 'open',
                    'resolved_by' => null,
                    'resolved_at' => null,
                    'resolution_notes' => null,
                ]
            );

            if (!$reserved) {
                $job->update(['status' => 'disputed']);
            }

            return response()->json($dispute->load(['job', 'openedBy']), 201);
        }, 3);
    }

    public function myDisputes()
    {
        $user = Auth::guard('api')->user();

        $disputes = Dispute::with(['job', 'resolvedBy'])
            ->where('opened_by', $user->id)
            ->latest()
            ->get();

        return response()->json($disputes);
    }

    public function adminIndex(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'under_review', 'resolved', 'dismissed'])],
            'job_id' => ['nullable', 'integer', 'exists:jobs,id'],
            'opened_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $query = Dispute::with(['job', 'openedBy', 'resolvedBy']);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['job_id'])) {
            $query->where('job_id', $validated['job_id']);
        }

        if (!empty($validated['opened_by'])) {
            $query->where('opened_by', $validated['opened_by']);
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function adminUpdateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['under_review', 'resolved', 'dismissed'])],
            'resolution_notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $admin = Auth::guard('api')->user();
        $jobId = Dispute::query()->findOrFail($id, ['job_id'])->job_id;

        return DB::transaction(function () use ($jobId, $id, $admin, $validated) {
            $job = Job::query()->lockForUpdate()->findOrFail($jobId);
            $dispute = Dispute::where('job_id', $job->id)->lockForUpdate()->findOrFail($id);

            $dispute->update([
                'status' => $validated['status'],
                'resolved_by' => in_array($validated['status'], ['resolved', 'dismissed'], true) ? $admin->id : null,
                'resolved_at' => in_array($validated['status'], ['resolved', 'dismissed'], true) ? now() : null,
                'resolution_notes' => $validated['resolution_notes'] ?? null,
            ]);

            if (in_array($validated['status'], ['resolved', 'dismissed'], true)
                && !app(PaymentAgreementService::class)->jobHasReservedPayable((int) $job->id)) {
                $job->update(['status' => 'in_progress']);
            }

            return response()->json($dispute->fresh(['job', 'openedBy', 'resolvedBy']));
        }, 3);
    }
}