<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\SupportCase;
use App\Services\OperationsAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SupportCaseController extends Controller
{
    public function __construct(
        private readonly OperationsAlertService $alerts,
    ) {
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category' => ['required', Rule::in([
                'account',
                'technical',
                'payment',
                'job',
                'contractor',
                'safety',
                'security',
                'other',
            ])],
            'impact' => ['nullable', Rule::in(['normal', 'high', 'urgent'])],
            'subject' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'min:20', 'max:10000'],
            'job_id' => ['nullable', 'integer', 'exists:jobs,id'],
        ]);
        $user = Auth::guard('api')->user();

        if (!empty($validated['job_id'])) {
            $job = Job::findOrFail($validated['job_id']);
            $isParticipant = (int) $job->customer_id === (int) $user->id
                || (int) $job->handyman_id === (int) $user->id
                || $user->role === 'admin';

            if (!$isParticipant) {
                return response()->json([
                    'error' => 'You can only attach a job that belongs to your account.',
                ], 403);
            }
        }

        $severity = $this->severityFor(
            $validated['category'],
            $validated['impact'] ?? 'normal'
        );
        [$responseHours, $resolutionHours] = $this->slaHours($severity);
        $level = match ($severity) {
            'urgent' => 3,
            'high' => 2,
            default => 1,
        };

        $case = SupportCase::create([
            'user_id' => $user->id,
            'job_id' => $validated['job_id'] ?? null,
            'category' => $validated['category'],
            'severity' => $severity,
            'status' => 'open',
            'subject' => trim($validated['subject']),
            'description' => trim($validated['description']),
            'escalation_level' => $level,
            'first_response_due_at' => now()->addHours($responseHours),
            'resolution_due_at' => now()->addHours($resolutionHours),
            'last_activity_at' => now(),
            'escalated_at' => $level > 1 ? now() : null,
        ]);

        $alertSent = $this->alerts->send('New support case '.$case->case_number, [
            'Case: '.$case->case_number,
            'Customer: '.$user->name.' <'.$user->email.'>',
            'Category: '.ucfirst($case->category),
            'Severity: '.ucfirst($case->severity).' (level '.$case->escalation_level.')',
            'Subject: '.$case->subject,
            'First response due: '.$case->first_response_due_at?->toIso8601String(),
            'Review the case in the TrustFix administrator Operations page.',
        ]);
        $this->alerts->sendTo($user->email, 'Support case '.$case->case_number.' received', [
            'Hello '.$user->name.',',
            'We received your TrustFix support request.',
            'Case: '.$case->case_number,
            'Subject: '.$case->subject,
            'Priority: '.ucfirst($case->severity),
            'First response target: '.$case->first_response_due_at?->toIso8601String(),
            'You can follow its status from the Support page in TrustFix.',
        ]);

        return response()->json(array_merge($this->userCase($case), [
            'operations_alert_sent' => $alertSent,
        ]), 201);
    }

    public function myCases()
    {
        $user = Auth::guard('api')->user();
        $cases = SupportCase::with('job:id,status,initial_description')
            ->where('user_id', $user->id)
            ->latest()
            ->paginate(20);

        $cases->getCollection()->transform(
            fn (SupportCase $case): array => $this->userCase($case)
        );

        return response()->json($cases);
    }

    private function userCase(SupportCase $case): array
    {
        return [
            'id' => $case->id,
            'case_number' => $case->case_number,
            'job_id' => $case->job_id,
            'job' => $case->relationLoaded('job') ? $case->job : null,
            'category' => $case->category,
            'severity' => $case->severity,
            'status' => $case->status,
            'subject' => $case->subject,
            'description' => $case->description,
            'escalation_level' => $case->escalation_level,
            'first_response_due_at' => $case->first_response_due_at,
            'resolution_due_at' => $case->resolution_due_at,
            'last_activity_at' => $case->last_activity_at,
            'resolved_at' => $case->resolved_at,
            'created_at' => $case->created_at,
            'updated_at' => $case->updated_at,
        ];
    }

    private function severityFor(string $category, string $impact): string
    {
        if (in_array($category, ['safety', 'security'], true)) {
            return 'urgent';
        }

        if ($category === 'payment' && $impact === 'normal') {
            return 'high';
        }

        return $impact;
    }

    private function slaHours(string $severity): array
    {
        return match ($severity) {
            'urgent' => [
                (int) config('operations.support.urgent_response_hours', 1),
                (int) config('operations.support.urgent_resolution_hours', 4),
            ],
            'high' => [
                (int) config('operations.support.high_response_hours', 4),
                (int) config('operations.support.high_resolution_hours', 24),
            ],
            default => [
                (int) config('operations.support.normal_response_hours', 24),
                (int) config('operations.support.normal_resolution_hours', 72),
            ],
        };
    }
}
