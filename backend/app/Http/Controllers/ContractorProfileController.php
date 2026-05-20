<?php

namespace App\Http\Controllers;

use App\Models\ContractorProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ContractorProfileController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'service_area' => ['nullable', 'string', 'max:255'],
            'badge' => ['nullable', 'string', 'max:100'],
            'min_years_experience' => ['nullable', 'integer', 'min:0', 'max:100'],
            'min_rating' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'experience_high', 'experience_low', 'business_name', 'rating_high'])],
        ]);

        $query = ContractorProfile::with(['user', 'badges'])
            ->withAvg(['visibleReviews as average_rating' => function ($reviewQuery) {
                $reviewQuery->where('is_visible', true);
            }], 'rating')
            ->withCount(['visibleReviews as review_count'])
            ->where('status', 'approved')
            ->where('is_public', true);

        if (!empty($validated['q'])) {
            $search = $validated['q'];

            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('business_name', 'like', '%' . $search . '%')
                    ->orWhere('bio', 'like', '%' . $search . '%')
                    ->orWhere('service_area', 'like', '%' . $search . '%')
                    ->orWhere('license_number', 'like', '%' . $search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        if (!empty($validated['service_area'])) {
            $query->where('service_area', 'like', '%' . $validated['service_area'] . '%');
        }

        if (!empty($validated['badge'])) {
            $badge = $validated['badge'];

            $query->whereHas('badges', function ($badgeQuery) use ($badge) {
                $badgeQuery->where('slug', $badge)
                    ->orWhere('name', 'like', '%' . $badge . '%');
            });
        }

        if (array_key_exists('min_years_experience', $validated)) {
            $query->where('years_experience', '>=', $validated['min_years_experience']);
        }

        if (!empty($validated['min_rating'])) {
            $query->having('average_rating', '>=', $validated['min_rating']);
        }

        $sort = $validated['sort'] ?? 'newest';

        match ($sort) {
            'oldest' => $query->oldest(),
            'experience_high' => $query->orderByDesc('years_experience'),
            'experience_low' => $query->orderBy('years_experience'),
            'business_name' => $query->orderBy('business_name'),
            'rating_high' => $query->orderByDesc('average_rating'),
            default => $query->latest(),
        };

        return response()->json($query->paginate(20));
    }

    public function show($id)
    {
        $profile = ContractorProfile::with(['user', 'badges', 'visibleReviews.customer'])
            ->withAvg(['visibleReviews as average_rating' => function ($reviewQuery) {
                $reviewQuery->where('is_visible', true);
            }], 'rating')
            ->withCount(['visibleReviews as review_count'])
            ->where('status', 'approved')
            ->where('is_public', true)
            ->findOrFail($id);

        return response()->json($profile);
    }

    public function myProfile()
    {
        $user = Auth::guard('api')->user();

        $profile = ContractorProfile::with('badges')
            ->withAvg(['visibleReviews as average_rating' => function ($reviewQuery) {
                $reviewQuery->where('is_visible', true);
            }], 'rating')
            ->withCount(['visibleReviews as review_count'])
            ->where('user_id', $user->id)
            ->first();

        return response()->json($profile);
    }

    public function storeOrUpdate(Request $request)
    {
        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'service_area' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'url', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'years_experience' => ['nullable', 'integer', 'min:0', 'max:100'],
            'profile_photo_path' => ['nullable', 'string', 'max:500'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        $user = Auth::guard('api')->user();

        $profile = ContractorProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'business_name' => $validated['business_name'],
                'bio' => $validated['bio'] ?? null,
                'service_area' => $validated['service_area'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'website' => $validated['website'] ?? null,
                'license_number' => $validated['license_number'] ?? null,
                'years_experience' => $validated['years_experience'] ?? null,
                'profile_photo_path' => $validated['profile_photo_path'] ?? null,
                'is_public' => $validated['is_public'] ?? false,
                'status' => 'pending',
            ]
        );

        return response()->json($profile->load('badges'), 200);
    }
}