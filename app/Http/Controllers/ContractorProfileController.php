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
            'service_area' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['approved'])],
        ]);

        $query = ContractorProfile::with('user')
            ->where('status', 'approved')
            ->where('is_public', true);

        if (!empty($validated['service_area'])) {
            $query->where('service_area', 'like', '%' . $validated['service_area'] . '%');
        }

        return response()->json($query->latest()->get());
    }

    public function show($id)
    {
        $profile = ContractorProfile::with('user')
            ->where('status', 'approved')
            ->where('is_public', true)
            ->findOrFail($id);

        return response()->json($profile);
    }

    public function myProfile()
    {
        $user = Auth::guard('api')->user();

        $profile = ContractorProfile::where('user_id', $user->id)->first();

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

        return response()->json($profile, 200);
    }
}