<?php

namespace App\Http\Controllers;

use App\Models\JobEstimate;
use App\Services\Estimating\EstimateAccuracyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class EstimateAccuracyController extends Controller
{
    private const SAMPLE_LIMIT = 5000;

    public function __construct(
        private readonly EstimateAccuracyService $accuracyService
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'project_type' => ['nullable', 'string', 'max:80'],
            'zip_code' => ['nullable', 'string', 'max:10'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $baseQuery = JobEstimate::query();
        $this->applyFilters($baseQuery, $validated);

        $funnel = [
            'total_estimates' => (clone $baseQuery)->count(),
            'quoted_estimates' => (clone $baseQuery)
                ->whereNotNull('contractor_quote')
                ->count(),
            'accepted_estimates' => (clone $baseQuery)
                ->whereNotNull('accepted_price')
                ->count(),
            'actuals_complete' => (clone $baseQuery)
                ->whereNotNull('actual_hours')
                ->whereNotNull('actual_material_cost')
                ->whereNotNull('final_invoice')
                ->count(),
        ];

        $accuracyQuery = (clone $baseQuery)
            ->whereNotNull('final_invoice')
            ->where('final_invoice', '>', 0)
            ->where(function (Builder $query) {
                $query->where('status', 'completed')
                    ->orWhereHas('job', function (Builder $jobQuery) {
                        $jobQuery->where('status', 'completed');
                    });
            });
        $completedCount = (clone $accuracyQuery)->count();

        $estimates = $accuracyQuery
            ->with([
                'job:id,property_id,customer_id,handyman_id,status',
                'job.property:id,zip',
                'job.customer:id,name',
                'job.handyman:id,name',
            ])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(self::SAMPLE_LIMIT)
            ->get();

        return response()->json(array_merge(
            $this->accuracyService->summarize(
                $estimates,
                $funnel,
                $completedCount > self::SAMPLE_LIMIT
            ),
            [
                'filters' => $validated,
                'filter_options' => [
                    'project_types' => JobEstimate::query()
                        ->whereNotNull('project_type')
                        ->distinct()
                        ->orderBy('project_type')
                        ->pluck('project_type')
                        ->values(),
                    'zip_codes' => JobEstimate::query()
                        ->leftJoin('jobs', 'job_estimates.job_id', '=', 'jobs.id')
                        ->leftJoin('properties', 'jobs.property_id', '=', 'properties.id')
                        ->selectRaw(
                            "COALESCE(NULLIF(job_estimates.zip_code, ''), properties.zip) AS resolved_zip"
                        )
                        ->whereRaw(
                            "COALESCE(NULLIF(job_estimates.zip_code, ''), properties.zip) IS NOT NULL"
                        )
                        ->whereRaw(
                            "COALESCE(NULLIF(job_estimates.zip_code, ''), properties.zip) != ''"
                        )
                        ->distinct()
                        ->orderBy('resolved_zip')
                        ->pluck('resolved_zip')
                        ->values(),
                ],
                'generated_at' => now()->toIso8601String(),
            ]
        ));
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['project_type'])) {
            $query->where('project_type', $filters['project_type']);
        }

        if (!empty($filters['zip_code'])) {
            $zipCode = $filters['zip_code'];
            $query->where(function (Builder $zipQuery) use ($zipCode) {
                $zipQuery->where('zip_code', $zipCode)
                    ->orWhere(function (Builder $propertyZipQuery) use ($zipCode) {
                        $propertyZipQuery
                            ->where(function (Builder $missingZipQuery) {
                                $missingZipQuery->whereNull('zip_code')
                                    ->orWhere('zip_code', '');
                            })
                            ->whereHas('job.property', function (Builder $propertyQuery) use ($zipCode) {
                                $propertyQuery->where('zip', $zipCode);
                            });
                    });
            });
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
    }
}
