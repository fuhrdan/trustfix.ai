<?php

namespace App\Http\Controllers;

use App\Models\Badge;
use App\Models\ContractorProfile;
use App\Models\ContractorDocument;
use App\Models\Dispute;
use App\Models\Document;
use App\Models\Job;
use App\Models\ProfileClaim;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminDashboardController extends Controller
{
    public function stats()
    {
        return response()->json([
            'users' => [
                'total' => User::count(),
                'customers' => User::where('role', 'customer')->count(),
                'handymen' => User::where('role', 'handyman')->count(),
                'companies' => User::where('role', 'company')->count(),
                'admins' => User::where('role', 'admin')->count(),
                'active' => User::where('account_status', 'active')->count(),
                'suspended' => User::where('account_status', 'suspended')->count(),
                'banned' => User::where('account_status', 'banned')->count(),
            ],
            'contractor_profiles' => [
                'total' => ContractorProfile::count(),
                'draft' => ContractorProfile::where('status', 'draft')->count(),
                'pending' => ContractorProfile::where('status', 'pending')->count(),
                'approved' => ContractorProfile::where('status', 'approved')->count(),
                'rejected' => ContractorProfile::where('status', 'rejected')->count(),
                'suspended' => ContractorProfile::where('status', 'suspended')->count(),
                'public' => ContractorProfile::where('is_public', true)->count(),
            ],
            'jobs' => [
                'total' => Job::count(),
                'posted' => Job::where('status', 'posted')->count(),
                'accepted' => Job::where('status', 'accepted')->count(),
                'in_progress' => Job::where('status', 'in_progress')->count(),
                'change_requested' => Job::where('status', 'change_requested')->count(),
                'completed' => Job::where('status', 'completed')->count(),
                'cancelled' => Job::where('status', 'cancelled')->count(),
                'disputed' => Job::where('status', 'disputed')->count(),
            ],
            'trust_and_safety' => [
                'pending_documents' => Document::where('status', 'pending')->count(),
                'pending_profile_claims' => ProfileClaim::where('status', 'pending')->count(),
                'pending_reports' => Report::where('status', 'pending')->count(),
                'open_disputes' => Dispute::where('status', 'open')->count(),
                'visible_reviews' => Review::where('is_visible', true)->count(),
                'hidden_reviews' => Review::where('is_visible', false)->count(),
                'active_badges' => Badge::where('is_active', true)->count(),
            ],
        ]);
    }

    public function users(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $query = User::query()
            ->with(['contractorProfile'])
            ->withCount([
                'contractorDocuments as pending_contractor_document_count' => function ($documentQuery) {
                    $documentQuery->where('verification_status', 0);
                },
            ]);

        if (!empty($validated['q']))
        {
            $search = $validated['q'];

            $query->where(function ($subQuery) use ($search)
            {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function deleteUser($id)
    {
        $user = User::findOrFail($id);

        $user->delete();

        return response()->json([
            'success' => true
        ]);
    }

    public function getUser($id)
    {
        return response()->json(
            User::with([
                'contractorProfile',
                'contractorDocuments' => function ($documentQuery) {
                    $documentQuery->latest();
                },
            ])->findOrFail($id)
        );
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'role' => [
                'required',
                Rule::in([
                    'customer',
                    'handyman',
                    'company',
                    'admin'
                ])
            ]
        ]);

        $user->update($validated);

        return response()->json([
            'success' => true,
            'user' => $user
        ]);
    }

    public function resetUserPassword(Request $request, $id)
    {
        $validated = $request->validate([
            'password' => 'required|string|min:8|confirmed'
        ]);

        $user = User::findOrFail($id);

        $user->password = Hash::make($validated['password']);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully'
        ]);
    }

    public function updateContractorDocumentStatus(Request $request, $id)
    {
        $admin = auth('api')->user();

        $validated = $request->validate([
            'verification_status' => ['required', Rule::in([0, 1, 2])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $document = ContractorDocument::findOrFail($id);

        $document->verification_status = (int) $validated['verification_status'];
        $document->notes = $validated['notes'] ?? $document->notes;

        if ((int) $validated['verification_status'] === 1) {
            $document->verified_by = $admin?->id;
            $document->verified_at = now();
        } else {
            $document->verified_by = null;
            $document->verified_at = null;
        }

        $document->save();

        return response()->json([
            'success' => true,
            'document' => $document->load(['user', 'verifier']),
        ]);
    }

    public function contractors(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'pending', 'approved', 'rejected', 'suspended'])],
            'is_public' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ContractorProfile::with(['user', 'badges'])
            ->withAvg(['visibleReviews as average_rating' => function ($reviewQuery) {
                $reviewQuery->where('is_visible', true);
            }], 'rating')
            ->withCount(['visibleReviews as review_count']);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (array_key_exists('is_public', $validated)) {
            $query->where('is_public', $validated['is_public']);
        }

        if (!empty($validated['q'])) {
            $search = $validated['q'];

            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('business_name', 'like', '%' . $search . '%')
                    ->orWhere('service_area', 'like', '%' . $search . '%')
                    ->orWhere('license_number', 'like', '%' . $search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
            });
        }

        return response()->json(
            $query->latest()->paginate($validated['per_page'] ?? 20)
        );
    }

    public function jobs(Request $request)
    {
        $validated = $request->validate([
            'status' => [
                'nullable',
                Rule::in([
                    'posted',
                    'requested',
                    'accepted',
                    'in_progress',
                    'change_requested',
                    'completed',
                    'cancelled',
                    'disputed',
                ]),
            ],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'handyman_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $query = Job::with(['customer', 'handyman', 'changeOrders', 'disputes']);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['customer_id'])) {
            $query->where('customer_id', $validated['customer_id']);
        }

        if (!empty($validated['handyman_id'])) {
            $query->where('handyman_id', $validated['handyman_id']);
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function activity()
    {
        return response()->json([
            'latest_users' => User::latest()->limit(10)->get(),
            'latest_contractors' => ContractorProfile::with('user')->latest()->limit(10)->get(),
            'latest_jobs' => Job::with(['customer', 'handyman'])->latest()->limit(10)->get(),
            'latest_documents' => Document::with(['handyman', 'reviewer'])->latest()->limit(10)->get(),
            'latest_profile_claims' => ProfileClaim::with(['contractorProfile', 'claimant', 'reviewer'])->latest()->limit(10)->get(),
            'latest_reports' => Report::with(['reporter', 'reportedUser', 'contractorProfile', 'job', 'reviewer'])->latest()->limit(10)->get(),
            'latest_disputes' => Dispute::with(['job', 'openedBy', 'resolvedBy'])->latest()->limit(10)->get(),
            'latest_reviews' => Review::with(['job', 'customer', 'handyman', 'contractorProfile'])->latest()->limit(10)->get(),
        ]);
    }
}
