<?php

namespace App\Http\Controllers;

use App\Models\Badge;
use App\Models\ContractorProfile;
use App\Models\ContractorDocument;
use App\Models\Dispute;
use App\Models\Document;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\JobMessage;
use App\Models\ProfileClaim;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use App\Services\LifecycleNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly LifecycleNotificationService $notifications
    ) {
    }

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

        if ((int) auth('api')->id() === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete the administrator account you are currently using.',
            ], 422);
        }

        if ($user->role === 'admin' && User::where('role', 'admin')->count() <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'TrustFix must keep at least one administrator account.',
            ], 422);
        }

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
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
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

        if (
            $user->role === 'admin'
            && $validated['role'] !== 'admin'
            && User::where('role', 'admin')->count() <= 1
        ) {
            return response()->json([
                'success' => false,
                'message' => 'TrustFix must keep at least one administrator account.',
            ], 422);
        }

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
        $previousStatus = (int) $document->verification_status;

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

        $document->load('user');
        if (
            $document->user
            && $previousStatus !== (int) $document->verification_status
            && in_array((int) $document->verification_status, [1, 2], true)
        ) {
            $this->notifications->contractorDocumentReviewed(
                $document->user,
                $document->document_type,
                (int) $document->verification_status === 1 ? 'approved' : 'rejected',
                $document->notes
            );
        }

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
            'q' => ['nullable', 'string', 'max:255'],
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

        $query = Job::with(['customer', 'handyman', 'property', 'changeOrders', 'disputes']);

        if (!empty($validated['q'])) {
            $search = $validated['q'];

            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('status', 'like', '%' . $search . '%')
                    ->orWhere('address', 'like', '%' . $search . '%')
                    ->orWhere('initial_description', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('handyman', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('property', function ($propertyQuery) use ($search) {
                        $propertyQuery->where('street_address', 'like', '%' . $search . '%')
                            ->orWhere('address_line_2', 'like', '%' . $search . '%')
                            ->orWhere('apartment', 'like', '%' . $search . '%')
                            ->orWhere('city', 'like', '%' . $search . '%')
                            ->orWhere('state', 'like', '%' . $search . '%')
                            ->orWhere('zip', 'like', '%' . $search . '%');
                    });

                if (ctype_digit($search)) {
                    $subQuery->orWhere('id', (int) $search);
                }
            });
        }

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

    public function getJob($id)
    {
        return response()->json(
            Job::with([
                'customer',
                'handyman',
                'property',
                'images',
                'changeOrders',
                'disputes',
            ])->findOrFail($id)
        );
    }

    public function updateJob(Request $request, $id)
    {
        $job = Job::findOrFail($id);

        $validated = $request->validate([
            'status' => [
                'required',
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
            'address' => ['required', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'initial_description' => ['required', 'string', 'max:5000'],
            'agreed_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'onsite_contact_name' => ['nullable', 'string', 'max:255'],
            'onsite_contact_phone' => ['nullable', 'string', 'max:50'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['string', 'max:100'],
        ]);

        $previousStatus = $job->status;
        $job->update($validated);

        if ($previousStatus !== $job->status) {
            $admin = auth('api')->user();
            $description = ($admin?->name ?? 'A TrustFix administrator')
                . ' changed the job status from '
                . str_replace('_', ' ', $previousStatus)
                . ' to '
                . str_replace('_', ' ', $job->status)
                . '.';

            JobActivity::create([
                'job_id' => $job->id,
                'user_id' => $admin?->id,
                'activity_type' => 'job_status_changed',
                'description' => $description,
            ]);

            JobMessage::create([
                'job_id' => $job->id,
                'sender_user_id' => null,
                'message' => $description,
                'message_type' => 'system',
            ]);

            $this->notifications->jobStatusChanged(
                $job,
                $job->status,
                $admin
            );
        }

        return response()->json([
            'success' => true,
            'job' => $job->fresh([
                'customer',
                'handyman',
                'property',
                'images',
                'changeOrders',
                'disputes',
            ]),
        ]);
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
