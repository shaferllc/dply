<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
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
     * Per the strategy memo: "Restart is dply-owned, not user-editable
     * (preserves atomic-release/FPM-reload correctness)." Users don't
     * author steps in this phase.
     *
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory
     * @return list<array{step_id: string, step_type: string, command: string, ok: bool, output: string, duration_ms: int}>
     */
    public function runRestart(Site $site, ?Closure $shellFactory = null): array
    {
        if ($site->isCustom()) {
            return [];
        }

        $runtime = $site->runtimeKey();
        if ($runtime === 'static') {
            return [];
        }

        $command = $runtime === 'php'
            ? sprintf('sudo systemctl reload php%s-fpm', $site->phpVersion() ?? '8.3')
            : sprintf('sudo systemctl restart %s', escapeshellarg('dply-site-'.$site->id.'.service'));

        return [$this->runOne($site, 'restart', SiteDeployStep::PHASE_RESTART, $command, $this->repositoryBase($site), $shellFactory)];
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

            $result = $this->execAt($shell, $cwd, $cmd, (int) ($step->timeout_seconds ?? 900));
            $result['step_id'] = (string) $step->id;
            $result['step_type'] = $step->step_type;
            $result['command'] = $cmd;
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
    private function execAt(RemoteShell $shell, string $cwd, string $command, int $timeout): array
    {
        $wrapped = sprintf('cd %s && %s', escapeshellarg($cwd), $command);
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

        return $base !== '' ? rtrim($base, '/') : '/var/www/'.$site->slug;
    }
}
