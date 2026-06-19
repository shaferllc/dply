<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Services\Servers\MiseInstallScriptBuilder;
use App\Support\Servers\DedicatedCacheServerProvisionConfig;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsProvisionRuntimes
{


    /**
     * @return list<string>
     */
    private function maybeInstallSupervisor(): array
    {
        if (! config('server_provision.install_supervisor_on_provision', false)) {
            return [];
        }

        return [
            $this->stepMarker('Installing Supervisor'),
            ...$this->ensurePackagesInstalled(
                ['supervisor'],
                '[dply] supervisor already installed; skipping package install.'
            ),
            'systemctl enable --now supervisor',
        ];
    }

    /**
     * Install mise system-wide via apt, activate it for the deploy user,
     * and pin any per-runtime defaults the wizard recorded on the server.
     *
     * mise manages non-PHP runtimes (Node / Python / Ruby / Go) per the
     * multi-runtime strategy. The polyglot-host preset (or any wizard
     * preset that pre-selects runtimes) writes a `runtime_defaults` map
     * onto the server's meta:
     *
     *   meta.runtime_defaults = ['node' => '22', 'python' => '3.12', ...]
     *
     * Each entry becomes a `mise use --global <tool>@<ver>` line so a
     * site without its own pin (no .tool-versions, no Site-level
     * runtime_version) gets the server default at deploy time.
     *
     * @return list<string>
     */
    private function maybeInstallMise(): array
    {
        if (! config('server_provision.install_mise_on_provision', true)) {
            return [];
        }

        $mise = app(MiseInstallScriptBuilder::class);
        $deployUser = (string) config('server_provision.deploy_ssh_user', 'dply');

        // mise itself installs via apt (must be sequential — holds the dpkg
        // lock). The per-runtime installs below (`mise use …`) download
        // language binaries from github/nodejs.org and DON'T touch the dpkg
        // lock, so they can run concurrently with the apt-heavy PHP/MySQL steps.
        $lines = array_merge(
            [$this->stepMarker('Installing mise (Node / Python / Ruby / Go manager)')],
            $mise->installLines($this->forceReinstall()),
            $mise->activateForUserLines($deployUser),
        );

        $runtimeLines = [];
        $defaults = $this->serverRuntimeDefaults();
        foreach ($defaults as $runtime => $version) {
            $runtimeLines = array_merge(
                $runtimeLines,
                $mise->installRuntimeForUserLines($deployUser, $runtime, $version),
            );
        }

        if ($runtimeLines === []) {
            return $lines;
        }

        // Sequential (default): install runtimes inline.
        if (! (bool) config('server_provision.parallel_runtimes', false)) {
            return array_merge($lines, $runtimeLines);
        }

        // Parallel (opt-in): run the runtime downloads in a background subshell
        // and record its PID so waitForBackgroundRuntimes() (emitted before
        // verification) can join + report. Best-effort: a runtime failure warns
        // rather than aborting the whole provision.
        $bg = implode("\n", $runtimeLines);
        $lines[] = 'mkdir -p /var/lib/dply';
        $lines[] = "(\n".$bg."\n) > /var/lib/dply/mise-runtimes.log 2>&1 &";
        $lines[] = 'echo $! > /var/lib/dply/mise-runtimes.pid';
        $lines[] = 'echo "[dply] runtime installs (node/python/…) running in background to overlap apt steps."';

        return $lines;
    }

    /**
     * Join background runtime installs started by maybeInstallMise() under the
     * parallel_runtimes flag. No-op when nothing ran in the background (the PID
     * file only exists when the flag is on and runtimes were queued). A failed
     * background install warns (with its log) rather than aborting — the runtime
     * can be reinstalled via the workspace later.
     *
     * @return list<string>
     */
    private function waitForBackgroundRuntimes(): array
    {
        return [
            'if [ -f /var/lib/dply/mise-runtimes.pid ]; then '
                .'_dply_rt_pid=$(cat /var/lib/dply/mise-runtimes.pid 2>/dev/null || echo ""); '
                .'echo "[dply] waiting for background runtime installs (pid ${_dply_rt_pid})…"; '
                .'if [ -n "${_dply_rt_pid}" ] && wait "${_dply_rt_pid}"; then '
                    .'echo "[dply] background runtime installs finished."; '
                .'else '
                    .'echo "[dply] WARNING: background runtime install reported a failure — see log:" >&2; '
                    .'cat /var/lib/dply/mise-runtimes.log >&2 2>/dev/null || true; '
                .'fi; '
                .'rm -f /var/lib/dply/mise-runtimes.pid; '
            .'fi',
        ];
    }

    /**
     * Combined parallel download (prefetch_packages flag): pre-download the
     * stack's stock-repo packages in ONE apt transaction after the base update,
     * so apt fetches them in parallel and the per-component installs are
     * disk-only. Runtime-filtered to packages apt can actually resolve at this
     * point (third-party repos like pgdg/ondrej may not be added yet), and
     * fully best-effort — anything not prefetched simply downloads at install
     * time as before. No-op when the flag is off.
     *
     * @return list<string>
     */
    private function prefetchPackages(string $web, string $php, string $database, string $cache): array
    {
        if (! (bool) config('server_provision.prefetch_packages', false)) {
            return [];
        }

        $candidates = [];

        if ($php !== 'none') {
            $stem = $this->phpStem($php);
            foreach (['-cli', '-fpm', '-common', '-mbstring', '-xml', '-curl', '-mysql', '-pgsql', '-sqlite3', '-redis', '-gd', '-bcmath', '-intl', '-zip', '-opcache'] as $ext) {
                $candidates[] = $stem.$ext;
            }
        }

        if (in_array($database, ['mysql84', 'mysql80', 'mysql57', 'mariadb114', 'mariadb11', 'mariadb1011'], true)) {
            $candidates[] = 'mysql-server';
        }

        $candidates[] = match ($cache) {
            'redis' => 'redis-server',
            'valkey' => 'valkey-server',
            default => '',
        };

        $candidates[] = match ($web) {
            'nginx' => 'nginx',
            'apache' => 'apache2',
            default => '',
        };

        $candidates = array_values(array_filter($candidates, fn (string $p): bool => $p !== ''));
        if ($candidates === []) {
            return [];
        }

        $list = implode(' ', $candidates);

        return [
            implode("\n", [
                'echo "[dply] prefetching stock packages (one parallel download pass)…"',
                '_dply_prefetch=""',
                'for _dply_pf in '.$list.'; do',
                '  if apt-cache show "$_dply_pf" >/dev/null 2>&1; then _dply_prefetch="$_dply_prefetch $_dply_pf"; fi',
                'done',
                'if [ -n "$_dply_prefetch" ]; then',
                '  dply_wait_for_apt_locks || true',
                '  DEBIAN_FRONTEND=noninteractive apt-get install -y --download-only --no-install-recommends $_dply_prefetch >/dev/null 2>&1 || true',
                '  echo "[dply] prefetch complete:$_dply_prefetch"',
                'fi',
            ]),
        ];
    }

    /**
     * Pull the wizard-defined per-runtime defaults from the server meta.
     * Returns an empty array when nothing was recorded — mise still
     * installs but no specific runtime versions are pinned globally.
     *
     * @return array<string, string>
     */
    private function serverRuntimeDefaults(): array
    {
        $meta = $this->server?->meta ?? [];
        if (! is_array($meta)) {
            return [];
        }
        $defaults = $meta['runtime_defaults'] ?? null;
        if (! is_array($defaults)) {
            return [];
        }

        $clean = [];
        foreach ($defaults as $runtime => $version) {
            if (! is_string($runtime) || ! is_string($version)) {
                continue;
            }
            $version = trim($version);
            if ($version === '') {
                continue;
            }
            $clean[$runtime] = $version;
        }

        // A PHP application server almost always builds front-end assets
        // (`npm run build`), and the laravel_app profile advertises "NPM" — so
        // default Node when PHP is installed and no node was pinned, instead of
        // silently shipping a server with no npm. Operators who truly don't want
        // it can still pin runtime_defaults without node.
        $php = trim((string) ($meta['php_version'] ?? 'none'));
        if (! isset($clean['node']) && $php !== '' && $php !== 'none') {
            $clean['node'] = (string) config('server_provision.default_node_version', '22');
        }

        return $clean;
    }

    /**
     * @return list<string>
     */
    private function installAppCache(string $cache, ?DedicatedCacheServerProvisionConfig $config = null): array
    {
        // The local-cache fallback (no dedicated cache server) MUST emit an
        // explicit `dir` and disable RDB persistence. Without `dir`, redis/valkey/
        // keydb fall back to `./` which under systemd hardening resolves to `/` —
        // a read-only filesystem. BGSAVE then fails every few seconds, and with the
        // package-default `stop-writes-on-bgsave-error yes` it freezes ALL writes
        // (SET/SETEX), so cache+session writes throw `MISCONF ... unable to persist
        // to disk`. On shutdown redis tries a final save, fails, and refuses to exit
        // — wedging the unit in `deactivating (stop-sigterm)`. We hit exactly that on
        // lookout. A local app cache needs no RDB, so `save ""` makes it a pure cache
        // and `stop-writes ... no` is the belt-and-suspenders. Mirrors the dedicated
        // template in DedicatedCacheServerProvisionConfig.
        $redisConf = $config?->configFileContent('redis') ?? "bind 127.0.0.1 -::1\ndir /var/lib/redis\nsave \"\"\nstop-writes-on-bgsave-error no\nappendonly no\nmaxmemory 256mb\nmaxmemory-policy allkeys-lru\n";
        $valkeyConf = $config?->configFileContent('valkey') ?? "bind 127.0.0.1 ::1\ndir /var/lib/valkey\nsave \"\"\nstop-writes-on-bgsave-error no\nappendonly no\nmaxmemory 256mb\nmaxmemory-policy allkeys-lru\n";
        $keydbConf = $config?->configFileContent('keydb') ?? "bind 127.0.0.1 ::1\nprotected-mode yes\ndir /var/lib/keydb\nsave \"\"\nstop-writes-on-bgsave-error no\nappendonly no\nmaxmemory 256mb\nmaxmemory-policy allkeys-lru\nport 6379\n";
        $memcachedConf = $config?->configFileContent('memcached') ?? "-d\nlogfile /var/log/memcached.log\n-m 256\n-p 11211\n-l 127.0.0.1\n-U 0\n";

        // `systemctl enable --now` is a no-op when the unit is already running
        // — it does NOT pick up a freshly-written config. apt-get install for
        // redis/valkey/keydb/memcached auto-starts the daemon on the package's
        // default config (loopback only), so the dply config we write next is
        // only applied after an explicit restart. Without the restart step
        // operators provisioning a remote-access cache box end up with bind
        // 127.0.0.1 in the file but a process listening on 127.0.0.1 only —
        // exactly the bug we saw on dply-redis-1.
        return match ($cache) {
            'none' => [],
            'valkey' => $this->withStep('Installing Valkey', [
                'if dpkg -s valkey-server >/dev/null 2>&1 || dpkg -s valkey >/dev/null 2>&1; then echo "[dply] valkey already installed; skipping package install."; else apt-get install -y --no-install-recommends valkey-server || apt-get install -y --no-install-recommends valkey; fi',
                'if command -v redis-cli >/dev/null 2>&1; then echo "[dply] redis-cli already available."; else apt-get install -y --no-install-recommends redis-tools || true; fi',
                $this->writeFileWithRollback('/etc/valkey/valkey.conf', $valkeyConf),
                'install -d -m 0750 -o valkey -g valkey /var/lib/valkey || true',
                'systemctl enable valkey-server 2>/dev/null || systemctl enable valkey 2>/dev/null || true',
                'systemctl restart valkey-server 2>/dev/null || systemctl restart valkey 2>/dev/null || true',
            ]),
            'memcached' => $this->withStep('Installing Memcached', [
                ...$this->ensurePackagesInstalled(
                    ['memcached', 'libmemcached-tools'],
                    '[dply] memcached already installed; skipping package install.'
                ),
                $this->writeFileWithRollback('/etc/memcached.conf', $memcachedConf),
                'systemctl enable memcached',
                'systemctl restart memcached',
            ]),
            'keydb' => $this->withStep('Installing KeyDB', [
                'if dpkg -s keydb-server >/dev/null 2>&1 || dpkg -s keydb >/dev/null 2>&1; then echo "[dply] keydb already installed; skipping repository + package install."; else '
                    .'apt-get install -y --no-install-recommends software-properties-common ca-certificates && '
                    .'add-apt-repository -y ppa:eq-alpha/keydb 2>/dev/null || true; '
                    .'dply_apt_update && '
                    .'apt-get install -y --no-install-recommends keydb-server keydb-tools; fi',
                $this->writeFileWithRollback('/etc/keydb/keydb.conf', $keydbConf),
                'install -d -m 0750 -o keydb -g keydb /var/lib/keydb || true',
                'systemctl enable keydb-server 2>/dev/null || systemctl enable keydb 2>/dev/null || true',
                'systemctl restart keydb-server 2>/dev/null || systemctl restart keydb 2>/dev/null || true',
            ]),
            'dragonfly' => $this->installDragonfly(),
            default => $this->withStep('Installing Redis', [
                ...$this->ensurePackagesInstalled(
                    ['redis-server', 'redis-tools'],
                    '[dply] redis-server already installed; skipping package install.'
                ),
                $this->writeFileWithRollback('/etc/redis/redis.conf', $redisConf),
                // Guarantee the `dir` we just wrote exists and is writable by the
                // daemon — the package usually creates it, but if it's wrong redis
                // wedges (see the redisConf comment above).
                'install -d -m 0750 -o redis -g redis /var/lib/redis || true',
                'systemctl enable redis-server',
                'systemctl restart redis-server',
            ]),
        };
    }

    /**
     * Install Dragonfly from the official apt repo. Wire-compatible with Redis on port 6379;
     * verified with redis-cli.
     *
     * @return list<string>
     */
    private function installDragonfly(): array
    {
        return $this->withStep('Installing Dragonfly', [
            'if dpkg -s dragonfly >/dev/null 2>&1; then echo "[dply] dragonfly already installed; skipping repository + package install."; else '
                .'install -d /etc/apt/keyrings && '
                .'curl -fsSL https://packages.dragonflydb.io/keys/release.asc | gpg --dearmor --yes -o /etc/apt/keyrings/dragonfly.gpg && '
                .'chmod 0644 /etc/apt/keyrings/dragonfly.gpg && '
                .'. /etc/os-release && '
                .'echo "deb [signed-by=/etc/apt/keyrings/dragonfly.gpg] https://packages.dragonflydb.io/dragonfly/ubuntu ${VERSION_CODENAME:-jammy} main" > /etc/apt/sources.list.d/dragonfly.list && '
                .'dply_apt_update && '
                .'apt-get install -y --no-install-recommends dragonfly; fi',
            // Dragonfly ships its own systemd unit; just ensure it's enabled.
            'systemctl enable --now dragonfly 2>/dev/null || true',
        ]);
    }
}
