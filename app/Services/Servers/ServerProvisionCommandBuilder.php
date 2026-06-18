<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\RunSetupScriptJob;
use App\Models\Server;
use App\Models\UserSshKey;
use App\Services\Servers\Concerns\BuildsProvisionBootstrap;
use App\Services\Servers\Concerns\BuildsProvisionDatabaseStack;
use App\Services\Servers\Concerns\BuildsProvisionRoles;
use App\Services\Servers\Concerns\BuildsProvisionRuntimes;
use App\Services\Servers\Concerns\BuildsProvisionWebserverPhp;
use App\Support\Servers\DedicatedCacheServerProvisionConfig;
use App\Support\Servers\DedicatedDatabaseServerProvisionConfig;

/**
 * Builds a bash script (list of lines) from servers.meta stack fields set at create time.
 *
 * Target images: Ubuntu 24.04 LTS (default DigitalOcean image) and compatible Debian/Ubuntu.
 */
final class ServerProvisionCommandBuilder
{
    use BuildsProvisionBootstrap;
    use BuildsProvisionDatabaseStack;
    use BuildsProvisionRoles;
    use BuildsProvisionRuntimes;
    use BuildsProvisionWebserverPhp;

    private const STEP_PREFIX = '[dply-step] ';

    /**
     * Sibling marker emitted by {@see withStep()} once a step finishes.
     * Format: `[dply-step-end] <label>\t<seconds>` for normal completion,
     * or `[dply-step-end] <label>\t0\tresumed` for the resume-skip path.
     * Parsed by {@see App\Support\Servers\ProvisionStepDurations} into
     * server_provision_step_runs rows so the journey UI can render
     * data-driven ETAs ("Avg 1m 25s from 12 previous runs") in place
     * of the static "Usually a few minutes" copy.
     */
    private const STEP_END_PREFIX = '[dply-step-end] ';

    private const VERIFY_PREFIX = '[dply-verify] ';

    private const ROLLBACK_PREFIX = '[dply-rollback] ';

    /**
     * In-progress server, populated for the duration of a single build()
     * call so role helpers can read meta without threading it through
     * every signature. Reset to null on exit so a builder reused across
     * builds doesn't leak state.
     */
    private ?Server $server = null;

    /** @return list<string> */
    /** @return array<string, mixed> */
    /**
     * @return list<string>
     */
    public function build(Server $server): array
    {
        $this->server = $server;
        try {
            return $this->buildInner($server);
        } finally {
            $this->server = null;
        }
    }

