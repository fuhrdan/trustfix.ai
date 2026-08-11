<?php

namespace App\Services\Estimating;

use App\Models\JobEstimate;
use Illuminate\Support\Collection;

class EstimateAccuracyService
{
    private const BASELINE_SAMPLE_TARGET = 30;
    private const PROJECT_TYPE_SAMPLE_TARGET = 10;

    public function summarize(
        Collection $estimates,
        array $funnel,
        bool $truncated = false
    ): array {
        $samples = $estimates
            ->map(fn (JobEstimate $estimate) => $this->sample($estimate))
            ->values();

        $projectTypes = $samples
            ->groupBy('project_type')
            ->map(fn (Collection $group, string $key) => array_merge(
                ['project_type' => $key],
                $this->aggregate($group)
            ))
            ->sortByDesc('sample_count')
            ->values();

        $zipCodes = $samples
            ->groupBy('zip_code')
            ->map(fn (Collection $group, string $key) => array_merge(
                ['zip_code' => $key],
                $this->aggregate($group)
            ))
            ->sortByDesc('sample_count')
            ->values();

        $overall = $this->aggregate($samples);
        $readyProjectTypes = $projectTypes
            ->filter(
                fn (array $group) => $group['sample_count']
                    >= self::PROJECT_TYPE_SAMPLE_TARGET
            )
            ->pluck('project_type')
            ->values()
            ->all();

        return [
            'summary' => array_merge($funnel, $overall, [
                'baseline_sample_target' => self::BASELINE_SAMPLE_TARGET,
                'baseline_training_ready' => $overall['sample_count']
                    >= self::BASELINE_SAMPLE_TARGET,
                'samples_needed_for_baseline' => max(
                    0,
                    self::BASELINE_SAMPLE_TARGET - $overall['sample_count']
                ),
                'project_type_sample_target' => self::PROJECT_TYPE_SAMPLE_TARGET,
                'project_types_ready' => $readyProjectTypes,
                'truncated' => $truncated,
            ]),
            'by_project_type' => $projectTypes,
            'by_zip_code' => $zipCodes,
            'recent_samples' => $samples->take(50)->values(),
        ];
    }

    private function sample(JobEstimate $estimate): array
    {
        $invoice = (float) $estimate->final_invoice;
        $estimateLow = (float) $estimate->estimate_low;
        $estimateHigh = (float) $estimate->estimate_high;
        $estimateMidpoint = ($estimateLow + $estimateHigh) / 2;
        $hoursLow = (float) $estimate->estimated_hours_low;
        $hoursHigh = (float) $estimate->estimated_hours_high;
        $hoursMidpoint = ($hoursLow + $hoursHigh) / 2;
        $actualHours = $estimate->actual_hours !== null
            ? (float) $estimate->actual_hours
            : null;

        return [
            'estimate_id' => $estimate->id,
            'job_id' => $estimate->job_id,
            'project_type' => $estimate->project_type ?: 'unknown',
            'zip_code' => $estimate->zip_code
                ?: $estimate->job?->property?->zip
                ?: 'Unknown',
            'confidence' => $estimate->confidence,
            'customer_name' => $estimate->job?->customer?->name,
            'contractor_name' => $estimate->job?->handyman?->name,
            'estimate_low' => $this->money($estimateLow),
            'estimate_high' => $this->money($estimateHigh),
            'estimate_midpoint' => $this->money($estimateMidpoint),
            'contractor_quote' => $estimate->contractor_quote !== null
                ? $this->money((float) $estimate->contractor_quote)
                : null,
            'accepted_price' => $estimate->accepted_price !== null
                ? $this->money((float) $estimate->accepted_price)
                : null,
            'final_invoice' => $this->money($invoice),
            'estimated_hours_midpoint' => $this->number($hoursMidpoint),
            'actual_hours' => $actualHours !== null
                ? $this->number($actualHours)
                : null,
            'in_estimate_range' => $invoice >= $estimateLow
                && $invoice <= $estimateHigh,
            'midpoint_error_percent' => $this->percentError(
                $estimateMidpoint,
                $invoice
            ),
            'quote_error_percent' => $estimate->contractor_quote !== null
                ? $this->percentError(
                    (float) $estimate->contractor_quote,
                    $invoice
                )
                : null,
            'accepted_error_percent' => $estimate->accepted_price !== null
                ? $this->percentError(
                    (float) $estimate->accepted_price,
                    $invoice
                )
                : null,
            'hours_error_percent' => $actualHours !== null && $actualHours > 0
                ? $this->percentError($hoursMidpoint, $actualHours)
                : null,
            'completed_at' => optional(
                $estimate->completed_at ?: $estimate->updated_at
            )->toIso8601String(),
        ];
    }

    private function aggregate(Collection $samples): array
    {
        $count = $samples->count();
        $inRangeCount = $samples
            ->where('in_estimate_range', true)
            ->count();

        return [
            'sample_count' => $count,
            'in_range_count' => $inRangeCount,
            'in_range_percent' => $count
                ? $this->number(($inRangeCount / $count) * 100)
                : null,
            'average_midpoint_error_percent' => $this->average(
                $samples->pluck('midpoint_error_percent')
            ),
            'average_quote_error_percent' => $this->average(
                $samples->pluck('quote_error_percent')
            ),
            'average_accepted_error_percent' => $this->average(
                $samples->pluck('accepted_error_percent')
            ),
            'average_hours_error_percent' => $this->average(
                $samples->pluck('hours_error_percent')
            ),
            'average_final_invoice' => $count
                ? $this->money((float) $samples->avg('final_invoice'))
                : null,
        ];
    }

    private function average(Collection $values): ?float
    {
        $present = $values
            ->filter(fn ($value) => $value !== null)
            ->values();

        return $present->isEmpty()
            ? null
            : $this->number((float) $present->avg());
    }

    private function percentError(float $predicted, float $actual): ?float
    {
        if ($actual <= 0) {
            return null;
        }

        return $this->number(
            (abs($predicted - $actual) / $actual) * 100
        );
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }

    private function number(float $value): float
    {
        return round($value, 1);
    }
}
