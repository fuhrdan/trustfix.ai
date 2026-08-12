<?php

namespace App\Services;

use App\Models\OperationRun;
use App\Models\SupportCase;
use Throwable;

class SupportEscalationService
{
    public function __construct(
        private readonly OperationsAlertService $alerts,
    ) {
    }

    public function run(): int
    {
        $clockStartedAt = microtime(true);
        $run = OperationRun::create([
            'operation' => 'support_escalation',
            'status' => 'running',
            'started_at' => now(),
            'summary' => 'Support escalation review is running.',
        ]);
        $escalated = 0;

        try {
            SupportCase::query()
                ->whereIn('status', ['open', 'in_progress'])
                ->orderBy('id')
                ->chunkById(100, function ($cases) use (&$escalated): void {
                    foreach ($cases as $case) {
                        $targetLevel = $case->escalation_level;
                        $reasons = [];
                        $responseOverdue = !$case->first_responded_at
                            && !$case->response_overdue_alerted_at
                            && $case->first_response_due_at
                            && $case->first_response_due_at->isPast();
                        $resolutionOverdue = !$case->resolution_overdue_alerted_at
                            && $case->resolution_due_at
                            && $case->resolution_due_at->isPast();

                        if ($responseOverdue) {
                            $targetLevel = max($targetLevel, 2);
                            $reasons[] = 'The first-response target was missed.';
                        }

                        if ($resolutionOverdue) {
                            $targetLevel = 3;
                            $reasons[] = 'The resolution target was missed.';
                        }

                        if (empty($reasons) && $targetLevel <= $case->escalation_level) {
                            continue;
                        }

                        $case->update([
                            'escalation_level' => $targetLevel,
                            'escalated_at' => $targetLevel > $case->escalation_level
                                ? now()
                                : $case->escalated_at,
                            'response_overdue_alerted_at' => $responseOverdue
                                ? now()
                                : $case->response_overdue_alerted_at,
                            'resolution_overdue_alerted_at' => $resolutionOverdue
                                ? now()
                                : $case->resolution_overdue_alerted_at,
                            'last_activity_at' => now(),
                        ]);
                        $escalated++;

                        $this->alerts->send('Support case '.$case->case_number.' requires attention', [
                            'Case: '.$case->case_number,
                            'Subject: '.$case->subject,
                            'Severity: '.ucfirst($case->severity),
                            'Escalation level: '.$targetLevel,
                            'Status: '.str_replace('_', ' ', ucfirst($case->status)),
                            ...$reasons,
                            'Review this case in the TrustFix administrator Operations page.',
                        ]);
                    }
                });

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'duration_ms' => max(0, (int) round((microtime(true) - $clockStartedAt) * 1000)),
                'summary' => $escalated.' support case'.($escalated === 1 ? '' : 's').' escalated or flagged overdue.',
                'details' => ['cases_requiring_attention' => $escalated],
            ]);

            return $escalated;
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => max(0, (int) round((microtime(true) - $clockStartedAt) * 1000)),
                'summary' => 'Support escalation review failed.',
                'details' => ['error' => mb_substr($exception->getMessage(), 0, 1000)],
            ]);

            throw $exception;
        }
    }
}
