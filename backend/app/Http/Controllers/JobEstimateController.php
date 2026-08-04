<?php

namespace App\Http\Controllers;

use App\Models\ContractorProfile;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\JobEstimate;
use App\Models\JobEstimateRevision;
use App\Models\JobMessage;
use App\Models\User;
use App\Services\Estimating\EstimateCalculator;
use App\Services\Estimating\EstimatePricingResolver;
use App\Services\Estimating\JobAnalysisManager;
use App\Services\Estimating\MaterialPriceMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class JobEstimateController extends Controller
{
    public function __construct(
        private readonly JobAnalysisManager $analysisManager,
        private readonly EstimatePricingResolver $pricingResolver,
        private readonly MaterialPriceMatcher $materialMatcher,
        private readonly EstimateCalculator $calculator,
    ) {
    }

    public function show($id)
    {
        [$job, $user] = $this->accessibleJob($id, true);
        $estimate = $job->estimate;

        return response()->json([
            'job' => $job,
            'estimate' => $estimate,
            'permissions' => $this->permissions($job, $user, $estimate),
            'pricing_ready' => $estimate
                ? (bool) (($estimate->pricing_snapshot['configured'] ?? false))
                : (bool) (($this->pricingResolver->forJob($job)['configured'] ?? false)),
            'missing_material_prices' => $estimate
                ? $this->missingMaterialPrices($estimate->materials ?? [])
                : [],
        ]);
    }

    public function analyze(Request $request, $id)
    {
        [$job, $user] = $this->accessibleJob($id);
        $this->requireParticipant($job, $user);

        if ($job->estimate && in_array($job->estimate->status, ['quoted', 'accepted', 'completed'], true)) {
            return response()->json([
                'message' => 'A submitted or accepted quote cannot be replaced by a new automated analysis.',
            ], 409);
        }

        $validated = $request->validate([
            'answers' => ['nullable', 'array', 'max:30'],
            'answers.*' => ['nullable', 'string', 'max:1500'],
        ]);
        $answers = $validated['answers'] ?? [];
        $analysisResult = $this->analysisManager->analyze($job, $answers);
        $analysis = $analysisResult['analysis'];
        $zipCode = $this->jobZipCode($job);
        $materials = $this->materialMatcher->price($analysis['materials'] ?? [], $zipCode);
        $pricing = $this->pricingResolver->forJob($job);
        $calculation = $this->calculator->calculate($analysis['steps'] ?? [], $materials, $pricing);

        $estimate = DB::transaction(function () use (
            $job,
            $user,
            $answers,
            $analysis,
            $analysisResult,
            $zipCode,
            $materials,
            $calculation
        ) {
            $estimate = JobEstimate::firstOrNew(['job_id' => $job->id]);
            $estimate->fill(array_merge([
                'created_by_user_id' => $estimate->exists
                    ? $estimate->created_by_user_id
                    : $user->id,
                'version' => $estimate->exists ? $estimate->version + 1 : 1,
                'status' => !empty($analysis['follow_up_questions'])
                    ? 'needs_information'
                    : 'preliminary',
                'analysis_provider' => $analysisResult['provider'],
                'analysis_model' => $analysisResult['model'],
                'analysis_error' => $analysisResult['error'],
                'project_type' => $analysis['project_type'] ?? 'general_repair',
                'zip_code' => $zipCode,
                'scope_summary' => $analysis['scope_summary'] ?? $job->initial_description,
                'confidence' => $analysis['confidence'] ?? 'low',
                'follow_up_questions' => $analysis['follow_up_questions'] ?? [],
                'intake_answers' => $answers,
                'assumptions' => $analysis['assumptions'] ?? [],
                'risk_flags' => $analysis['risk_flags'] ?? [],
                'steps' => $analysis['steps'] ?? [],
                'materials' => $materials,
                'photo_count' => $job->images()->count(),
            ], $calculation));
            $estimate->save();

            $this->revision($estimate, $user, 'analysis_generated');

            return $estimate->fresh();
        });

        $this->recordEvent(
            $job,
            $user,
            'estimate_analyzed',
            'TrustFix generated a ' . str_replace('_', ' ', $estimate->status) . ' job analysis.'
        );

        return response()->json([
            'estimate' => $estimate,
            'permissions' => $this->permissions($job, $user, $estimate),
            'pricing_ready' => (bool) ($estimate->pricing_snapshot['configured'] ?? false),
            'missing_material_prices' => $this->missingMaterialPrices($estimate->materials ?? []),
        ]);
    }

    public function update(Request $request, $id)
    {
        [$job, $user] = $this->accessibleJob($id);
        $this->requireContractorOrAdmin($job, $user);
        $estimate = $job->estimate;

        if (!$estimate) {
            return response()->json(['message' => 'Generate an analysis before contractor review.'], 409);
        }
        if (in_array($estimate->status, ['accepted', 'completed'], true)) {
            return response()->json(['message' => 'An accepted estimate cannot be rewritten.'], 409);
        }

        $validated = $request->validate([
            'project_type' => ['required', 'string', 'max:80'],
            'scope_summary' => ['required', 'string', 'max:10000'],
            'steps' => ['required', 'array', 'min:1', 'max:50'],
            'steps.*.title' => ['required', 'string', 'max:255'],
            'steps.*.description' => ['nullable', 'string', 'max:2000'],
            'steps.*.hours_low' => ['required', 'numeric', 'min:0', 'max:10000'],
            'steps.*.hours_high' => ['required', 'numeric', 'min:0', 'max:10000'],
            'materials' => ['nullable', 'array', 'max:100'],
            'materials.*.name' => ['required', 'string', 'max:255'],
            'materials.*.quantity_low' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'materials.*.quantity_high' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'materials.*.unit' => ['required', 'string', 'max:40'],
            'materials.*.unit_price_low' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'materials.*.unit_price_high' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'materials.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($validated['steps'] as $index => $step) {
            if ((float) $step['hours_high'] < (float) $step['hours_low']) {
                return response()->json([
                    'message' => 'Each high-hour estimate must be at least its low-hour estimate.',
                    'errors' => ["steps.$index.hours_high" => ['High hours must be greater than or equal to low hours.']],
                ], 422);
            }
        }

        $materials = $validated['materials'] ?? [];
        foreach ($materials as $index => $material) {
            if ((float) $material['quantity_high'] < (float) $material['quantity_low']) {
                return response()->json([
                    'message' => 'Each high material quantity must be at least its low quantity.',
                    'errors' => ["materials.$index.quantity_high" => ['High quantity must be greater than or equal to low quantity.']],
                ], 422);
            }
            $materials[$index]['price_source'] = 'Contractor entry';
        }

        $pricedMaterials = $this->materialMatcher->price($materials, $estimate->zip_code);
        $pricing = $this->pricingResolver->forJob($job);
        $calculation = $this->calculator->calculate($validated['steps'], $pricedMaterials, $pricing);

        DB::transaction(function () use ($estimate, $user, $validated, $pricedMaterials, $calculation) {
            $estimate->fill(array_merge([
                'project_type' => $validated['project_type'],
                'scope_summary' => $validated['scope_summary'],
                'steps' => $validated['steps'],
                'materials' => $pricedMaterials,
                'status' => 'contractor_reviewed',
                'reviewed_by_user_id' => $user->id,
                'contractor_quote' => null,
                'quoted_at' => null,
                'version' => $estimate->version + 1,
            ], $calculation));
            $estimate->save();
            $this->revision($estimate, $user, 'contractor_reviewed');
        });

        $this->recordEvent(
            $job,
            $user,
            'estimate_reviewed',
            $user->name . ' reviewed and adjusted the TrustFix estimate.'
        );

        return response()->json([
            'estimate' => $estimate->fresh(),
            'missing_material_prices' => $this->missingMaterialPrices($pricedMaterials),
        ]);
    }

    public function quote(Request $request, $id)
    {
        [$job, $user] = $this->accessibleJob($id);
        $this->requireContractorOrAdmin($job, $user);
        $estimate = $job->estimate;

        if (!$estimate || $estimate->status !== 'contractor_reviewed') {
            return response()->json(['message' => 'Save a contractor review before submitting the quote.'], 409);
        }

        if (empty($estimate->pricing_snapshot['configured'])) {
            return response()->json([
                'message' => 'Save real TrustFix or contractor pricing settings before submitting a quote.',
            ], 422);
        }

        $missing = $this->missingMaterialPrices($estimate->materials ?? []);
        if ($missing) {
            return response()->json([
                'message' => 'Enter a verified unit price for every listed material before submitting a quote.',
                'missing_material_prices' => $missing,
            ], 422);
        }

        $validated = $request->validate([
            'contractor_quote' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
        ]);

        DB::transaction(function () use ($estimate, $user, $validated) {
            $estimate->update([
                'contractor_quote' => $validated['contractor_quote'],
                'status' => 'quoted',
                'reviewed_by_user_id' => $user->id,
                'quoted_at' => now(),
                'version' => $estimate->version + 1,
            ]);
            $this->revision($estimate, $user, 'quote_submitted');
        });

        $this->recordEvent(
            $job,
            $user,
            'quote_submitted',
            $user->name . ' submitted a contractor quote for customer review.'
        );

        return response()->json($estimate->fresh());
    }

    public function accept(Request $request, $id)
    {
        [$job, $user] = $this->accessibleJob($id);
        $estimate = $job->estimate;

        if ($job->customer_id != $user->id && $user->role !== 'admin') {
            return response()->json(['message' => 'Only the customer can accept this quote.'], 403);
        }
        if (!$estimate || $estimate->status !== 'quoted' || !$estimate->contractor_quote) {
            return response()->json(['message' => 'There is no submitted quote to accept.'], 409);
        }

        DB::transaction(function () use ($job, $estimate, $user) {
            $estimate->update([
                'accepted_price' => $estimate->contractor_quote,
                'status' => 'accepted',
                'accepted_at' => now(),
                'version' => $estimate->version + 1,
            ]);
            $job->update(['agreed_price' => $estimate->contractor_quote]);
            $this->revision($estimate, $user, 'quote_accepted');
        });

        $this->recordEvent(
            $job,
            $user,
            'quote_accepted',
            $user->name . ' accepted the contractor quote.'
        );

        return response()->json($estimate->fresh());
    }

    public function actuals(Request $request, $id)
    {
        [$job, $user] = $this->accessibleJob($id);
        $this->requireContractorOrAdmin($job, $user);
        $estimate = $job->estimate;

        if (!$estimate) {
            return response()->json(['message' => 'No estimate exists for this job.'], 409);
        }
        if (!in_array($estimate->status, ['accepted', 'completed'], true)
            && !in_array($job->status, ['in_progress', 'completed'], true)) {
            return response()->json(['message' => 'Actual results can be recorded after the quote is accepted or work begins.'], 409);
        }

        $validated = $request->validate([
            'actual_hours' => ['required', 'numeric', 'min:0', 'max:10000'],
            'actual_material_cost' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'final_invoice' => ['required', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        DB::transaction(function () use ($job, $estimate, $user, $validated) {
            $estimate->fill($validated);
            if ($job->status === 'completed') {
                $estimate->status = 'completed';
                $estimate->completed_at = now();
            }
            $estimate->version++;
            $estimate->save();
            $this->revision($estimate, $user, 'actuals_recorded');
        });

        $this->recordEvent(
            $job,
            $user,
            'estimate_actuals_recorded',
            $user->name . ' recorded actual labor, materials, and final invoice data.'
        );

        return response()->json($estimate->fresh());
    }

    public function revisions($id)
    {
        [$job] = $this->accessibleJob($id);

        return response()->json(
            $job->estimate
                ? $job->estimate->revisions()->with('user:id,name')->latest()->get()
                : []
        );
    }

    private function accessibleJob($id, bool $withEstimate = false): array
    {
        $user = Auth::guard('api')->user();
        if (!$user) {
            abort(response()->json(['error' => 'Unauthenticated'], 401));
        }

        $relations = ['customer', 'handyman', 'property', 'images'];
        if ($withEstimate) {
            $relations[] = 'estimate';
        }

        $job = Job::with($relations)->findOrFail($id);
        $isCustomer = $job->customer_id == $user->id;
        $isAssignedContractor = $job->handyman_id == $user->id;
        $isAdmin = $user->role === 'admin';
        $isAvailableContractor = $user->role === 'handyman'
            && !$job->handyman_id
            && in_array($job->status, ['posted', 'requested'], true)
            && ContractorProfile::where('user_id', $user->id)->where('status', 'approved')->exists();

        if (!$isCustomer && !$isAssignedContractor && !$isAdmin && !$isAvailableContractor) {
            abort(response()->json(['error' => 'Forbidden'], 403));
        }

        return [$job, $user];
    }

    private function requireParticipant(Job $job, User $user): void
    {
        if ($job->customer_id != $user->id && $job->handyman_id != $user->id && $user->role !== 'admin') {
            abort(response()->json(['message' => 'Only the customer or assigned contractor can analyze this job.'], 403));
        }
    }

    private function requireContractorOrAdmin(Job $job, User $user): void
    {
        if ($user->role !== 'admin' && $job->handyman_id != $user->id) {
            abort(response()->json(['message' => 'Only the assigned contractor can review this estimate.'], 403));
        }
    }

    private function permissions(Job $job, User $user, ?JobEstimate $estimate): array
    {
        $isCustomer = $job->customer_id == $user->id;
        $isAssigned = $job->handyman_id == $user->id;
        $isAdmin = $user->role === 'admin';
        $locked = $estimate && in_array($estimate->status, ['accepted', 'completed'], true);

        return [
            'can_analyze' => ($isCustomer || $isAssigned || $isAdmin)
                && (!$estimate || !in_array($estimate->status, ['quoted', 'accepted', 'completed'], true)),
            'can_review' => ($isAssigned || $isAdmin) && !$locked,
            'can_quote' => ($isAssigned || $isAdmin)
                && $estimate
                && $estimate->status === 'contractor_reviewed',
            'can_accept' => ($isCustomer || $isAdmin) && $estimate?->status === 'quoted',
            'can_record_actuals' => ($isAssigned || $isAdmin)
                && (bool) $estimate
                && (in_array($estimate->status, ['accepted', 'completed'], true)
                    || in_array($job->status, ['in_progress', 'completed'], true)),
            'is_customer' => $isCustomer,
            'is_contractor' => $isAssigned,
            'is_admin' => $isAdmin,
        ];
    }

    private function jobZipCode(Job $job): ?string
    {
        if ($job->property?->zip) {
            return trim((string) $job->property->zip);
        }

        return preg_match('/\b\d{5}(?:-\d{4})?\b/', (string) $job->address, $match)
            ? $match[0]
            : null;
    }

    private function missingMaterialPrices(array $materials): array
    {
        return array_values(array_map(
            fn ($material) => $material['name'] ?? 'Unnamed material',
            array_filter($materials, fn ($material) => !empty($material['price_missing']))
        ));
    }

    private function revision(JobEstimate $estimate, User $user, string $action): void
    {
        JobEstimateRevision::create([
            'job_estimate_id' => $estimate->id,
            'user_id' => $user->id,
            'action' => $action,
            'snapshot' => $estimate->fresh()->toArray(),
        ]);
    }

    private function recordEvent(Job $job, User $user, string $type, string $description): void
    {
        JobActivity::create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'activity_type' => $type,
            'description' => $description,
        ]);

        JobMessage::create([
            'job_id' => $job->id,
            'sender_user_id' => null,
            'message' => $description,
            'message_type' => 'system',
        ]);
    }
}
