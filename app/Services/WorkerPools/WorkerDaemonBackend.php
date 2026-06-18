<?php

declare(strict_types=1);

namespace App\Services\WorkerPools;

use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\SupervisorProgram;
use App\Models\WorkerPool;
use App\Services\Servers\SupervisorProvisioner;
use App\Services\Sites\SiteSystemdProvisioner;
use App\Services\Sites\SiteSystemdUnitBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Provisions a worker site's queue daemons (Horizon / queue:work / scheduler)
 * under the process manager its worker pool selected — systemd OR supervisor —
 * and tears down the OTHER backend's artifacts so exactly one manager owns the
 * daemons.
 *
 * The choice lives on {@see WorkerPool::processManager()} (meta.process_manager,
 * default systemd). systemd is the canonical path ({@see SiteSystemdProvisioner},
 * one unit per worker {@see SiteProcess}); supervisor mirrors the same
 * SiteProcess rows into managed {@see SupervisorProgram} rows
 * (slug `dply-worker-<siteId>-<name>`) and syncs them.
 *
 * Switching backends is just re-running {@see ensure()} after flipping the
 * toggle: it provisions the new backend and removes the old one's units/confs.
 */
class WorkerDaemonBackend
{
    public function __construct(
        private SiteSystemdProvisioner $systemd,
        private SiteSystemdUnitBuilder $unitBuilder,
        private SupervisorProvisioner $supervisor,
    ) {}

    /**
     * Process manager for this site's daemons, from its pool (default systemd
     * for standalone worker hosts with no pool).
     */
    public function backendFor(Site $site): string
    {
        return $this->poolFor($site)?->processManager() ?? WorkerPool::PM_SYSTEMD;
    }

    /**
     * Provision worker daemons under the chosen backend; tear down the other.
     *
     * @return array{backend: string, detail: string}
     */
    /** @return array<string, mixed> */
    public function ensure(Site $site): array
    {
        if ($this->backendFor($site) === WorkerPool::PM_SUPERVISOR) {
            $this->teardownSystemdWorkers($site);

            return ['backend' => WorkerPool::PM_SUPERVISOR, 'detail' => $this->provisionSupervisor($site)];
        }

        // systemd (default): write + start units, then retire any managed
        // supervisor worker programs left over from a previous backend choice.
        $written = $this->systemd->provision($site);
        $this->teardownSupervisorWorkers($site);

        return ['backend' => WorkerPool::PM_SYSTEMD, 'detail' => implode(', ', $written) ?: 'none'];
    }

    /**
     * start | stop | restart the worker daemons on the ACTIVE backend.
     */
    public function control(Site $site, string $action): string
    {
        $action = in_array($action, ['start', 'stop', 'restart'], true) ? $action : 'restart';

        if ($this->backendFor($site) === WorkerPool::PM_SUPERVISOR) {
            $server = $site->server;
            if ($server === null) {
                throw new \RuntimeException('Member server is not available.');
            }
            $programs = $this->managedPrograms($site)->where('is_active', true)->get();
            if ($programs->isEmpty()) {
                return "No supervisor worker programs are defined for this site — switch the pool to supervisor and ensure workers first.\n";
            }
            $out = '';
            foreach ($programs as $program) {
                $out .= match ($action) {
                    'start' => $this->supervisor->startProgramGroup($server, (string) $program->id),
                    'stop' => $this->supervisor->stopProgramGroup($server, (string) $program->id),
                    default => $this->supervisor->restartProgramGroup($server, (string) $program->id),
                }."\n";
            }

            return $out;
        }

        return $this->systemd->controlWorkerUnits($site, $action);
    }

    private function poolFor(Site $site): ?WorkerPool
    {
        $poolId = $site->server?->worker_pool_id;

        return $poolId ? WorkerPool::query()->find($poolId) : null;
    }

    private function provisionSupervisor(Site $site): string
    {
        $server = $site->server;
        if ($server === null) {
            throw new \RuntimeException('Member server is not available.');
        }

        if (! $this->supervisor->isSupervisorPackageInstalled($server)) {
            $this->supervisor->installSupervisorPackage($server);
        }

        $this->syncManagedProgramsFromSite($site);

        return $this->supervisor->sync($server);
    }

    /**
     * Mirror the site's active non-web SiteProcesses into managed
     * SupervisorProgram rows (idempotent upsert by slug); deactivate managed
     * rows whose SiteProcess has since gone away.
     */
    private function syncManagedProgramsFromSite(Site $site): void
    {
        $server = $site->server;
        if ($server === null) {
            return;
        }

        $site->loadMissing('processes');
        $user = $site->effectiveSystemUser($server);
        $dir = $site->effectiveEnvDirectory();

        $keptSlugs = [];
        foreach ($site->processes as $process) {
            if ($process->type === SiteProcess::TYPE_WEB || ! $process->is_active) {
                continue;
            }
            $command = trim((string) $process->command);
            if ($command === '') {
                continue;
            }

            $slug = $this->programSlug($site, $process);
            $keptSlugs[] = $slug;

            SupervisorProgram::updateOrCreate(
                ['server_id' => $server->id, 'slug' => $slug],
                [
                    'site_id' => $site->id,
                    'program_type' => $process->type === SiteProcess::TYPE_SCHEDULER ? 'scheduler' : 'worker',
                    'command' => $command,
                    'directory' => $dir,
                    'user' => $user,
                    'numprocs' => max(1, (int) $process->scale),
                    'is_active' => true,
                    'env_vars' => $process->env_vars ?: null,
                    'autorestart' => true,
                    'redirect_stderr' => true,
                    // Horizon traps SIGTERM and drains in-flight jobs; give it
                    // room before supervisor SIGKILLs it.
                    'stopwaitsecs' => 3660,
                ],
            );
        }

        $this->managedPrograms($site)
            ->when($keptSlugs !== [], fn (Builder $q) => $q->whereNotIn('slug', $keptSlugs))
            ->update(['is_active' => false]);
    }

    /**
     * Deactivate this site's managed supervisor programs and sync, so their
     * conf files are removed and the daemons stop — the teardown half of a
     * switch TO systemd. Best-effort: never blocks the chosen backend.
     */
    private function teardownSupervisorWorkers(Site $site): void
    {
        $server = $site->server;
        if ($server === null) {
            return;
        }
        if (! $this->managedPrograms($site)->where('is_active', true)->exists()) {
            return;
        }

        $this->managedPrograms($site)->update(['is_active' => false]);

        try {
            $this->supervisor->sync($server);
        } catch (\Throwable) {
            // Orphan confs are reaped on the next successful sync.
        }
    }

    /**
     * Stop + remove this site's worker systemd units — the teardown half of a
     * switch TO supervisor. Best-effort per unit.
     */
    private function teardownSystemdWorkers(Site $site): void
    {
        $site->loadMissing('processes');
        foreach ($site->processes as $process) {
            if ($process->type === SiteProcess::TYPE_WEB || ! $process->is_active) {
                continue;
            }
            try {
                $this->systemd->teardownUnit($site, $this->unitBuilder->processUnitName($site, $process));
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    /**
     * @return Builder<SupervisorProgram>
     */
    private function managedPrograms(Site $site): Builder
    {
        return SupervisorProgram::query()
            ->where('site_id', $site->id)
            ->where('slug', 'like', $this->slugPrefix($site).'%');
    }

    private function slugPrefix(Site $site): string
    {
        return 'dply-worker-'.$site->id.'-';
    }

    private function programSlug(Site $site, SiteProcess $process): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $process->name) ?: 'worker';

        return $this->slugPrefix($site).Str::lower($name);
    }
}
