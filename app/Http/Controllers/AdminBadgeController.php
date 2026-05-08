<?php

namespace App\Http\Controllers;

use App\Models\Badge;
use App\Models\ContractorBadge;
use App\Models\ContractorProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AdminBadgeController extends Controller
{
    public function index()
    {
        return response()->json(
            Badge::orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:badges,name'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:badges,slug'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $badge = Badge::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json($badge, 201);
    }

    public function assign(Request $request, $contractorProfileId)
    {
        $validated = $request->validate([
            'badge_id' => ['required', 'integer', 'exists:badges,id'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $admin = Auth::guard('api')->user();

        $profile = ContractorProfile::findOrFail($contractorProfileId);

        $contractorBadge = ContractorBadge::updateOrCreate(
            [
                'contractor_profile_id' => $profile->id,
                'badge_id' => $validated['badge_id'],
            ],
            [
                'assigned_by' => $admin->id,
                'assigned_at' => now(),
                'admin_notes' => $validated['admin_notes'] ?? null,
            ]
        );

        return response()->json($contractorBadge->load(['badge', 'assignedBy']), 201);
    }

    public function remove($contractorProfileId, $badgeId)
    {
        $deleted = ContractorBadge::where('contractor_profile_id', $contractorProfileId)
            ->where('badge_id', $badgeId)
            ->delete();

        if (!$deleted) {
            return response()->json(['error' => 'Badge assignment not found'], 404);
        }

        return response()->json([
            'message' => 'Badge removed successfully',
        ]);
    }
}