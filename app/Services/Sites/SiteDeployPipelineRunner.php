<?php

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Models\SiteDeployStep;

/**
 * Runs ordered {@see SiteDeployStep} records over SSH in the deploy working directory.
 *
 * Each phase method returns a structured result so callers can both append
 * the human-readable log AND record per-step status/timing onto the
 * {@see \App\Models\SiteDeployment} (powering the live phase timeline):
 *
 *   ['log' => string, 'steps' => list<step>, 'ok' => bool]
 *
 * where each step matches the shape {@see \App\Services\Deploy\DeployPhaseRunner}
 * records: {step_id, step_type, command, ok, output, duration_ms, skipped}.
 * The runner does NOT throw on a failed step — it sets ok=false and stops the
 * phase so the caller can record the partial results before failing the deploy.
 */
class SiteDeployPipelineRunner
{
    public function __construct(
        private readonly DeployHookRunner $hookRunner,
    ) {}

    /**
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    public function run(RemoteShell $ssh, Site $site, string $workingDirectory): array
    {
        $build = $this->runBuild($ssh, $site, $workingDirectory);
        $release = $this->runRelease($ssh, $site, $workingDirectory);

        return [
            'log' => $build['log'].$release['log'],
            'steps' => [...$build['steps'], ...$release['steps']],
            'ok' => $build['ok'] && $release['ok'],
        ];
    }

    /**
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    public function runBuild(RemoteShell $ssh, Site $site, string $workingDirectory): array
    {
        return $this->runPhase($ssh, $site, $workingDirectory, SiteDeployStep::PHASE_BUILD);
    }

    /**
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    public function runRelease(RemoteShell $ssh, Site $site, string $workingDirectory): array
    {
        return $this->runPhase($ssh, $site, $workingDirectory, SiteDeployStep::PHASE_RELEASE);
    }

    /**
     * Run user-authored RESTART-phase steps (the simple text pipeline's
     * "Restart" block) — after dply's own managed restart, for restarting
     * workers/daemons the app owns. A no-op when no restart steps exist.
     *
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    public function runRestart(RemoteShell $ssh, Site $site, string $workingDirectory): array
    {
        return $this->runPhase($ssh, $site, $workingDirectory, SiteDeployStep::PHASE_RESTART);
    }

    /**
     * dply-owned managed restart, run after activation so long-running processes
     * pick up the new release: reload PHP-FPM (or reload Octane workers, which
     * serve the app instead of FPM), and bounce Horizon + queue workers when the
     * app uses them. Detection is repo-driven (composer packages / octane port);
     * each command is also guarded on the box and best-effort, so a worker that
     * isn't running never fails an otherwise-healthy deploy. Skipped entirely
     * when the operator disabled it (`meta.deploy.skip_managed_restart`) or for
     * static / container sites that have nothing FPM/worker-shaped to restart.
     *
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    public function runManagedRestart(RemoteShell $ssh, Site $site, string $workingDirectory): array
    {
        if ($site->isCustom() || $site->runtimeKey() === 'static') {
            return ['log' => '', 'steps' => [], 'ok' => true];
        }

        if ((bool) data_get($site->meta, 'deploy.skip_managed_restart', false)) {
            return ['log' => "\n[dply] managed restart disabled for this site — skipping FPM/worker restart.\n", 'steps' => [], 'ok' => true];
        }

        $parts = [];
        $labels = [];

        if ((bool) $site->octane_port || $site->resolvedLaravelPackageFlag('octane')) {
            // Octane serves the app itself — reload its workers onto the new release.
            $parts[] = '{ [ -f artisan ] && php artisan list 2>/dev/null | grep -q "octane:reload" '
                .'&& { echo "[dply] octane:reload"; php artisan octane:reload 2>&1 || echo "[dply] octane:reload skipped/failed (continuing)"; }; } || true';
            $labels[] = 'Octane';
        } elseif ($site->runtimeKey() === 'php') {
            // Non-Octane PHP: reload FPM so it serves the freshly swapped `current`.
            $parts[] = 'for svc in php8.5-fpm php8.4-fpm php8.3-fpm php-fpm; do sudo systemctl reload "$svc" 2>/dev/null && { echo "[dply] reloaded $svc"; break; }; done || true';
            $labels[] = 'PHP-FPM';
        }

        if ($site->resolvedLaravelPackageFlag('horizon')) {
            // horizon:terminate; its supervisor/systemd unit (Restart=always) relaunches it on the new code.
            $parts[] = '{ [ -f artisan ] && php artisan list 2>/dev/null | grep -q "horizon:terminate" '
                .'&& { echo "[dply] horizon:terminate"; php artisan horizon:terminate 2>&1 || echo "[dply] horizon:terminate skipped/failed (continuing)"; }; } || true';
            $labels[] = 'Horizon';
        }

        if ($site->isLaravelFrameworkDetected()) {
            // Signal any queue:work workers to restart gracefully after the current job.
            $parts[] = '{ [ -f artisan ] && { echo "[dply] queue:restart"; php artisan queue:restart 2>&1 || true; }; } || true';
            $labels[] = 'queue workers';
        }

        if ($parts === []) {
            return ['log' => "\n[dply] managed restart: nothing to restart for this runtime.\n", 'steps' => [], 'ok' => true];
        }

        $out = $ssh->exec(sprintf('cd %s 2>/dev/null; %s', escapeshellarg($workingDirectory), implode('; ', $parts)), 120);

        return [
            'log' => sprintf("\n--- managed restart (%s) ---\n%s\n", implode(', ', $labels), $out),
            'steps' => [[
                'step_id' => 'managed_restart',
                'step_type' => 'managed_restart',
                'command' => 'dply managed restart: '.implode(', ', $labels),
                'ok' => true,
                'output' => $out,
                'duration_ms' => 0,
                'skipped' => false,
            ]],
            'ok' => true,
        ];
    }

    /**
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    protected function runPhase(RemoteShell $ssh, Site $site, string $workingDirectory, string $phase): array
    {
        $site->loadMissing('deploySteps');
        $cwd = escapeshellarg($workingDirectory);
        $log = '';
        $steps = [];
        $ok = true;

        $ordered = $site->deploySteps
            ->where('phase', $phase)
            ->sortBy('sort_order')
            ->values();

        // Verbose preamble: show the working directory this phase runs in and
        // exactly what's on disk there (does `artisan` exist? is it a symlink?)
        // so a "Could not open input file: artisan" failure is self-explaining
        // from the log instead of guesswork.
        $log .= sprintf("\n[dply] phase '%s' → working dir: %s\n", $phase, $workingDirectory);
        $log .= sprintf("[dply] %d step(s) queued: %s\n", $ordered->count(), $ordered->pluck('step_type')->implode(', ') ?: '(none)');
        $probe = $ssh->exec(sprintf(
            'echo "=== [dply] PHASE PROBE: %2$s ==="; '
            .'echo "[dply] whoami=$(whoami)"; '
            .'echo "[dply] pwd=$(cd %1$s 2>/dev/null && pwd || echo UNREADABLE)"; '
            .'echo "[dply] is-symlink=$([ -L %1$s ] && echo yes || echo no)"; '
            .'echo "[dply] composer.json=$([ -f %1$s/composer.json ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] package.json=$([ -f %1$s/package.json ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] artisan=$([ -f %1$s/artisan ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] .env=$([ -f %1$s/.env ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] .env.example=$([ -f %1$s/.env.example ] && echo PRESENT || echo MISSING)"; '
            .'echo "[dply] git-sha:"; git -C %1$s rev-parse HEAD 2>&1 || echo "(n/a)"; '
            .'echo "[dply] git-branch:"; git -C %1$s branch --show-current 2>&1 || echo "(n/a)"; '
            .'echo "[dply] git-status:"; git -C %1$s status --short 2>&1 || echo "(n/a)"; '
            .'echo "[dply] php:"; php --version 2>&1 | head -n 1 || echo "(php not found)"; '
            .'echo "[dply] composer:"; composer --version 2>&1 | head -n 1 || echo "(composer not found)"; '
            .'echo "[dply] node:"; node --version 2>&1 || echo "(node not found)"; '
            .'echo "[dply] disk:"; df -h %1$s 2>&1; '
            .'echo "[dply] ls:"; ls -la %1$s 2>&1; '
            .'echo "=== [dply] END PHASE PROBE ==="',
            $cwd,
            $phase
        ), 30);
        $log .= $probe."\n";

        foreach ($ordered as $step) {
            /** @var SiteDeployStep $step */
            $cmd = $this->resolveShellCommand($step);
            if ($cmd === null || $cmd === '') {
                // A step with no resolvable command (e.g. an empty custom
                // step) is a no-op — record it as skipped so the timeline
                // still accounts for it, and run its after-step hooks.
                $hookLog = $this->hookRunner->runAfterStep($ssh, $site, (string) $step->id, $workingDirectory);
                $log .= $hookLog;
                $steps[] = [
                    'step_id' => (string) $step->id,
                    'step_type' => (string) $step->step_type,
                    'command' => null,
                    'ok' => true,
                    'output' => $hookLog,
                    'duration_ms' => 0,
                    'skipped' => true,
                ];

                continue;
            }

