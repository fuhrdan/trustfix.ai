<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\AdminAuditLog;
use App\Models\OperationRun;
use App\Models\UptimeCheck;
use App\Services\DatabaseBackupService;
use App\Services\SupportEscalationService;
use App\Services\UptimeMonitorService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('trustfix:backup-database {--force}', function (DatabaseBackupService $backups) {
    try {
        $run = $backups->run((bool) $this->option('force'));
        $this->info(sprintf(
            'Backup complete: %s (%s bytes, sha256 %s)',
            $run->artifact_path,
            number_format((int) $run->artifact_size_bytes),
            $run->artifact_sha256
        ));

        return Command::SUCCESS;
    } catch (\Throwable $exception) {
        $this->error('Backup failed: '.$exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Create a private, compressed TrustFix database backup');

Artisan::command('trustfix:monitor-uptime', function (UptimeMonitorService $monitor) {
    if (!config('operations.monitoring.enabled')) {
        $this->comment('Uptime monitoring is disabled.');
        return Command::SUCCESS;
    }

    try {
        $checks = $monitor->run();

        foreach ($checks as $check) {
            $message = sprintf(
                '%s: %s (%s, %d ms)',
                $check->target_name,
                strtoupper($check->status),
                $check->status_code ?? 'no response',
                $check->response_time_ms
            );
            $check->status === 'up' ? $this->info($message) : $this->warn($message);
        }

        return Command::SUCCESS;
    } catch (\Throwable $exception) {
        $this->error('Uptime checks failed: '.$exception->getMessage());
        return Command::FAILURE;
    }
})->purpose('Check the TrustFix web and API endpoints and record availability');

Artisan::command('trustfix:escalate-support', function (SupportEscalationService $support) {
    try {
        $count = $support->run();
        $this->info($count.' support case'.($count === 1 ? '' : 's').' escalated or flagged overdue.');
        return Command::SUCCESS;
    } catch (\Throwable $exception) {
        $this->error('Support escalation failed: '.$exception->getMessage());
        return Command::FAILURE;
    }
})->purpose('Escalate TrustFix support cases that have missed their response targets');

Artisan::command('trustfix:prune-operations', function () {
    $auditDeleted = AdminAuditLog::where(
        'created_at',
        '<',
        now()->subDays((int) config('operations.audit.retention_days', 365))
    )->delete();
    $checksDeleted = UptimeCheck::where(
        'checked_at',
        '<',
        now()->subDays((int) config('operations.monitoring.retention_days', 30))
    )->delete();
    $runsDeleted = OperationRun::where(
        'created_at',
        '<',
        now()->subDays((int) config('operations.backups.history_days', 90))
    )->delete();

    $this->info(sprintf(
        'Pruned %d audit events, %d uptime checks, and %d operation runs.',
        $auditDeleted,
        $checksDeleted,
        $runsDeleted
    ));

    return Command::SUCCESS;
})->purpose('Remove expired TrustFix operations history');

Schedule::command('trustfix:backup-database')
    ->cron((string) config('operations.backups.schedule'))
    ->when(fn (): bool => (bool) config('operations.backups.enabled'))
    ->withoutOverlapping(360);

Schedule::command('trustfix:monitor-uptime')
    ->cron((string) config('operations.monitoring.schedule'))
    ->when(fn (): bool => (bool) config('operations.monitoring.enabled'))
    ->withoutOverlapping(15);

Schedule::command('trustfix:escalate-support')
    ->cron((string) config('operations.support.schedule'))
    ->withoutOverlapping(15);

Schedule::command('trustfix:prune-operations')
    ->dailyAt('07:15')
    ->withoutOverlapping(60);
