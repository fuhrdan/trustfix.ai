<?php

namespace App\Http\Controllers;

use App\Models\ChangeOrder;
use App\Models\Job;
use App\Services\PaymentAgreementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChangeOrderController extends Controller
{
    public function store(Request $request, $jobId)
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:5000'],
            'price_delta' => ['required', 'numeric', 'min:-999999.99', 'max:999999.99'],
        ]);

        $user = Auth::guard('api')->user();

        return DB::transaction(function () use ($jobId, $user, $validated) {
            $job = Job::query()->lockForUpdate()->findOrFail($jobId);

            $isCustomer = $job->customer_id === $user->id;
            $isAssignedHandyman = $job->handyman_id === $user->id;

            if (!$isCustomer && !$isAssignedHandyman) {
                return response()->json(['error' => 'Forbidden'], 403);
            }

            app(PaymentAgreementService::class)->assertJobTermsEditable((int) $job->id);

            $requestedBy = $isCustomer ? 'customer' : 'handyman';

            $changeOrder = ChangeOrder::create([
                'job_id' => $job->id,
                'requested_by' => $requestedBy,
                'description' => $validated['description'],
                'price_delta' => $validated['price_delta'],
                'status' => 'pending',
            ]);

            $job->update([
                'status' => 'change_requested',
            ]);

            return response()->json($changeOrder, 201);
        }, 3);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
        ]);

        $user = Auth::guard('api')->user();
        $jobId = ChangeOrder::query()->findOrFail($id, ['job_id'])->job_id;

        return DB::transaction(function () use ($jobId, $id, $user, $validated) {
            $job = Job::query()->lockForUpdate()->findOrFail($jobId);
            $changeOrder = ChangeOrder::where('job_id', $job->id)->lockForUpdate()->findOrFail($id);

            $isCustomer = $job->customer_id === $user->id;
            $isAssignedHandyman = $job->handyman_id === $user->id;

            if (!$isCustomer && !$isAssignedHandyman) {
                return response()->json(['error' => 'Forbidden'], 403);
            }

            if ($changeOrder->status !== 'pending') {
                return response()->json(['error' => 'Change order already reviewed'], 409);
            }

            if ($validated['status'] === 'approved' && (
                $isCustomer === $isAssignedHandyman
                || $changeOrder->requested_by !== ($isCustomer ? 'handyman' : 'customer')
            )) {
                return response()->json(['error' => 'The counterparty must approve the change order.'], 403);
            }

            app(PaymentAgreementService::class)->assertJobTermsEditable((int) $job->id);
            $changeOrder->update([
                'status' => $validated['status'],
            ]);

            if ($validated['status'] === 'approved') {
                $job->agreed_price = ($job->agreed_price ?? 0) + $changeOrder->price_delta;
                $job->status = 'in_progress';
                $job->save();
            }

            if ($validated['status'] === 'rejected') {
                $job->update([
                    'status' => 'in_progress',
                ]);
            }

            return response()->json($changeOrder->fresh('job'));
        }, 3);
    }
}