            $timeout = max(30, min(3600, (int) ($step->timeout_seconds ?? 900)));
            $header = "\n--- pipeline ({$phase}): {$step->step_type} ---\n";
            $log .= $header;
            // SSH exec does not surface non-zero exit codes, so append an exit
            // marker and read it back — otherwise a failed build/migration
            // would be recorded (and shown on the timeline) as success.
            // The recorded `command` stays clean; only the executed command is
            // prefixed with any tooling guard (e.g. ensure Composer is present).
            $runCmd = $this->ensureToolingPrefix($step, $cmd).$cmd;
            // Echo the fully-resolved shell line (incl. the `cd`) so the log
            // shows precisely what ran and where — invaluable when a step fails.
            $log .= sprintf("[dply] exec (timeout %ds): cd %s && %s\n", $timeout, $workingDirectory, $runCmd);
            $start = microtime(true);
            $stepOut = $ssh->exec(
                sprintf('cd %s && (%s) 2>&1; printf "\nDPLY_STEP_EXIT:%%s" "$?"', $cwd, $runCmd),
                $timeout
            );
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $log .= $stepOut;
            $hookLog = $this->hookRunner->runAfterStep($ssh, $site, (string) $step->id, $workingDirectory);
            $log .= $hookLog;

            $stepOk = $this->outputSucceeded($stepOut) && $this->outputSucceeded($hookLog);
            $steps[] = [
                'step_id' => (string) $step->id,
                'step_type' => (string) $step->step_type,
                'command' => $cmd,
                'ok' => $stepOk,
                'output' => $stepOut.$hookLog,
                'duration_ms' => $durationMs,
                'skipped' => false,
            ];