    /**
     * @return list<string>
     */
    private function buildInner(Server $server): array
    {
        $meta = $server->meta ?? [];
        if (! is_array($meta) || empty($meta['server_role']) || ! is_string($meta['server_role'])) {
            return [];
        }

        $role = $meta['server_role'];
        if (! $this->isAllowed('server_roles', $role)) {
            return [];
        }

        $web = $this->coalesceId('webservers', $meta['webserver'] ?? null, 'nginx');
        $php = $this->coalesceId('php_versions', $meta['php_version'] ?? null, '8.3');
        $database = $this->coalesceId('databases', $meta['database'] ?? null, 'mysql84');
        $cache = $this->coalesceId('cache_services', $meta['cache_service'] ?? null, 'redis');
        if ($role === 'database') {
            $cache = 'none';
        }
        $layout = $this->deployLayout($server);

        $lines = [];
        $lines[] = 'echo "[dply] provision start role='.$role.' web='.$web.' php='.$php.' db='.$database.' cache='.$cache.'"';

        // Stamp wizard inputs as environment defaults early. The
        // installed-stack emit at the end reads these, then live-probes
        // versions. Database is overwritten inside its install branch
        // (low-mem fallback flips it to sqlite3). PHP / webserver /
        // cache_service have no fallback paths today, so the wizard
        // value IS the installed value — we set them once here.
        $lines[] = 'export DPLY_INSTALLED_PHP_VERSION='.escapeshellarg($php);
        $lines[] = 'export DPLY_INSTALLED_WEBSERVER='.escapeshellarg($web);
        $lines[] = 'export DPLY_INSTALLED_CACHE_SERVICE='.escapeshellarg($cache);
        // Default; overridden inside the database install conditional.
        $lines[] = 'export DPLY_INSTALLED_DATABASE='.escapeshellarg($database);

        $lines = array_merge($lines, $this->dplyDeployUserBootstrap($server));
        $lines = array_merge($lines, $this->bootstrap());
        $lines = array_merge($lines, $this->prefetchPackages($web, $php, $database, $cache));
        $lines = array_merge($lines, $this->createDeployLayout($layout));
        $lines = array_merge($lines, match ($role) {
            'application' => $this->roleApplication($web, $php, $database, $cache, $layout),
            'docker' => $this->roleDocker($web, $php, $database, $cache, $layout),
            'worker' => $this->roleWorker($web, $php, $database, $layout),
            'database' => $this->roleDatabase($database, $layout),
            'redis' => $this->roleCacheHost($cache),
            'valkey' => $this->roleCacheHost('valkey'),
            'load_balancer' => $this->roleLoadBalancer($layout),
            'plain' => $this->rolePlain($layout),
            default => [],
        });

        // Join any background runtime installs (parallel_runtimes) before we
        // verify — node/etc. must be present for verification + first deploy.
        $lines = array_merge($lines, $this->waitForBackgroundRuntimes());
        $lines = array_merge($lines, $this->metricsAgent($server));
        $lines = array_merge($lines, $this->verificationCommands($role, $web, $php, $database, $cache));
        $lines = array_merge($lines, $this->deployGitIdentity($server));
        $lines = array_merge($lines, $this->finalize($role));
        $lines = array_merge($lines, $this->emitInstalledStack());

        return $lines;
    }


