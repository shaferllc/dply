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
use App\Support\DplyRuntime;
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
     * Process manager for this site's daemons: its pool's choice, else the
     * site's own (meta.worker_process_manager), else systemd. The site-level
     * choice is how a standalone VM site moves its queue workers onto
     * Supervisor, where the Queue page and deploy restarts manage them.
     */
    public function backendFor(Site $site): string
    {
        $pool = $this->poolFor($site);

        if ($pool !== null) {
            return $pool->processManager();
        }

        return data_get($site->meta, 'worker_process_manager') === WorkerPool::PM_SUPERVISOR
            ? WorkerPool::PM_SUPERVISOR
            : WorkerPool::PM_SYSTEMD;
    }

    /**
     * Provision worker daemons under the chosen backend; tear down the other.
     *
     * @return array{backend: string, detail: string}
     */
    public function ensure(Site $site): array
    {
        if ($this->backendFor($site) === WorkerPool::PM_SUPERVISOR) {
            // Supervisor first: a failed install or sync must leave the units
            // running, not the site with no workers. And only units that were
            // actually mirrored come down — a role-filtered or command-less one
            // has no program to take its place.
            [$detail, $mirroredProcessIds] = $this->provisionSupervisor($site);
            $this->teardownSystemdWorkers($site, $mirroredProcessIds);

            return ['backend' => WorkerPool::PM_SUPERVISOR, 'detail' => $detail];
        }

        // systemd (default): write + start units, then retire any managed
        // supervisor worker programs left over from a previous backend choice.
        $written = $this->systemd->provision($site);
        $this->teardownSupervisorWorkers($site);

        return ['backend' => WorkerPool::PM_SYSTEMD, 'detail' => implode(', ', $written) ?: 'none'];
    }

    /**
     * Whether this site still runs workers as systemd units — the case where a
     * PHP site needs the Services page, since nothing else lists them.
     */
    public function hasSystemdWorkerUnits(Site $site): bool
    {
        return $site->exists
            && $this->backendFor($site) === WorkerPool::PM_SYSTEMD
            && $site->processes()->where('is_active', true)->where('type', '!=', SiteProcess::TYPE_WEB)->exists();
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

    /**
     * @return array{0: string, 1: list<string>} sync output, and the ids of the processes now running as programs
     */
    private function provisionSupervisor(Site $site): array
    {
        $server = $site->server;
        if ($server === null) {
            throw new \RuntimeException('Member server is not available.');
        }

        if (! $this->supervisor->isSupervisorPackageInstalled($server)) {
            $this->supervisor->installSupervisorPackage($server);
        }

        $mirrored = $this->syncManagedProgramsFromSite($site);

        return [$this->supervisor->sync($server), $mirrored];
    }

    /**
     * The SiteProcess a managed program mirrors, if it is one. A switch that
     * stops such a program has to deactivate this row too — ensure() re-mirrors
     * every active process, so the program alone would come straight back.
     */
    public function sourceProcessFor(Site $site, SupervisorProgram $program): ?SiteProcess
    {
        $site->loadMissing('processes');

        return $site->processes->first(fn (SiteProcess $process): bool => $this->programSlug($site, $process) === $program->slug);
    }

    /**
     * Mirror the site's active non-web SiteProcesses into managed
     * SupervisorProgram rows (idempotent upsert by slug); deactivate managed
     * rows whose SiteProcess has since gone away.
     *
     * @return list<string> ids of the processes that now have a program
     */
    private function syncManagedProgramsFromSite(Site $site): array
    {
        $server = $site->server;
        if ($server === null) {
            return [];
        }

        $site->loadMissing('processes');
        $user = $site->effectiveSystemUser($server);
        $dir = $site->effectiveEnvDirectory();

        [$runtimeMode, $workerRole] = $this->hostRuntimeFor($site);

        $keptSlugs = [];
        $mirrored = [];
        foreach ($site->processes as $process) {
            if ($process->type === SiteProcess::TYPE_WEB || ! $process->is_active) {
                continue;
            }
            // Manifest roles (worker / worker:primary / web) filter which hosts
            // get the program. Empty roles = apply on every host (BYO default).
            if (! $process->matchesRuntimeRole($runtimeMode, $workerRole)) {
                continue;
            }
            $command = trim((string) $process->command);
            if ($command === '') {
                continue;
            }

            $loopSeconds = $process->loopSeconds();
            if ($loopSeconds !== null) {
                $command = sprintf(
                    '/bin/bash -c %s',
                    escapeshellarg(sprintf(
                        'while true; do %s || true; sleep %d; done',
                        $command,
                        $loopSeconds,
                    )),
                );
            }

            $slug = $this->programSlug($site, $process);
            $keptSlugs[] = $slug;
            $mirrored[] = (string) $process->id;

            $oneshot = $process->isOneshot();
            $stopwait = $process->stopwaitsecs();

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
                    'autorestart' => $oneshot ? 'false' : 'true',
                    'startsecs' => $oneshot ? 0 : 1,
                    'redirect_stderr' => true,
                    // Horizon traps SIGTERM and drains in-flight jobs; give it
                    // room before supervisor SIGKILLs it unless the manifest pins.
                    'stopwaitsecs' => $stopwait ?? 3660,
                ],
            );
        }

        $this->managedPrograms($site)
            ->when($keptSlugs !== [], fn (Builder $q) => $q->whereNotIn('slug', $keptSlugs))
            ->update(['is_active' => false]);

        return $mirrored;
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
     *
     * @param  list<string>  $processIds  only these — the ones Supervisor now runs
     */
    private function teardownSystemdWorkers(Site $site, array $processIds): void
    {
        $site->loadMissing('processes');
        foreach ($site->processes as $process) {
            if (! in_array((string) $process->id, $processIds, true)) {
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

    public function programSlug(Site $site, SiteProcess $process): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $process->name) ?: 'worker';

        return $this->slugPrefix($site).Str::lower($name);
    }

    /**
     * Runtime role of the HOST that will run these daemons.
     * Local control-plane dogfood uses this process's DplyRuntime; remote BYO
     * servers use server meta when present, otherwise `all` (no role filter).
     *
     * @return array{0: string, 1: string}
     */
    private function hostRuntimeFor(Site $site): array
    {
        $dir = $site->effectiveEnvDirectory();
        $localRoot = base_path();
        if ($dir !== '' && @realpath($dir) !== false && @realpath($localRoot) !== false
            && realpath($dir) === realpath($localRoot)) {
            return [DplyRuntime::mode(), DplyRuntime::workerRole()];
        }

        $meta = is_array($site->server?->meta) ? $site->server->meta : [];
        $runtime = is_array($meta['dply_runtime'] ?? null) ? $meta['dply_runtime'] : [];
        $mode = is_string($runtime['mode'] ?? null) ? strtolower($runtime['mode']) : 'all';
        $role = is_string($runtime['worker_role'] ?? null) ? strtolower($runtime['worker_role']) : 'primary';

        return [$mode, $role];
    }
}
