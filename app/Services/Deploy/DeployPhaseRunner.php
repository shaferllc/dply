<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Sites\DeployHookRunner;
use App\Services\SshConnection;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Walks a site through the four deploy phases — BUILD → SWAP → RELEASE
 * → RESTART — against a release directory on the server.
 *
 * Per the strategy memo: "Named phases: build → swap → release → restart.
 * Each phase a separate SiteDeployStep so the UI shows per-phase status,
 * timing, and logs."
 *
 * Each phase method:
 *   - Pulls the relevant SiteDeployStep rows (or runs the dply-owned
 *     swap/restart logic for those phases).
 *   - For each step: resolves the shell command via SiteDeployStep::commandFor(),
 *     wraps in `cd <release_dir> && <command>`, runs via the RemoteShell.
 *   - Captures per-step result: ok/failed, output, duration_ms.
 *   - Aborts the phase on the first failed step (consistent with how
 *     the existing deploy pipeline treats step failures).
 *
 * Result shape per phase: list of step result arrays. Callers persist
 * to logs / SiteDeployment audit rows / Horizon alerts.
 *
 * Test seam: the optional $shellFactory closure lets tests pass a
 * fake RemoteShell. Production omits it; the constructor builds a
 * SshConnection per server.
 */
class DeployPhaseRunner
{
    public function __construct(
        private readonly DeployHookRunner $hookRunner,
    ) {}

    /**
     * Walk the build phase steps in declaration order. Stops at the
     * first failure and returns results for every step attempted.
     *
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory
     * @return list<array{step_id: string, step_type: string, command: ?string, ok: bool, output: string, duration_ms: int}>
     */
    public function runBuild(Site $site, string $releaseDir, ?Closure $shellFactory = null): array
    {
        return $this->runPhase($site, SiteDeployStep::PHASE_BUILD, $releaseDir, $shellFactory);
    }

    /**
     * Atomic swap: flip the `current` symlink to point at the new
     * release directory. Skipped for non-atomic deploys (the file
     * tree is shared between releases — there's no symlink to move).
     *
     * Returns a single-entry result list so the caller can record
     * the swap alongside the build/release/restart entries with the
     * same shape.
     *
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory
     * @return list<array{step_id: string, step_type: string, command: string, ok: bool, output: string, duration_ms: int}>
     */
    public function runSwap(Site $site, string $releaseDir, ?Closure $shellFactory = null): array
    {
        if (! $site->isAtomicDeploys()) {
            return [];
        }

        $base = $this->repositoryBase($site);
        $current = rtrim($base, '/').'/current';
        $command = sprintf(
            'ln -sfn %s %s',
            escapeshellarg(rtrim($releaseDir, '/')),
            escapeshellarg($current),
        );

        return [$this->runOne($site, 'swap', SiteDeployStep::PHASE_SWAP, $command, $base, $shellFactory)];
    }

    /**
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory
     * @return list<array{step_id: string, step_type: string, command: ?string, ok: bool, output: string, duration_ms: int}>
     */
    public function runRelease(Site $site, string $releaseDir, ?Closure $shellFactory = null): array
    {
        // Release steps run from the active release path: for atomic
        // deploys that's the `current` symlink we just flipped; for
        // simple deploys it's the same release directory.
        $cwd = $site->isAtomicDeploys()
            ? rtrim($this->repositoryBase($site), '/').'/current'
            : $releaseDir;

        return $this->runPhase($site, SiteDeployStep::PHASE_RELEASE, $cwd, $shellFactory);
    }

    /**
     * Restart whatever process serves traffic for this site:
     *   - PHP: `sudo systemctl reload php{version}-fpm` so FPM picks up
     *     the new release without dropping connections.
     *   - Static: no-op — NGINX already serves the new files after the swap.
     *   - Other runtimes: `sudo systemctl restart dply-site-{id}.service`
     *     to bring the long-running process onto the new release.
     *
     * The dply-owned reload/restart runs FIRST (preserves atomic-release/FPM-reload
     * correctness). Any user-authored PHASE_RESTART steps then run on the live
     * release path — this is the "restart your own workers/daemons" escape hatch.
     * If the dply-owned reload fails we abort before the user steps, mirroring
     * runPhase's stop-on-first-failure semantics.
     *
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory
     * @return list<array{step_id: string, step_type: string, command: ?string, ok: bool, output: string, duration_ms: int}>
     */
    public function runRestart(Site $site, ?Closure $shellFactory = null): array
    {
        // Container/custom-runtime sites restart through their own runtime
        // manager, not this phase.
        if ($site->isCustom()) {
            return [];
        }

        $results = [];
        $runtime = $site->runtimeKey();

        // Operators can opt out of the dply-owned reload (e.g. they restart the
        // service themselves in a restart step). User restart steps still run.
        $managedRestartDisabled = (bool) data_get($site->meta, 'deploy.skip_managed_restart', false);

        // dply-owned reload/restart. Static sites skip it — NGINX already serves
        // the new files after the swap — but user restart steps may still apply.
        if ($runtime !== 'static' && ! $managedRestartDisabled) {
            $command = $runtime === 'php'
                ? sprintf('sudo systemctl reload php%s-fpm', $site->phpVersion() ?? '8.3')
                : sprintf('sudo systemctl restart %s', escapeshellarg('dply-site-'.$site->id.'.service'));

            $owned = $this->runOne($site, 'restart', SiteDeployStep::PHASE_RESTART, $command, $this->repositoryBase($site), $shellFactory);
            $results[] = $owned;

            if (! ($owned['ok'] ?? false)) {
                return $results;
            }
        }

        // User restart steps run AFTER the dply-owned reload, on the live path
        // (the `current` symlink for atomic deploys, the checkout otherwise).
        $liveCwd = $site->isAtomicDeploys()
            ? rtrim($this->repositoryBase($site), '/').'/current'
            : $this->repositoryBase($site);

        return array_merge($results, $this->runPhase($site, SiteDeployStep::PHASE_RESTART, $liveCwd, $shellFactory));
    }

