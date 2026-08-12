<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\OperationRun;
use App\Models\SupportCase;
use App\Models\UptimeCheck;
use App\Services\OperationsAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class OperationsController extends Controller
{
    public function __construct(
        private readonly OperationsAlertService $alerts,
    ) {
    }

    public function summary()
    {
        $latestBackup = OperationRun::where('operation', 'database_backup')->latest('started_at')->first();
        $latestMonitorRun = OperationRun::where('operation', 'uptime_monitor')->latest('started_at')->first();
        $monitoringCutoff = now()->subHours(24);
        $monitors = [];

        foreach ((array) config('operations.monitoring.targets', []) as $key => $target) {
            $latest = UptimeCheck::where('target_key', $key)->latest('id')->first();
            $total = UptimeCheck::where('target_key', $key)
                ->where('checked_at', '>=', $monitoringCutoff)
                ->count();
            $up = UptimeCheck::where('target_key', $key)
                ->where('checked_at', '>=', $monitoringCutoff)
                ->where('status', 'up')
                ->count();

            $monitors[] = [
                'key' => $key,
                'name' => $target['name'] ?? $key,
                'url' => $latest?->target_url ?? ($target['url'] ?? null),
                'status' => $latest?->status ?? 'pending',
                'status_code' => $latest?->status_code,
                'response_time_ms' => $latest?->response_time_ms,
                'consecutive_failures' => $latest?->consecutive_failures ?? 0,
                'error_message' => $latest?->error_message,
                'checked_at' => $latest?->checked_at,
                'checks_24h' => $total,
                'uptime_percent_24h' => $total > 0 ? round(($up / $total) * 100, 2) : null,
            ];
        }

        $openStatuses = ['open', 'in_progress', 'waiting_customer'];
        $supportCounts = SupportCase::selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->json([
            'server_time' => now(),
            'backup' => [
                'enabled' => (bool) config('operations.backups.enabled'),
                'healthy' => $latestBackup?->status === 'success'
                    && $latestBackup->started_at?->greaterThan(now()->subHours(26)),
                'latest' => $latestBackup,
                'retention_days' => (int) config('operations.backups.retention_days'),
                'schedule' => config('operations.backups.schedule'),
            ],
            'monitoring' => [
                'enabled' => (bool) config('operations.monitoring.enabled'),
                'scheduler_healthy' => $latestMonitorRun?->started_at?->greaterThan(now()->subMinutes(12)) ?? false,
                'latest_run' => $latestMonitorRun,
                'targets' => $monitors,
            ],
            'support' => [
                'open' => SupportCase::whereIn('status', $openStatuses)->count(),
                'urgent_open' => SupportCase::whereIn('status', $openStatuses)->where('severity', 'urgent')->count(),
                'overdue' => SupportCase::whereIn('status', ['open', 'in_progress'])
                    ->where(function ($query): void {
                        $query->where(function ($inner): void {
                            $inner->whereNull('first_responded_at')
                                ->where('first_response_due_at', '<', now());
                        })->orWhere('resolution_due_at', '<', now());
                    })->count(),
                'by_status' => $supportCounts,
            ],
            'audit' => [
                'events_24h' => AdminAuditLog::where('created_at', '>=', now()->subHours(24))->count(),
                'retention_days' => (int) config('operations.audit.retention_days'),
            ],
        ]);
    }

    public function auditLogs(Request $request)
    {
        $validated = $request->validate([
            'admin_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:180'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $query = AdminAuditLog::with('administrator:id,name,email')->latest('id');

        if (!empty($validated['admin_user_id'])) {
            $query->where('admin_user_id', $validated['admin_user_id']);
        }

        if (!empty($validated['action'])) {
            $query->where('action', 'like', '%'.$validated['action'].'%');
        }

        if (!empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }

        if (!empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to']);
        }

        return response()->json($query->paginate(30));
    }

    public function supportCases(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'])],
            'severity' => ['nullable', Rule::in(['normal', 'high', 'urgent'])],
        ]);
        $query = SupportCase::with([
            'user:id,name,email,phone',
            'job:id,status,initial_description',
            'assignedAdministrator:id,name,email',
        ])->latest('id');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['severity'])) {
            $query->where('severity', $validated['severity']);
        }

        return response()->json($query->paginate(30));
    }

    public function updateSupportCase(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'])],
            'escalation_level' => ['nullable', 'integer', 'between:1,3'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $case = SupportCase::with('user')->findOrFail($id);
        $administrator = Auth::guard('api')->user();
        $previousStatus = $case->status;
        $previousLevel = $case->escalation_level;
        $status = $validated['status'];
        $level = max($case->escalation_level, (int) ($validated['escalation_level'] ?? 1));

        $case->update([
            'status' => $status,
            'assigned_admin_id' => $administrator->id,
            'escalation_level' => $level,
            'admin_notes' => array_key_exists('admin_notes', $validated)
                ? $validated['admin_notes']
                : $case->admin_notes,
            'first_responded_at' => $case->first_responded_at ?? now(),
            'last_activity_at' => now(),
            'escalated_at' => $level > $previousLevel ? now() : $case->escalated_at,
            'resolved_at' => $status === 'resolved' ? ($case->resolved_at ?? now()) : null,
            'closed_at' => $status === 'closed' ? ($case->closed_at ?? now()) : null,
        ]);

        if ($level > $previousLevel) {
            $this->alerts->send('Support case '.$case->case_number.' was escalated', [
                'Case: '.$case->case_number,
                'Subject: '.$case->subject,
                'Administrator: '.$administrator->name,
                'New escalation level: '.$level,
            ]);
        }

        if ($previousStatus !== $status && $case->user?->email) {
            $this->alerts->sendTo($case->user->email, 'Support case '.$case->case_number.' updated', [
                'Hello '.$case->user->name.',',
                'Your TrustFix support case '.$case->case_number.' is now '.str_replace('_', ' ', $status).'.',
                'Subject: '.$case->subject,
                'You can review the current status from the Support page in TrustFix.',
            ]);
        }

        return response()->json($case->fresh([
            'user:id,name,email,phone',
            'job:id,status,initial_description',
            'assignedAdministrator:id,name,email',
        ]));
    }
}
