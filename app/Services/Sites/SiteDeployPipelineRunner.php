<?php

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeployStep;
use App\Modules\Deploy\Services\DeployPhaseRunner;

/**
 * Runs ordered {@see SiteDeployStep} records over SSH in the deploy working directory.
 *
 * Each phase method returns a structured result so callers can both append
 * the human-readable log AND record per-step status/timing onto the
 * {@see SiteDeployment} (powering the live phase timeline):
 *
 *   ['log' => string, 'steps' => list<step>, 'ok' => bool]
 *
 * where each step matches the shape {@see DeployPhaseRunner}
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
    /** @return array<string, mixed> */
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
     * @param  ?callable(list<array<string, mixed>>): void  $onProgress  Fired
     *                                                                   before each step with the full ordered step list (completed steps carry
     *                                                                   their output; the current one is flagged `running`; the rest `pending`),
     *                                                                   so the caller can persist live progress for the phase timeline.
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    /** @return array<string, mixed> */
    public function runBuild(RemoteShell $ssh, Site $site, string $workingDirectory, ?callable $onProgress = null): array
    {
        return $this->runPhase($ssh, $site, $workingDirectory, SiteDeployStep::PHASE_BUILD, $onProgress);
    }

    /**
     * @param  ?callable(list<array<string, mixed>>): void  $onProgress
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    /** @return array<string, mixed> */
    public function runRelease(RemoteShell $ssh, Site $site, string $workingDirectory, ?callable $onProgress = null): array
    {
        return $this->runPhase($ssh, $site, $workingDirectory, SiteDeployStep::PHASE_RELEASE, $onProgress);
    }

    /**
     * Run user-authored RESTART-phase steps (the simple text pipeline's
     * "Restart" block) — after dply's own managed restart, for restarting
     * workers/daemons the app owns. A no-op when no restart steps exist.
     *
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    /** @return array<string, mixed> */
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
    /** @return array<string, mixed> */
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
            // A SELF-deploy is one whose target IS the box running this deploy
            // job, so `horizon:terminate` would bounce the Horizon executing it.
            // Matched purely by local-IP identity ({@see Server::isLocalDeployHost()})
            // — exact, so a customer's remote server can never trip it.
            $server = $site->server;
            $isSelfDeploy = $server !== null && $server->isLocalDeployHost();

            if ($isSelfDeploy) {
                // SELF-deploy: terminating Horizon inline would SIGKILL this very
                // deploy job (and any concurrent one) — it runs on the Horizon we'd
                // bounce. Hand the restart to a DETACHED drain-aware command that
                // waits for in-flight deploys to finish first, then terminates.
                // Falls back to the inline terminate only if the command isn't on
                // the box yet (the deploy that first ships it still runs old code).
                $parts[] = 'if [ -f artisan ] && php artisan list 2>/dev/null | grep -q "dply:self-horizon-restart"; then '
                    .'echo "[dply] self-deploy: deferring Horizon restart until in-flight deploys drain"; '
                    .'setsid nohup php artisan dply:self-horizon-restart >> /tmp/dply-self-horizon-restart.log 2>&1 </dev/null & '
                    .'elif [ -f artisan ] && php artisan list 2>/dev/null | grep -q "horizon:terminate"; then '
                    .'echo "[dply] self-deploy: drain command unavailable — inline horizon:terminate (legacy)"; '
                    .'php artisan horizon:terminate 2>&1 || true; '
                    .'fi';
            } else {
                // horizon:terminate; its supervisor/systemd unit (Restart=always) relaunches it on the new code.
                $parts[] = '{ [ -f artisan ] && php artisan list 2>/dev/null | grep -q "horizon:terminate" '
                    .'&& { echo "[dply] horizon:terminate"; php artisan horizon:terminate 2>&1 || echo "[dply] horizon:terminate skipped/failed (continuing)"; }; } || true';
            }
            $labels[] = 'Horizon';
        }

        if ($site->isLaravelFrameworkDetected()) {
            // Signal any queue:work workers to restart gracefully after the
            // current job — guarded on the command existing so an app without
            // the queue component (or that can't boot artisan) skips cleanly.
            $parts[] = '{ [ -f artisan ] && php artisan list 2>/dev/null | grep -q "queue:restart" '
                .'&& { echo "[dply] queue:restart"; php artisan queue:restart 2>&1 || true; }; } || true';
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
    /**
     * @param  ?callable(list<array<string, mixed>>): void  $onProgress
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    protected function runPhase(RemoteShell $ssh, Site $site, string $workingDirectory, string $phase, ?callable $onProgress = null): array
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

        // Live progress: before each step runs, emit the whole phase as
        // completed (with output) + the current step `running` + the rest
        // `pending`, so the polled timeline lights steps up one-by-one. SSH exec
        // is blocking, so a step's own output only lands once it finishes —
        // which is exactly the incremental "step done → next running" cadence.
        $emitProgress = static function (int $runningIndex) use ($onProgress, $ordered, &$steps): void {
            if ($onProgress === null) {
                return;
            }
            $full = [];
            foreach ($ordered as $i => $cfg) {
                /** @var SiteDeployStep $cfg */
                if ($i < count($steps)) {
                    $full[] = $steps[$i];
                } else {
                    $full[] = [
                        'step_id' => (string) $cfg->id,
                        'step_type' => (string) $cfg->step_type,
                        'command' => $cfg->custom_command,
                        'ok' => false,
                        'skipped' => false,
                        'running' => $i === $runningIndex,
                        'pending' => $i !== $runningIndex,
                        'output' => '',
                        'duration_ms' => 0,
                    ];
                }
            }
            $onProgress($full);
        };

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

        foreach ($ordered as $idx => $step) {
            /** @var SiteDeployStep $step */
            // Mark this step running (rest pending) before it executes.
            $emitProgress($idx);

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

        // Vite manifest safety net (build phase only). A Laravel/@vite app that
        // reaches cutover with no public/build/manifest.json 500s on every
        // request. That happens when the pipeline has NO asset-build step (e.g. a
        // site whose steps predate Vite detection): the build phase reports ok
        // with nothing built, and on atomic each release is a pristine clone with
        // no manifest (flat masked it by reusing a dir that still held an old
        // one). Rather than ship a broken release, auto-resolve by building the
        // assets here; if a manifest still can't be produced, fail the phase so
        // the deploy aborts BEFORE cutover instead of going live broken.
        if ($ok && $phase === SiteDeployStep::PHASE_BUILD) {
            $guard = $this->ensureViteManifest($ssh, $workingDirectory, $cwd);
            $log .= $guard['log'];
            if ($guard['step'] !== null) {
                $steps[] = $guard['step'];
            }
            if (! $guard['ok']) {
                $ok = false;
            }
        }

        return ['log' => $log, 'steps' => $steps, 'ok' => $ok];
    }

    /**
     * Self-heal a missing Vite manifest. Returns ok=false only when the app
     * genuinely needs a build (vite.config present, not opted out) and one still
     * can't be produced — so the caller fails the deploy before cutover.
     *
     * @return array{log: string, ok: bool, step: ?array<string, mixed>}
     */
    private function ensureViteManifest(RemoteShell $ssh, string $workingDirectory, string $cwd): array
    {
        $probe = $ssh->exec(sprintf(
            'cd %s 2>/dev/null && { vite=no; for f in vite.config.js vite.config.ts vite.config.mjs vite.config.cjs; do [ -f "$f" ] && vite=yes; done; '
            .'man=no; { [ -f public/build/manifest.json ] || [ -f public/build/.vite/manifest.json ]; } && man=yes; '
            .'optout=no; { [ -f package.json ] && tr -d " \t\n\r" < package.json 2>/dev/null | grep -q %s; } && optout=yes; '
            .'echo "DPLY_VITE vite=$vite man=$man optout=$optout"; }',
            $cwd,
            escapeshellarg('"dply":{[^{}]*"build":false')
        ), 30);

        if (preg_match('/DPLY_VITE vite=(\w+) man=(\w+) optout=(\w+)/', $probe, $m) !== 1) {
            return ['log' => '', 'ok' => true, 'step' => null]; // probe inconclusive — never block on uncertainty
        }
        [, $vite, $man, $optout] = $m;

        if ($vite !== 'yes' || $man === 'yes' || $optout === 'yes') {
            return ['log' => '', 'ok' => true, 'step' => null];
        }

        $log = "\n[dply] VITE GUARD → @vite app is missing public/build/manifest.json; auto-building assets so the release doesn't ship a 500…\n";

        // Reuse the node self-heal (mise/NodeSource/snap + loud-fail) via the
        // tooling prefix by synthesizing an npm step.
        $synthetic = new SiteDeployStep;
        $synthetic->step_type = SiteDeployStep::TYPE_NPM_RUN;
        $buildCmd = 'npm ci --include=dev && npm run build --if-present';
        $runCmd = $this->ensureToolingPrefix($synthetic, $buildCmd).$buildCmd;

        $start = microtime(true);
        $out = $ssh->exec(sprintf('cd %s && (%s) 2>&1; printf "\nDPLY_STEP_EXIT:%%s" "$?"', $cwd, $runCmd), 900);
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $log .= $out;

        $recheck = $ssh->exec(sprintf(
            'cd %s 2>/dev/null && { [ -f public/build/manifest.json ] || [ -f public/build/.vite/manifest.json ]; } && echo DPLY_MAN_OK || echo DPLY_MAN_MISSING',
            $cwd
        ), 30);
        $built = str_contains($recheck, 'DPLY_MAN_OK');

        $log .= $built
            ? "[dply] VITE GUARD → manifest built; release is safe to cut over.\n"
            : "[dply] VITE GUARD → still no manifest after auto-build — failing the deploy so a broken release can't go live. Fix Node/the build, or set {\"dply\":{\"build\":false}} in package.json to opt out.\n";

        return [
            'log' => $log,
            'ok' => $built,
            'step' => [
                'step_id' => 'vite_manifest_guard',
                'step_type' => 'vite_manifest_guard',
                'command' => $buildCmd,
                'ok' => $built,
                'output' => $out,
                'duration_ms' => $durationMs,
                'skipped' => false,
            ],
        ];
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
     * Build a shell prefix that ensures every tool the step needs is available.
     *
     * Previously this returned early on the first matching tool, so a step that
     * calls both `composer install` AND `npm run build` got the Composer prefix
     * but never had the mise PATH set up, causing "npm: command not found".
     *
     * Now we detect ALL needed tools first and emit one combined prefix that:
     *   1. Sets a shared PATH (mise shims + ~/.local/bin + /usr/local/bin)
     *   2. Self-heals Composer if missing (BYO server or php=none provisioning)
     *   3. Self-heals Node/npm via mise if missing (server provisioned without
     *      node, or mise PATH not active in the non-interactive SSH session)
     *
     * The node guard skips cleanly when no package.json exists so API-only apps
     * that happen to have an npm command in a shared custom step don't fail.
     */
    protected function ensureToolingPrefix(SiteDeployStep $step, string $cmd): string
    {
        $usesComposer = $step->step_type === SiteDeployStep::TYPE_COMPOSER_INSTALL
            || preg_match('/\bcomposer\s/', $cmd) === 1;

        $usesNode = in_array($step->step_type, [SiteDeployStep::TYPE_NPM_CI, SiteDeployStep::TYPE_NPM_RUN], true)
            || preg_match('/\b(npm|npx|node|yarn|pnpm)\s/', $cmd) === 1;

        if (! $usesComposer && ! $usesNode) {
            return '';
        }

        // Shared PATH setup — always emitted when any tool guard fires.
        $prefix = '{ export PATH="$HOME/.local/share/mise/shims:$HOME/.local/bin:/usr/local/bin:$PATH"; ';

        if ($usesComposer) {
            $prefix .= 'command -v composer >/dev/null 2>&1 || { '
                .'echo "[dply] composer not found — installing…"; '
                .'if [ -w /usr/local/bin ]; then DPLY_COMPOSER_DIR=/usr/local/bin; '
                .'else DPLY_COMPOSER_DIR="$HOME/.local/bin"; mkdir -p "$DPLY_COMPOSER_DIR"; fi; '
                .'curl -fsSL https://getcomposer.org/installer | php -- --install-dir="$DPLY_COMPOSER_DIR" --filename=composer; '
                .'}; ';
        }

        if ($usesNode) {
            // Node-only steps (no composer in same step): exit 0 cleanly when
            // there is no package.json — the app is API-only and doesn't need
            // a frontend build. When composer is also in the step we can't exit
            // early (composer install must still run), so we guard with an if.
            if (! $usesComposer) {
                $prefix .= '[ -f package.json ] || { echo "[dply] no package.json — skipping frontend build"; exit 0; }; ';
                // Opt-out: a package.json with {"dply": {"build": false}} skips
                // both the npm install and the asset build. Read without node
                // (which may not be installed yet) by flattening whitespace and
                // matching the dply block — keeps the opt-out honored even on a
                // box that has no Node toolchain at all.
                $prefix .= 'if [ -f package.json ] && tr -d " \\t\\n\\r" < package.json 2>/dev/null | grep -q \'"dply":{[^{}]*"build":false\'; then '
                    .'echo "[dply] package.json opts out of the build (dply.build=false) — skipping install & build"; exit 0; '
                    .'fi; ';
            }
            $prefix .= 'if [ -f package.json ]; then '
                // 1) Try mise (present on dply-provisioned boxes) to get node@lts.
                .'command -v npm >/dev/null 2>&1 || { '
                .'echo "[dply] node/npm not found — installing node@lts via mise…"; '
                .'if command -v mise >/dev/null 2>&1; then '
                .'mise use -g node@lts >/dev/null 2>&1 || mise install node@lts >/dev/null 2>&1; '
                .'eval "$(mise env -s bash 2>/dev/null)" 2>/dev/null || true; '
                .'export PATH="$HOME/.local/share/mise/shims:$PATH"; '
                .'fi; }; '
                // 2) Still missing (BYO box without mise) — install Node LTS from
                //    NodeSource, mirroring the on-demand composer install above.
                .'command -v npm >/dev/null 2>&1 || { '
                .'echo "[dply] installing Node LTS via NodeSource…"; '
                .'if [ "$(id -u)" = 0 ]; then '
                .'curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - >/dev/null 2>&1 && apt-get install -y --no-install-recommends nodejs >/dev/null 2>&1 || true; '
                .'elif command -v sudo >/dev/null 2>&1; then '
                .'curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash - >/dev/null 2>&1 && sudo apt-get install -y --no-install-recommends nodejs >/dev/null 2>&1 || true; '
                .'fi; }; '
                // 3) Still missing, but snap is available (stock Ubuntu) — install a
                //    current Node via snap. This is the path that actually works on
                //    BYO boxes with no mise where the NodeSource apt path failed, and
                //    it gives Node 20+/22 — Vite 7 / Tailwind 4 (oxide) reject the
                //    distro `nodejs` 18, so an old apt Node still can't build.
                .'command -v npm >/dev/null 2>&1 || { '
                .'if command -v snap >/dev/null 2>&1; then '
                .'echo "[dply] installing Node via snap…"; '
                .'if [ "$(id -u)" = 0 ]; then snap install node --classic --channel=lts/stable >/dev/null 2>&1 || snap install node --classic >/dev/null 2>&1 || true; '
                .'elif command -v sudo >/dev/null 2>&1; then sudo snap install node --classic --channel=lts/stable >/dev/null 2>&1 || sudo snap install node --classic >/dev/null 2>&1 || true; fi; '
                .'export PATH="/snap/bin:$PATH"; '
                .'fi; }; ';
            // 4) Still no npm. The app has a package.json and did NOT opt out
            //    (checked above), so it genuinely needs an asset build — without
            //    one it 500s on a missing public/build/manifest.json. FAIL the
            //    deploy loudly rather than shipping a green deploy over a broken
            //    site (the old behaviour silently exit 0'd and we shipped sites
            //    with no manifest). Operator fixes Node or sets the opt-out.
            //    Combined composer+node steps don't exit here — that would skip
            //    composer too; let the command run and surface npm's own failure.
            if (! $usesComposer) {
                $prefix .= 'command -v npm >/dev/null 2>&1 || { echo "[dply] npm unavailable and auto-install (mise/NodeSource/snap) all failed — failing the deploy so a missing public/build/manifest.json can not ship silently. Install Node on the server, or set {\"dply\":{\"build\":false}} in package.json to opt out, then redeploy."; exit 1; }; ';
            }
            $prefix .= 'fi; ';
        }

        return $prefix.'} && ';
    }
}
