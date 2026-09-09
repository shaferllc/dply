<?php

declare(strict_types=1);

namespace App\Services\WorkerPools;

use App\Jobs\ProvisionSiteSystemdUnitsJob;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\SupervisorProgram;
use Illuminate\Support\Collection;

/**
 * Pause / restore Horizon and queue:work on the origin web site once a
 * site-sourced fleet is healthy (or when the fleet is destroyed).
 *
 * Scheduler and cron stay on the web box. Only queue daemons are touched.
 */
class SiteWorkerFleetOnBoxDaemons
{
    /** @var list<string> */
    private const QUEUE_NEEDLES = ['queue:work', 'queue:listen', 'horizon'];

    public function pause(Site $site): int
    {
        if (data_get($site->meta, 'fleet_paused_onbox') !== null) {
            return 0;
        }

        $processIds = [];
        foreach ($this->queueProcesses($site) as $process) {
            if (! $process->is_active) {
                continue;
            }
            $process->forceFill(['is_active' => false])->save();
            $processIds[] = (string) $process->id;
        }

        $supervisorIds = [];
        foreach ($this->queueSupervisorPrograms($site) as $program) {
            if (! $program->is_active) {
                continue;
            }
            $program->forceFill(['is_active' => false])->save();
            $supervisorIds[] = (string) $program->id;
        }

        if ($processIds === [] && $supervisorIds === []) {
            return 0;
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['fleet_paused_onbox'] = [
            'processes' => $processIds,
            'supervisor' => $supervisorIds,
            'paused_at' => now()->toIso8601String(),
        ];
        $site->forceFill(['meta' => $meta])->save();

        ProvisionSiteSystemdUnitsJob::dispatch($site->id);

        return count($processIds) + count($supervisorIds);
    }

    public function restore(Site $site): int
    {
        $paused = data_get($site->meta, 'fleet_paused_onbox');
        if (! is_array($paused)) {
            return 0;
        }

        $processIds = array_values(array_filter((array) ($paused['processes'] ?? []), fn ($id): bool => is_string($id) && $id !== ''));
        $supervisorIds = array_values(array_filter((array) ($paused['supervisor'] ?? []), fn ($id): bool => is_string($id) && $id !== ''));

        $restored = 0;
        if ($processIds !== []) {
            $restored += SiteProcess::query()
                ->where('site_id', $site->id)
                ->whereIn('id', $processIds)
                ->update(['is_active' => true]);
        }
        if ($supervisorIds !== []) {
            $restored += SupervisorProgram::query()
                ->where(fn ($q) => $q->where('site_id', $site->id)->orWhere(function ($q) use ($site): void {
                    $q->whereNull('site_id')->where('server_id', $site->server_id);
                }))
                ->whereIn('id', $supervisorIds)
                ->update(['is_active' => true]);
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        unset($meta['fleet_paused_onbox']);
        $site->forceFill(['meta' => $meta])->save();

        if ($restored > 0) {
            ProvisionSiteSystemdUnitsJob::dispatch($site->id);
        }

        return $restored;
    }

    /**
     * @return Collection<int, SiteProcess>
     */
    private function queueProcesses(Site $site)
    {
        return $site->processes()
            ->get()
            ->filter(fn (SiteProcess $process): bool => $this->isQueueDaemon(
                (string) $process->type,
                (string) $process->command,
            ));
    }

    /**
     * @return Collection<int, SupervisorProgram>
     */
    private function queueSupervisorPrograms(Site $site)
    {
        $server = $site->server;
        if ($server === null) {
            return collect();
        }

        return $server->supervisorPrograms()
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $site->id))
            ->get()
            ->filter(fn (SupervisorProgram $program): bool => $this->isQueueDaemon(
                (string) $program->program_type,
                (string) $program->command,
            ));
    }

    private function isQueueDaemon(string $type, string $command): bool
    {
        $type = strtolower($type);
        if (in_array($type, ['worker', 'horizon', 'queue'], true)) {
            return true;
        }

        $haystack = strtolower($command);
        foreach (self::QUEUE_NEEDLES as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
