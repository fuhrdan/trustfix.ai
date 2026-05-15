<?php

namespace App\Http\Controllers;

use App\Models\ContractorProfile;
use App\Models\Job;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    public function store(Request $request, $jobId)
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:3000'],
        ]);

        $user = Auth::guard('api')->user();

        $job = Job::with('review')->findOrFail($jobId);

        if ($job->customer_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($job->status !== 'completed') {
            return response()->json(['error' => 'Only completed jobs can be reviewed'], 409);
        }

        if (!$job->handyman_id) {
            return response()->json(['error' => 'Job has no assigned contractor'], 409);
        }

        if ($job->review) {
            return response()->json(['error' => 'This job already has a review'], 409);
        }

        $profile = ContractorProfile::where('user_id', $job->handyman_id)->first();

        if (!$profile) {
            return response()->json(['error' => 'Contractor profile not found'], 404);
        }

        $review = Review::create([
            'job_id' => $job->id,
            'customer_id' => $user->id,
            'handyman_id' => $job->handyman_id,
            'contractor_profile_id' => $profile->id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
            'is_visible' => true,
        ]);

        return response()->json($review->load(['job', 'customer', 'handyman', 'contractorProfile']), 201);
    }

    public function contractorReviews($contractorProfileId)
    {
        $profile = ContractorProfile::findOrFail($contractorProfileId);

        $reviews = Review::with(['customer'])
            ->where('contractor_profile_id', $profile->id)
            ->where('is_visible', true)
            ->latest()
            ->paginate(20);

        return response()->json($reviews);
    }

    public function myReviews()
    {
        $user = Auth::guard('api')->user();

        $reviews = Review::with(['job', 'contractorProfile'])
            ->where('customer_id', $user->id)
            ->latest()
            ->get();

        return response()->json($reviews);
    }

    public function adminIndex(Request $request)
    {
        $validated = $request->validate([
            'is_visible' => ['nullable', 'boolean'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'contractor_profile_id' => ['nullable', 'integer', 'exists:contractor_profiles,id'],
        ]);

        $query = Review::with(['job', 'customer', 'handyman', 'contractorProfile', 'moderator']);

        if (array_key_exists('is_visible', $validated)) {
            $query->where('is_visible', $validated['is_visible']);
        }

        if (!empty($validated['rating'])) {
            $query->where('rating', $validated['rating']);
        }

        if (!empty($validated['contractor_profile_id'])) {
            $query->where('contractor_profile_id', $validated['contractor_profile_id']);
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function adminUpdateVisibility(Request $request, $id)
    {
        $validated = $request->validate([
            'is_visible' => ['required', 'boolean'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $admin = Auth::guard('api')->user();
        $review = Review::findOrFail($id);

        $review->update([
            'is_visible' => $validated['is_visible'],
            'moderated_by' => $admin->id,
            'moderated_at' => now(),
            'admin_notes' => $validated['admin_notes'] ?? null,
        ]);

        return response()->json($review->fresh(['job', 'customer', 'handyman', 'contractorProfile', 'moderator']));
    }
}