    /**
     * @return list<array{type:string,key:string,label:string,content:string,metadata:array<string,mixed>}>
     */
    public function buildArtifacts(Server $server): array
    {
        $meta = $server->meta ?? [];
        if (! is_array($meta) || empty($meta['server_role']) || ! is_string($meta['server_role'])) {
            return [];
        }

        $role = $meta['server_role'];
        $web = $this->coalesceId('webservers', $meta['webserver'] ?? null, 'nginx');
        $php = $this->coalesceId('php_versions', $meta['php_version'] ?? null, '8.3');
        $database = $this->coalesceId('databases', $meta['database'] ?? null, 'mysql84');
        $cache = $this->coalesceId('cache_services', $meta['cache_service'] ?? null, 'redis');
        $layout = $this->deployLayout($server);

        $artifacts = [[
            'type' => 'deploy_layout',
            'key' => 'layout',
            'label' => 'Deploy layout',
            'content' => json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            'metadata' => ['role' => $role],
        ]];

        foreach ($this->renderedConfigs($role, $web, $php, $layout) as $key => $config) {
            $artifacts[] = [
                'type' => 'rendered_config',
                'key' => $key,
                'label' => $config['label'],
                'content' => $config['content'],
                'metadata' => ['path' => $config['path']],
            ];
        }

        $artifacts[] = [
            'type' => 'verification_plan',
            'key' => 'verification-plan',
            'label' => 'Verification plan',
            'content' => implode("\n", $this->verificationLabels($role, $web, $php, $database, $cache)),
            'metadata' => compact('role', 'web', 'php', 'database', 'cache'),
        ];

        $artifacts[] = [
            'type' => 'stack_summary',
            'key' => 'stack-summary',
            'label' => 'Installed stack',
            'content' => json_encode([
                'role' => $role,
                'webserver' => $web,
                'php_version' => $php,
                'database' => $database,
                'cache_service' => $cache,
                'deploy_user' => (string) config('server_provision.deploy_ssh_user', 'dply'),
                'expected_services' => array_keys($this->verificationLabels($role, $web, $php, $database, $cache)),
                'paths' => [
                    // Server-level paths only — per-site current/shared/releases
                    // get reported by each Site's own provisioning artifact.
                    'web_root' => $layout['web_root'],
                    'logs' => $layout['logs'],
                ],
                'config_files' => collect($this->renderedConfigs($role, $web, $php, $layout))
                    ->map(fn (array $config): string => $config['path'])
                    ->values()
                    ->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            'metadata' => [
                'role' => $role,
                'webserver' => $web,
                'php_version' => $php,
                'database' => $database,
                'cache_service' => $cache,
                'deploy_user' => (string) config('server_provision.deploy_ssh_user', 'dply'),
                'expected_services' => array_keys($this->verificationLabels($role, $web, $php, $database, $cache)),
                'paths' => [
                    // Server-level paths only — per-site current/shared/releases
                    // get reported by each Site's own provisioning artifact.
                    'web_root' => $layout['web_root'],
                    'logs' => $layout['logs'],
                ],
                'config_files' => collect($this->renderedConfigs($role, $web, $php, $layout))
                    ->map(fn (array $config): string => $config['path'])
                    ->values()
                    ->all(),
            ],
        ];

        $artifacts[] = [
            'type' => 'rollback_plan',
            'key' => 'rollback-plan',
            'label' => 'Rollback plan',
            'content' => implode("\n", [
                'Restore config files written through dply_write_file.',
                'Disable generated systemd unit symlinks when they were created in this run.',
                'Remove new config files that did not exist before provisioning.',
            ]),
            'metadata' => ['strategy' => 'best_effort'],
        ];

        return $artifacts;
    }


    private function writeFileWithRollback(string $path, string $content): string
    {
        $encodedPath = base64_encode($path);
        $encodedContent = base64_encode($content);

        return 'dply_write_file '.escapeshellarg($encodedPath).' '.escapeshellarg($encodedContent);
    }

    private function isAllowed(string $section, string $id): bool
    {
        $rows = config('server_provision_options.'.$section, []);

        return collect(is_array($rows) ? $rows : [])->pluck('id')->contains($id);
    }

    private function coalesceId(string $section, mixed $value, string $fallback): string
    {
        if (is_string($value) && $this->isAllowed($section, $value)) {
            return $value;
        }

        return $this->isAllowed($section, $fallback) ? $fallback : (string) $value;
    }

    private function stepMarker(string $label): string
    {
        return 'echo '.escapeshellarg(self::STEP_PREFIX.$label);
    }

    /**
     * Emit a tab-delimited end marker:
     *   `[dply-step-end] <label>\t<seconds>` (normal)
     *   `[dply-step-end] <label>\t0\tresumed` (resume-skip)
     *
     * Tab-delimited so {@see App\Support\Servers\ProvisionStepDurations::parse()}
     * can split fields without ambiguity (labels can include spaces).
     *
     * `$secondsExpression` is interpolated raw into the printf arg so the
     * caller can pass a bash expression like `$((NOW - START))`. For
     * resumed rows the caller passes the literal `0`.
     */
    private function stepEndMarker(string $label, string $secondsExpression, bool $resumed = false): string
    {
        $resumedSuffix = $resumed ? '\tresumed' : '';
        // Both label and seconds are passed as printf args (not embedded
        // in the format string) so any character a label could contain
        // — quotes, $, backticks — stays inert. The format string itself
        // is single-quoted bash so printf sees `\t` / `\n` as escapes.
        $format = self::STEP_END_PREFIX.'%s\t%s'.$resumedSuffix.'\n';

        return sprintf(
            "printf '%s' %s %s",
            $format,
            escapeshellarg($label),
            $secondsExpression,
        );
    }

    /**
     * Wrap a step's commands in a resume-on-failure guard.
     *
     * On success, the step writes /var/lib/dply/steps/<key>.done.
     * On the next run (manual "Resume install" or auto-retry), the
     * presence of that file short-circuits the entire step body —
     * the journey output reads "[dply-step] X (resumed: already
     * done)" and execution moves immediately to the next step.
     *
     * The marker key is a hash of label + step body, NOT just the
     * label. If the operator changes the step's bash (adds a flag,
     * tweaks an apt package list), the hash changes, the old marker
     * no longer matches, and the step re-executes. Without this,
     * iterating on the script while resuming would silently skip
     * the new code — a real foot-gun.
     *
     * Markers live under /var/lib/dply/steps/ which is created on
     * demand. Force-reinstall mode wipes the directory at bootstrap
     * (see bootstrap()).
     *
     * @param  array<string, mixed> $commands
     * @return list<string>
     */
    private function withStep(string $label, array $commands): array
    {
        $body = implode("\n", $commands);
        $key = substr(md5($label.'|'.$body), 0, 16);
        $marker = '/var/lib/dply/steps/'.$key.'.done';
        $skipMarker = $this->stepMarker($label.' (resumed: already done)');

        // Each step is wrapped with a wall-clock duration capture that
        // emits a `[dply-step-end]` marker on exit. ProvisionStepDurations
        // parses those markers when the task reaches a terminal state and
        // inserts one row per step into server_provision_step_runs, which
        // ProvisionStepEtaService averages to power the "Avg X min from N
        // runs" ETA in the journey UI.
        //
        // _DPLY_STEP_STARTED is per-step so nested withStep calls (none
        // today, but defensively) don't clobber each other.
        $endMarker = $this->stepEndMarker($label, '"$(( $(date +%s) - _DPLY_STEP_STARTED ))"');
        $resumedEndMarker = $this->stepEndMarker($label, '0', resumed: true);

        return [
            // The whole step is one composite shell command so the
            // marker write only happens if every inner command
            // succeeded (set -e on the outer script propagates). The
            // `else` branch logs the skip so the journey UI still
            // sees a step marker line and can paint it as completed.
            implode("\n", [
                'if [ ! -f '.escapeshellarg($marker).' ]; then',
                '  '.$this->stepMarker($label),
                '  _DPLY_STEP_STARTED=$(date +%s)',
                $body,
                '  install -d -m 0755 /var/lib/dply/steps',
                '  touch '.escapeshellarg($marker),
                '  '.$endMarker,
                'else',
                '  '.$skipMarker,
                '  '.$resumedEndMarker,
                'fi',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed> $packages
     * @return list<string>
     */
    private function ensurePackagesInstalled(array $packages, string $alreadyInstalledMessage): array
    {
        $packages = array_values(array_filter($packages, fn (string $package): bool => trim($package) !== ''));
        if ($packages === []) {
            return [];
        }

        $checks = array_map(
            fn (string $package): string => 'dpkg -s '.escapeshellarg($package).' >/dev/null 2>&1',
            $packages,
        );

        if ($this->forceReinstall()) {
            return [
                'dply_wait_for_apt_locks || exit 100',
                'for _dply_apt_attempt in 1 2 3 4 5 6; do',
                '  dply_wait_for_apt_locks || exit 100',
                '  _dply_apt_log=$(DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends '.implode(' ', $packages).' 2>&1) || true',
                '  echo "$_dply_apt_log"',
                '  if echo "$_dply_apt_log" | grep -qE "Could not get lock|Unable to acquire the dpkg frontend lock|is held by process"; then',
                '    echo "[dply] apt lock during install — sleeping 15s before retry $_dply_apt_attempt/6."',
                '    sleep 15',
                '    continue',
                '  fi',
                '  if ! echo "$_dply_apt_log" | grep -qE "^E: "; then break; fi',
                '  if [ "$_dply_apt_attempt" -eq 6 ]; then exit 100; fi',
                '  sleep 15',
                'done',
            ];
        }

        $installBlock = implode(' ', [
            'dply_wait_for_apt_locks || exit 100;',
            'for _dply_apt_attempt in 1 2 3 4 5 6; do',
            'dply_wait_for_apt_locks || exit 100;',
            '_dply_apt_log=$(DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends '.implode(' ', $packages).' 2>&1) || true;',
            'echo "$_dply_apt_log";',
            'if echo "$_dply_apt_log" | grep -qE "Could not get lock|Unable to acquire the dpkg frontend lock|is held by process"; then',
            'echo "[dply] apt lock during install — sleeping 15s before retry $_dply_apt_attempt/6.";',
            'sleep 15;',
            'continue;',
            'fi;',
            'if ! echo "$_dply_apt_log" | grep -qE "^E: "; then break; fi;',
            'if [ "$_dply_apt_attempt" -eq 6 ]; then exit 100; fi;',
            'sleep 15;',
            'done',
        ]);

        return [
            'if '.implode(' && ', $checks).'; then echo '.escapeshellarg($alreadyInstalledMessage).'; else '.$installBlock.'; fi',
        ];
    }

    /**
     * Install REQUIRED packages (strict — abort on any missing) and OPTIONAL
     * packages (best-effort — filtered to what apt can resolve) in a SINGLE
     * apt-get transaction. This merges what was two separate dpkg runs (e.g.
     * PHP core + PHP extensions) into one, saving a full lock-wait + dpkg cycle.
     * Required-missing still trips exit 100; optional-missing are pre-filtered
     * out so they can't.
     *
     * @param  array<string, mixed> $required
     * @param  array<string, mixed> $optional
     * @return list<string>
     */
    private function ensureMixedPackagesInstalled(array $required, array $optional, string $alreadyInstalledMessage): array
    {
        $required = array_values(array_filter($required, fn (string $p): bool => trim($p) !== ''));
        $optional = array_values(array_filter($optional, fn (string $p): bool => trim($p) !== ''));
        if ($required === [] && $optional === []) {
            return [];
        }

        // Skip-fast only on the REQUIRED set. Optional extensions may legitimately
        // have no package on the distro (e.g. php8.3-sodium on noble — built into
        // core), so dpkg -s would always fail for them and the "already installed"
        // short-circuit could never fire on a resume/baked-snapshot re-run.
        $checks = array_map(
            fn (string $p): string => 'dpkg -s '.escapeshellarg($p).' >/dev/null 2>&1',
            $required,
        );

        $requiredList = implode(' ', $required);
        $optionalList = implode(' ', $optional);

        $install = implode("\n", [
            '_dply_opt_avail=""',
            $optional === [] ? ': # no optional packages' : 'for _dply_opt_pkg in '.$optionalList.'; do',
            $optional === [] ? '' : '  if apt-cache show "$_dply_opt_pkg" >/dev/null 2>&1; then _dply_opt_avail="$_dply_opt_avail $_dply_opt_pkg"; else echo "[dply] optional PHP extension $_dply_opt_pkg not available in configured repos — skipping."; fi',
            $optional === [] ? '' : 'done',
            'dply_wait_for_apt_locks || exit 100',
            'for _dply_apt_attempt in 1 2 3 4 5 6; do',
            '  dply_wait_for_apt_locks || exit 100',
            '  _dply_apt_log=$(DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends '.$requiredList.' $_dply_opt_avail 2>&1) || true',
            '  echo "$_dply_apt_log"',
            '  if echo "$_dply_apt_log" | grep -qE "Could not get lock|Unable to acquire the dpkg frontend lock|is held by process"; then',
            '    echo "[dply] apt lock during install — sleeping 15s before retry $_dply_apt_attempt/6."',
            '    sleep 15',
            '    continue',
            '  fi',
            '  if ! echo "$_dply_apt_log" | grep -qE "^E: "; then break; fi',
            '  if [ "$_dply_apt_attempt" -eq 6 ]; then exit 100; fi',
            '  sleep 15',
            'done',
        ]);

        // Drop the empty placeholder lines we may have inserted for the no-optional case.
        $install = implode("\n", array_values(array_filter(explode("\n", $install), fn (string $l): bool => $l !== '')));

        return [
            'if '.implode(' && ', $checks).'; then echo '.escapeshellarg($alreadyInstalledMessage).'; else'."\n".$install."\n".'fi',
        ];
    }


    private function forceReinstall(): bool
    {
        return (bool) config('server_provision.force_reinstall', false);
    }
}
