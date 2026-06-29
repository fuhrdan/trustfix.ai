<?php

namespace App\Http\Controllers;

use App\Models\ContractorProfile;
use App\Models\ContractorDocument;
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
            'year_established' => ['nullable', 'integer', 'min:1800', 'max:' . date('Y')],
            'min_rating' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'established_oldest', 'established_newest', 'business_name', 'rating_high'])],
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
                    ->orWhere('state_license', 'like', '%' . $search . '%')
                    ->orWhere('local_license', 'like', '%' . $search . '%')
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

        if (!empty($validated['year_established'])) {
            $query->where('year_established', '<=', $validated['year_established']);
        }

        if (!empty($validated['min_rating'])) {
            $query->having('average_rating', '>=', $validated['min_rating']);
        }

        $sort = $validated['sort'] ?? 'newest';

        match ($sort) {
            'oldest' => $query->oldest(),
            'established_oldest' => $query->orderBy('year_established'),
            'established_newest' => $query->orderByDesc('year_established'),
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

        $profile = ContractorProfile::with(['badges', 'user.documents'])
            ->withAvg(['visibleReviews as average_rating' => function ($reviewQuery) {
                $reviewQuery->where('is_visible', true);
            }], 'rating')
            ->withCount(['visibleReviews as review_count'])
            ->where('user_id', $user->id)
            ->first();

        return response()->json($profile);
    }


    public function myDocuments()
    {
        $user = Auth::guard('api')->user();

        $documentTypes = [
            'state_license',
            'sales_tax_license',
            'certificate_of_liability_insurance',
            'surety_bond',
            'service_agreement',
        ];

        $documents = ContractorDocument::where('user_id', $user->id)
            ->whereIn('document_type', $documentTypes)
            ->latest()
            ->get()
            ->groupBy('document_type')
            ->map(function ($items) {
                return $items->first();
            });

        return response()->json($documents);
    }

    public function uploadDocument(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'type' => ['required', Rule::in([
                'state_license',
                'sales_tax_license',
                'certificate_of_liability_insurance',
                'surety_bond',
                'service_agreement',
            ])],
        ]);

        $user = Auth::guard('api')->user();

        ContractorProfile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'business_name' => $user->name ?? 'Contractor Profile',
                'status' => 'pending',
                'is_public' => false,
            ]
        );

        $file = $request->file('file');

        $path = $file->store("contractors/user_{$user->id}/documents", 'public');

        $document = ContractorDocument::create([
            'user_id' => $user->id,
            'document_type' => $validated['type'],
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'verification_status' => 0,
            'verified_by' => null,
            'verified_at' => null,
            'notes' => null,
        ]);

        return response()->json($document, 201);
    }

    public function storeOrUpdate(Request $request)
    {
        $currentYear = (int) date('Y');

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'business_address' => ['nullable', 'string', 'max:500'],
            'business_phone' => ['nullable', 'string', 'max:30'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'service_area' => ['nullable', 'string', 'max:255'],
            'emergency_availability' => ['nullable', 'boolean'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'url', 'max:255'],
            'year_established' => ['nullable', 'integer', 'min:1800', 'max:' . $currentYear],
            'business_type' => ['nullable', Rule::in(['individual', 'company'])],
            'license_number' => ['nullable', 'string', 'max:100'],
            'state_license' => ['nullable', 'string', 'max:100'],
            'local_license' => ['nullable', 'string', 'max:100'],
            'sales_tax_license' => ['nullable', 'string', 'max:100'],
            'license_expiration_date' => ['nullable', 'date'],
            'coi_path' => ['nullable', 'string', 'max:500'],
            'insurance_expiration_date' => ['nullable', 'date'],
            'surety_bond_path' => ['nullable', 'string', 'max:500'],
            'service_agreement' => ['nullable', 'string', 'max:10000'],
            'profile_photo_path' => ['nullable', 'string', 'max:500'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        $user = Auth::guard('api')->user();

        $profile = ContractorProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'business_name' => $validated['business_name'],
                'business_address' => $validated['business_address'] ?? null,
                'business_phone' => $validated['business_phone'] ?? null,
                'bio' => $validated['bio'] ?? null,
                'service_area' => $validated['service_area'] ?? null,
                'emergency_availability' => $validated['emergency_availability'] ?? false,
                'phone' => $validated['phone'] ?? null,
                'website' => $validated['website'] ?? null,
                'year_established' => $validated['year_established'] ?? null,
                'business_type' => $validated['business_type'] ?? null,
                'license_number' => $validated['license_number'] ?? null,
                'state_license' => $validated['state_license'] ?? null,
                'local_license' => $validated['local_license'] ?? null,
                'sales_tax_license' => $validated['sales_tax_license'] ?? null,
                'license_expiration_date' => $validated['license_expiration_date'] ?? null,
                'coi_path' => $validated['coi_path'] ?? null,
                'insurance_expiration_date' => $validated['insurance_expiration_date'] ?? null,
                'surety_bond_path' => $validated['surety_bond_path'] ?? null,
                'service_agreement' => $validated['service_agreement'] ?? null,
                'profile_photo_path' => $validated['profile_photo_path'] ?? null,
                'is_public' => $validated['is_public'] ?? false,
                'status' => 'pending',
            ]
        );

        return response()->json($profile->load(['badges', 'employees']), 200);
    }
}