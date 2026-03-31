<?php

namespace App\Services\Insights;

use App\Models\Server;
use App\Models\ServerCronJobRun;
use App\Models\ServerFirewallAuditEvent;
use App\Models\SiteDeployment;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Str;

class InsightCorrelationService
{
    /**
     * Best-effort context for “first seen after deploy / firewall / cron / remote task” in UI.
     * Picks the single most recent qualifying event in the last 72 hours.
     *
     * @return array<string, mixed>|null
     */
    public function correlateForNewFinding(Server $server): ?array
    {
        $since = now()->subHours(72);

        $candidates = [];

        $dep = $this->latestSuccessfulDeployment($server, $since);
        if ($dep !== null && $dep->finished_at instanceof Carbon) {
            $candidates[] = [
                'at' => $dep->finished_at,
                'payload' => [
                    'type' => 'site_deployment',
                    'deployment_id' => $dep->id,
                    'site_id' => $dep->site_id,
                    'git_sha' => $dep->git_sha,
                    'finished_at' => $dep->finished_at->toIso8601String(),
                    'trigger' => $dep->trigger,
                ],
            ];
        }

        $fw = $this->latestSuccessfulFirewallApply($server, $since);
        if ($fw !== null && $fw->created_at instanceof Carbon) {
            $candidates[] = [
                'at' => $fw->created_at,
                'payload' => [
                    'type' => 'firewall_apply',
                    'audit_event_id' => $fw->id,
                    'firewall_event' => $fw->event,
                    'at' => $fw->created_at->toIso8601String(),
                ],
            ];
        }

        $cron = $this->latestSuccessfulCronRun($server, $since);
        if ($cron !== null && $cron->finished_at instanceof Carbon) {
            $cronJob = $cron->cronJob;
            $candidates[] = [
                'at' => $cron->finished_at,
                'payload' => [
                    'type' => 'cron_job_run',
                    'run_id' => $cron->id,
                    'server_cron_job_id' => $cron->server_cron_job_id,
                    'command_excerpt' => $cronJob !== null
                        ? Str::limit((string) $cronJob->command, 200)
                        : null,
                    'finished_at' => $cron->finished_at->toIso8601String(),
                    'trigger' => $cron->trigger,
                ],
            ];
        }

        $remoteTask = $this->latestFinishedRemoteTask($server, $since);
        if ($remoteTask !== null && $remoteTask->completed_at instanceof Carbon) {
            $candidates[] = [
                'at' => $remoteTask->completed_at,
                'payload' => [
                    'type' => 'task_runner',
                    'task_id' => $remoteTask->id,
                    'name' => Str::limit((string) $remoteTask->name, 120),
                    'completed_at' => $remoteTask->completed_at->toIso8601String(),
                ],
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            /** @var Carbon $atA */
            $atA = $a['at'];
            /** @var Carbon $atB */
            $atB = $b['at'];

            return $atB->timestamp <=> $atA->timestamp;
        });

        return $candidates[0]['payload'];
    }

    protected function latestSuccessfulDeployment(Server $server, Carbon $since): ?SiteDeployment
    {
        $siteIds = $server->sites()->pluck('id');
        if ($siteIds->isEmpty()) {
            return null;
        }

        return SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', SiteDeployment::STATUS_SUCCESS)
            ->where('finished_at', '>=', $since)
            ->orderByDesc('finished_at')
            ->first();
    }

    protected function latestSuccessfulFirewallApply(Server $server, Carbon $since): ?ServerFirewallAuditEvent
    {
        $row = ServerFirewallAuditEvent::query()
            ->where('server_id', $server->id)
            ->whereIn('event', [
                ServerFirewallAuditEvent::EVENT_APPLY,
                ServerFirewallAuditEvent::EVENT_SCHEDULED_APPLY,
            ])
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->first();

        if ($row === null) {
            return null;
        }

        $meta = $row->meta;
        if (is_array($meta) && (! empty($meta['error']) || ! empty($meta['skipped']))) {
            return null;
        }

        return $row;
    }

    protected function latestSuccessfulCronRun(Server $server, Carbon $since): ?ServerCronJobRun
    {
        return ServerCronJobRun::query()
            ->where('status', ServerCronJobRun::STATUS_FINISHED)
            ->where('finished_at', '>=', $since)
            ->where(function ($q): void {
                $q->whereNull('exit_code')
                    ->orWhere('exit_code', 0);
            })
            ->whereHas('cronJob', fn ($q) => $q->where('server_id', $server->id))
            ->orderByDesc('finished_at')
            ->first();
    }

    protected function latestFinishedRemoteTask(Server $server, Carbon $since): ?Task
    {
        return Task::query()
            ->where('server_id', $server->id)
            ->where('status', TaskStatus::Finished)
            ->where('completed_at', '>=', $since)
            ->orderByDesc('completed_at')
            ->first();
    }
}
