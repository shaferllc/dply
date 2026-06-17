<?php

namespace App\Services\Insights\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\ServerBackupSchedule;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;
use Carbon\CarbonInterface;

/**
 * Stale-backup detector. Pure DB check (no SSH). For each *active* backup
 * schedule on the server, finds the most recent completed backup for its
 * target and flags if the latest success is older than `stale_after_hours`
 * (default 48). Also flags schedules that have never produced a successful
 * backup at all.
 *
 * Without this an operator can have a green-looking backup tab in the UI while
 * every nightly run has been silently failing for weeks.
 */
class StaleBackupsInsightRunner implements InsightRunnerInterface
{
    /**
     * @return array<int, App\Services\Insights\InsightCandidate>
     */
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }

        $schedules = ServerBackupSchedule::query()
            ->where('server_id', $server->id)
            ->where('is_active', true)
            ->get();

        if ($schedules->isEmpty()) {
            return [];
        }

        $staleAfterHours = max(1, (int) ($parameters['stale_after_hours'] ?? 48));
        $criticalAfterHours = max($staleAfterHours, (int) ($parameters['critical_after_hours'] ?? 168));
        $cutoff = now()->subHours($staleAfterHours);
        $criticalCutoff = now()->subHours($criticalAfterHours);

        $stale = [];
        foreach ($schedules as $schedule) {
            $latest = $this->latestSuccessAt($schedule);

            // Skip schedules that haven't had time to run yet — anything
            // younger than the cutoff window is by definition not stale.
            if ($latest === null) {
                // Never-succeeded schedule. Only flag if the schedule itself
                // is older than the cutoff (so a brand-new schedule isn't
                // immediately yellow on the dashboard).
                if (! $schedule->created_at || $schedule->created_at->lt($cutoff)) {
                    $stale[] = [
                        'schedule_id' => (string) $schedule->id,
                        'target' => $schedule->targetLabel(),
                        'target_type' => $schedule->target_type,
                        'last_success_at' => null,
                        'severity' => $schedule->created_at && $schedule->created_at->lt($criticalCutoff)
                            ? InsightFinding::SEVERITY_CRITICAL
                            : InsightFinding::SEVERITY_WARNING,
                    ];
                }

                continue;
            }

            if ($latest->lt($cutoff)) {
                $stale[] = [
                    'schedule_id' => (string) $schedule->id,
                    'target' => $schedule->targetLabel(),
                    'target_type' => $schedule->target_type,
                    'last_success_at' => $latest->toIso8601String(),
                    'severity' => $latest->lt($criticalCutoff)
                        ? InsightFinding::SEVERITY_CRITICAL
                        : InsightFinding::SEVERITY_WARNING,
                ];
            }
        }

        if ($stale === []) {
            return [];
        }

        $severity = collect($stale)->contains(fn (array $row): bool => $row['severity'] === InsightFinding::SEVERITY_CRITICAL)
            ? InsightFinding::SEVERITY_CRITICAL
            : InsightFinding::SEVERITY_WARNING;

        $names = array_map(static fn (array $row): string => (string) $row['target'], $stale);

        return [
            new InsightCandidate(
                insightKey: 'stale_backups',
                dedupeHash: 'stale-'.md5(implode(',', array_column($stale, 'schedule_id'))),
                severity: $severity,
                title: trans_choice(
                    '{1} 1 backup schedule is overdue|[2,*] :count backup schedules are overdue',
                    count($stale),
                    ['count' => count($stale)],
                ),
                body: __('The following schedules have no successful run in the last :hours hour(s): :targets. Check Backups → recent runs for the failure reason.', [
                    'hours' => $staleAfterHours,
                    'targets' => implode(', ', array_slice($names, 0, 8)).(count($names) > 8 ? ', …' : ''),
                ]),
                meta: [
                    'signal' => [
                        'stale_after_hours' => $staleAfterHours,
                        'critical_after_hours' => $criticalAfterHours,
                        'count' => count($stale),
                        'schedules' => $stale,
                    ],
                ],
            ),
        ];
    }

    /**
     * Most-recent completed backup timestamp for a schedule's target, or null
     * if the target has never produced a successful backup.
     */
    private function latestSuccessAt(ServerBackupSchedule $schedule): ?CarbonInterface
    {
        return match ($schedule->target_type) {
            ServerBackupSchedule::TARGET_DATABASE => ServerDatabaseBackup::query()
                ->where('server_database_id', $schedule->target_id)
                ->where('status', ServerDatabaseBackup::STATUS_COMPLETED)
                ->orderByDesc('updated_at')
                ->value('updated_at'),
            ServerBackupSchedule::TARGET_SITE_FILES => SiteFileBackup::query()
                ->where('site_id', $schedule->target_id)
                ->where('status', SiteFileBackup::STATUS_COMPLETED)
                ->orderByDesc('updated_at')
                ->value('updated_at'),
            default => null,
        };
    }
}
