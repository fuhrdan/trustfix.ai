<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobEstimate extends Model
{
    protected $fillable = [
        'job_id',
        'created_by_user_id',
        'reviewed_by_user_id',
        'version',
        'status',
        'analysis_provider',
        'analysis_model',
        'analysis_error',
        'project_type',
        'zip_code',
        'scope_summary',
        'confidence',
        'follow_up_questions',
        'intake_answers',
        'assumptions',
        'risk_flags',
        'steps',
        'materials',
        'photo_count',
        'estimated_hours_low',
        'estimated_hours_high',
        'labor_cost_low',
        'labor_cost_high',
        'material_cost_low',
        'material_cost_high',
        'travel_cost',
        'insurance_cost_low',
        'insurance_cost_high',
        'tools_cost_low',
        'tools_cost_high',
        'overhead_cost_low',
        'overhead_cost_high',
        'profit_low',
        'profit_high',
        'estimate_low',
        'estimate_high',
        'pricing_snapshot',
        'contractor_quote',
        'accepted_price',
        'actual_hours',
        'actual_material_cost',
        'final_invoice',
        'quoted_at',
        'accepted_at',
        'completed_at',
    ];

    protected $casts = [
        'follow_up_questions' => 'array',
        'intake_answers' => 'array',
        'assumptions' => 'array',
        'risk_flags' => 'array',
        'steps' => 'array',
        'materials' => 'array',
        'pricing_snapshot' => 'array',
        'estimated_hours_low' => 'decimal:2',
        'estimated_hours_high' => 'decimal:2',
        'labor_cost_low' => 'decimal:2',
        'labor_cost_high' => 'decimal:2',
        'material_cost_low' => 'decimal:2',
        'material_cost_high' => 'decimal:2',
        'travel_cost' => 'decimal:2',
        'insurance_cost_low' => 'decimal:2',
        'insurance_cost_high' => 'decimal:2',
        'tools_cost_low' => 'decimal:2',
        'tools_cost_high' => 'decimal:2',
        'overhead_cost_low' => 'decimal:2',
        'overhead_cost_high' => 'decimal:2',
        'profit_low' => 'decimal:2',
        'profit_high' => 'decimal:2',
        'estimate_low' => 'decimal:2',
        'estimate_high' => 'decimal:2',
        'contractor_quote' => 'decimal:2',
        'accepted_price' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'actual_material_cost' => 'decimal:2',
        'final_invoice' => 'decimal:2',
        'quoted_at' => 'datetime',
        'accepted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function revisions()
    {
        return $this->hasMany(JobEstimateRevision::class);
    }
}
