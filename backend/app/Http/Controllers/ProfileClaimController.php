<?php

namespace App\Http\Controllers;

use App\Models\ContractorProfile;
use App\Models\ProfileClaim;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProfileClaimController extends Controller
{
    public function store(Request $request, $contractorProfileId)
    {
        $validated = $request->validate([
            'business_email' => ['nullable', 'email', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:30'],
            'proof_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = Auth::guard('api')->user();

        $profile = ContractorProfile::findOrFail($contractorProfileId);

        $proofPath = null;

        if ($request->hasFile('proof_document')) {
            $proofPath = $request->file('proof_document')->store("private/profile-claims/{$user->id}");
        }

        $claim = ProfileClaim::updateOrCreate(
            [
                'contractor_profile_id' => $profile->id,
                'claimant_user_id' => $user->id,
            ],
            [
                'business_email' => $validated['business_email'] ?? null,
                'business_phone' => $validated['business_phone'] ?? null,
                'proof_document_path' => $proofPath,
                'message' => $validated['message'] ?? null,
                'status' => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'admin_notes' => null,
            ]
        );

        return response()->json($claim, 201);
    }

    public function myClaims()
    {
        $user = Auth::guard('api')->user();

        $claims = ProfileClaim::with('contractorProfile')
            ->where('claimant_user_id', $user->id)
            ->latest()
            ->get();

        return response()->json($claims);
    }

    public function pending()
    {
        $claims = ProfileClaim::with(['contractorProfile', 'claimant'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json($claims);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $admin = Auth::guard('api')->user();

        $claim = ProfileClaim::with('contractorProfile')->findOrFail($id);

        $claim->update([
            'status' => $validated['status'],
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => $validated['admin_notes'] ?? null,
        ]);

        if ($validated['status'] === 'approved') {
            $claim->contractorProfile->update([
                'user_id' => $claim->claimant_user_id,
                'status' => 'pending',
            ]);
        }

        return response()->json($claim->fresh(['contractorProfile', 'claimant', 'reviewer']));
    }
}