    /**
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory
     * @return list<array<string, mixed>>
     */
    private function runPhase(Site $site, string $phase, string $cwd, ?Closure $shellFactory): array
    {
        $steps = $site->deploySteps()
            ->phase($phase)
            ->orderBy('sort_order')
            ->get();

        if ($steps->isEmpty()) {
            return [];
        }

        $shell = $this->resolveShell($site, $shellFactory);
        $stepEnv = [
            'DEPLOY_PATH' => $cwd,
            'SITE_NAME' => (string) $site->name,
            'RELEASE_REF' => (string) ($site->git_branch ?? ''),
        ];
        $results = [];
        foreach ($steps as $step) {
            $cmd = $step->commandFor();
            if ($cmd === null) {
                $results[] = [
                    'step_id' => (string) $step->id,
                    'step_type' => $step->step_type,
                    'command' => null,
                    'ok' => true,
                    'output' => '',
                    'duration_ms' => 0,
                    'skipped' => true,
                ];

                continue;
            }

            $result = $this->execAt($shell, $cwd, $cmd, (int) ($step->timeout_seconds ?? 900), $stepEnv);
            $result['step_id'] = (string) $step->id;
            $result['step_type'] = $step->step_type;
            $result['command'] = $cmd;
            $hookLog = $this->hookRunner->runAfterStep($shell, $site, (string) $step->id, $cwd);
            if ($hookLog !== '') {
                $result['output'] = ($result['output'] ?? '').$hookLog;
            }
            $results[] = $result;

            if (! $result['ok']) {
                // Abort on first failure so the rest of the phase doesn't
                // pile on top of broken state. Caller treats the absence
                // of subsequent results as "phase aborted".
                break;
            }
        }

        return $results;
    }

    /**
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory
     * @return array<string, mixed>
     */
    private function runOne(Site $site, string $stepId, string $phase, string $command, string $cwd, ?Closure $shellFactory): array
    {
        $shell = $this->resolveShell($site, $shellFactory);
        $result = $this->execAt($shell, $cwd, $command, 60);
        $result['step_id'] = $stepId;
        $result['step_type'] = $phase;
        $result['command'] = $command;

        return $result;
    }

    /**
     * @return array{ok: bool, output: string, duration_ms: int}
     */
    private function execAt(RemoteShell $shell, string $cwd, string $command, int $timeout, array $env = []): array
    {
        // Export the documented deploy-step env (DEPLOY_PATH, SITE_NAME,
        // RELEASE_REF, …) before running the command so custom scripts that
        // reference them — and run under `set -u` — don't fail with
        // "unbound variable".
        $exports = '';
        foreach ($env as $key => $value) {
            $exports .= sprintf('export %s=%s; ', $key, escapeshellarg((string) $value));
        }
        $wrapped = sprintf('cd %s && %s%s', escapeshellarg($cwd), $exports, $command);
        $start = Carbon::now();
        try {
            $output = $shell->exec($wrapped, $timeout);
            $ok = true;
        } catch (\Throwable $e) {
            $output = $e->getMessage();
            $ok = false;
        }
        $durationMs = (int) Carbon::now()->diffInMilliseconds($start, true);

        return [
            'ok' => $ok,
            'output' => $output,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory
     */
    private function resolveShell(Site $site, ?Closure $shellFactory): RemoteShell
    {
        $server = $site->server;
        if ($server === null || ! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        return $shellFactory !== null ? $shellFactory($server) : new SshConnection($server);
    }

    private function repositoryBase(Site $site): string
    {
        $base = trim((string) ($site->repository_path ?? ''));

        return $base !== '' ? rtrim($base, '/') : rtrim($site->conventionalRepositoryPath(), '/');
    }
}
