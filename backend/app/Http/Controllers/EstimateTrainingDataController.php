<?php

namespace App\Http\Controllers;

use App\Models\JobEstimate;
use Illuminate\Http\Request;

class EstimateTrainingDataController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $query = JobEstimate::with([
            'job:id,property_id,customer_id,handyman_id,status,initial_description,agreed_price,created_at',
            'job.property:id,zip',
        ]);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $page = $query->latest()->paginate($validated['per_page'] ?? 100);
        $page->getCollection()->transform(fn (JobEstimate $estimate) => $this->row($estimate));

        return response()->json($page);
    }

    private function row(JobEstimate $estimate): array
    {
        $fields = [
            'project_type' => $estimate->project_type,
            'zip_code' => $estimate->zip_code ?: $estimate->job?->property?->zip,
            'photo_count' => $estimate->photo_count,
            'estimated_hours_low' => $estimate->estimated_hours_low,
            'estimated_hours_high' => $estimate->estimated_hours_high,
            'actual_hours' => $estimate->actual_hours,
            'estimated_material_cost_low' => $estimate->material_cost_low,
            'estimated_material_cost_high' => $estimate->material_cost_high,
            'actual_material_cost' => $estimate->actual_material_cost,
            'contractor_quote' => $estimate->contractor_quote,
            'accepted_price' => $estimate->accepted_price,
            'final_invoice' => $estimate->final_invoice,
        ];

        $trainingFields = [
            $fields['project_type'],
            $fields['zip_code'],
            $fields['estimated_hours_high'],
            $fields['actual_hours'],
            $fields['actual_material_cost'],
            $fields['contractor_quote'],
            $fields['accepted_price'],
            $fields['final_invoice'],
        ];
        $complete = count(array_filter($trainingFields, fn ($value) => $value !== null && $value !== ''));

        return array_merge([
            'estimate_id' => $estimate->id,
            'job_id' => $estimate->job_id,
            'job_status' => $estimate->job?->status,
            'estimate_status' => $estimate->status,
            'analysis_provider' => $estimate->analysis_provider,
            'analysis_model' => $estimate->analysis_model,
            'confidence' => $estimate->confidence,
            'description' => $estimate->job?->initial_description,
            'scope_summary' => $estimate->scope_summary,
            'steps' => $estimate->steps,
            'materials' => $estimate->materials,
            'created_at' => optional($estimate->created_at)->toIso8601String(),
            'completeness_percent' => (int) round(($complete / count($trainingFields)) * 100),
        ], $fields);
    }
}