            if (! $stepOk) {
                // Abort the phase on first failure so later steps don't pile
                // onto broken state. The caller records these results, then
                // fails the deploy.
                $ok = false;
                break;
            }
        }

        return ['log' => $log, 'steps' => $steps, 'ok' => $ok];
    }

    /**
     * Read the appended exit markers (step + after-step hooks) and report
     * whether everything exited 0. Output with no marker (legacy commands,
     * fake shells in tests) is treated as success.
     */
    protected function outputSucceeded(string $output): bool
    {
        if (preg_match_all('/DPLY_(?:STEP|HOOK)_EXIT:(\d+)/', $output, $m)) {
            foreach ($m[1] as $code) {
                if ((int) $code !== 0) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function resolveShellCommand(SiteDeployStep $step): ?string
    {
        return SiteDeployPipelineCommands::fragmentFor(
            $step->step_type,
            trim((string) ($step->custom_command ?? ''))
        );
    }

    /**
     * Guard prefix prepended (with `&&`) to a step that invokes Composer.
     *
     * A PHP deploy can land on a box without Composer — a BYO server, a host
     * provisioned with php=none, or one where `install_composer` was off —
     * or with Composer installed outside the non-interactive SSH PATH. Either
     * way the step dies with "composer: command not found" (exit 127). This
     * puts the relevant bin dirs on PATH and installs Composer from
     * getcomposer.org if it is still missing, so the step can self-heal
     * instead of failing. Returns '' for non-Composer steps.
     *
     * Deploy steps run as the unprivileged deploy user (e.g. `dply`), which
     * cannot write to /usr/local/bin — the official installer aborts there
     * with "installation directory is not writable" (exit 1). So we only use
     * /usr/local/bin when it is actually writable (root/provisioning) and
     * otherwise install into the user-local ~/.local/bin, which we add to
     * PATH so the freshly installed binary is found within the same step.
     */
    protected function ensureToolingPrefix(SiteDeployStep $step, string $cmd): string
    {
        $usesComposer = $step->step_type === SiteDeployStep::TYPE_COMPOSER_INSTALL
            || preg_match('/\bcomposer\s/', $cmd) === 1;

        if ($usesComposer) {
            return '{ export PATH="$HOME/.local/bin:/usr/local/bin:$PATH"; '
                .'command -v composer >/dev/null 2>&1 || { '
                .'echo "[dply] composer not found — installing…"; '
                .'if [ -w /usr/local/bin ]; then DPLY_COMPOSER_DIR=/usr/local/bin; '
                .'else DPLY_COMPOSER_DIR="$HOME/.local/bin"; mkdir -p "$DPLY_COMPOSER_DIR"; fi; '
                .'curl -fsSL https://getcomposer.org/installer | php -- --install-dir="$DPLY_COMPOSER_DIR" --filename=composer; '
                .'}; } && ';
        }

        // npm/node steps: the base box ships with mise but no Node, so `npm ci`
        // dies with "npm: command not found" (and a Laravel/Vite app then 500s
        // with ViteManifestNotFoundException). Self-heal like Composer: put the
        // mise shims on PATH, install node@lts via mise if npm is still missing.
        // And skip cleanly on an API-only app that has no package.json.
        $usesNode = in_array($step->step_type, [SiteDeployStep::TYPE_NPM_CI, SiteDeployStep::TYPE_NPM_RUN], true)
            || preg_match('/\b(npm|npx|node|yarn|pnpm)\s/', $cmd) === 1;

        if ($usesNode) {
            return '{ '
                .'[ -f package.json ] || { echo "[dply] no package.json — skipping frontend build"; exit 0; }; '
                .'export PATH="$HOME/.local/share/mise/shims:$HOME/.local/bin:$PATH"; '
                .'command -v npm >/dev/null 2>&1 || { '
                .'echo "[dply] node/npm not found — installing node@lts via mise…"; '
                .'if command -v mise >/dev/null 2>&1; then '
                .'mise use -g node@lts >/dev/null 2>&1 || mise install node@lts >/dev/null 2>&1; '
                .'eval "$(mise env -s bash 2>/dev/null)" 2>/dev/null || true; '
                .'export PATH="$HOME/.local/share/mise/shims:$PATH"; '
                .'fi; }; '
                .'command -v npm >/dev/null 2>&1 || { echo "[dply] npm unavailable — install Node on the server, then redeploy."; exit 1; }; '
                .'} && ';
        }

        return '';
    }
}
