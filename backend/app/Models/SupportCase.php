<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SupportCase extends Model
{
    protected $fillable = [
        'case_number',
        'user_id',
        'job_id',
        'category',
        'severity',
        'status',
        'subject',
        'description',
        'assigned_admin_id',
        'escalation_level',
        'first_response_due_at',
        'resolution_due_at',
        'first_responded_at',
        'last_activity_at',
        'escalated_at',
        'response_overdue_alerted_at',
        'resolution_overdue_alerted_at',
        'resolved_at',
        'closed_at',
        'admin_notes',
    ];

    protected $casts = [
        'escalation_level' => 'integer',
        'first_response_due_at' => 'datetime',
        'resolution_due_at' => 'datetime',
        'first_responded_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'escalated_at' => 'datetime',
        'response_overdue_alerted_at' => 'datetime',
        'resolution_overdue_alerted_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupportCase $case): void {
            if ($case->case_number) {
                return;
            }

            do {
                $number = 'TFX-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
            } while (static::where('case_number', $number)->exists());

            $case->case_number = $number;
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function assignedAdministrator()
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }
}
