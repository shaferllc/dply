<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\ServerCacheService;

/**
 * Install / uninstall bash + systemd-unit + config-path helpers for each
 * supported cache engine, factored so multi-instance workspaces (multiple
 * instances of the same engine on different ports) can compose them. The
 * surface is split into three layers:
 *
 *   1. Package install  — idempotent apt step that runs once per engine on
 *      a server, regardless of how many instances run on it.
 *   2. Instance install — systemd enable + version probe per instance. For
 *      the reserved `default` name, this routes to the engine's legacy
 *      systemd unit + legacy config path so existing servers (provisioned
 *      before multi-instance) keep working with no on-box changes.
 *   3. Instance uninstall — disable + (optionally) `apt purge` when this
 *      was the last instance of the engine on the server.
 *
 * Mirror methods exist for the legacy `installScript($engine)` /
 * `uninstallScript($engine)` shape used by the existing single-instance
 * jobs — those route through `default` and are kept as thin wrappers so
 * the call sites don't have to change.
 */
final class CacheServiceInstallScripts
{
    /**
     * @return list<string>
     */
    public static function supportedEngines(): array
    {
        return ['redis', 'valkey', 'memcached', 'keydb', 'dragonfly'];
    }

    /**
     * Idempotent apt-install step for an engine. Each script first checks whether the engine's
     * binary is already on PATH and skips the apt step entirely if so — this matters for
     * "Add instance" which re-runs the install pipeline against an already-provisioned host;
     * the apt step itself reports "already newest" but `apt-get update` still costs 20–60 s.
     *
     * Each script ends with an explicit `command -v` verification. That's a backstop against
     * `||`-chained apt failures slipping past `set -e` — if the package isn't actually
     * installed, the script exits non-zero with a clear message instead of marching on to
     * systemctl and leaving a "Running" row pointing at nothing.
     */
    public static function installPackageScript(string $engine): string
    {
        return match ($engine) {
            'redis' => <<<'BASH'
if ! command -v redis-server >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get install -y redis-server
fi
command -v redis-server >/dev/null 2>&1 || { echo "ERROR: redis-server binary not on PATH after apt install — package may be missing from the configured repositories." >&2; exit 1; }
BASH,
            'valkey' => <<<'BASH'
if ! command -v valkey-server >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    if ! { apt-get install -y valkey-server || apt-get install -y valkey; }; then
        echo "ERROR: Could not install valkey — neither 'valkey-server' nor 'valkey' is in the configured APT repositories. Enable 'universe' (Ubuntu 24.04+) or add the upstream Valkey APT source and retry." >&2
        exit 1
    fi
fi
command -v valkey-server >/dev/null 2>&1 || { echo "ERROR: valkey-server binary not on PATH after apt install — package may be missing from the configured repositories." >&2; exit 1; }
BASH,
            'memcached' => <<<'BASH'
if ! command -v memcached >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get install -y memcached
fi
command -v memcached >/dev/null 2>&1 || { echo "ERROR: memcached binary not on PATH after apt install — package may be missing from the configured repositories." >&2; exit 1; }
BASH,
            'keydb' => <<<'BASH'
if ! command -v keydb-server >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    if ! { apt-get install -y keydb-server || apt-get install -y keydb; }; then
        echo "ERROR: Could not install KeyDB — neither 'keydb-server' nor 'keydb' is in the configured APT repositories. Add the upstream KeyDB APT source per https://docs.keydb.dev/docs/build/ (or 'add-apt-repository ppa:eq-alpha-technology/keydb' on Ubuntu) and retry." >&2
        exit 1
    fi
fi
command -v keydb-server >/dev/null 2>&1 || { echo "ERROR: keydb-server binary not on PATH after apt install — package may be missing from the configured repositories." >&2; exit 1; }
BASH,
            'dragonfly' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
# Dragonfly ships its own .deb; install via the upstream APT source if present, else fail loudly.
if ! command -v dragonfly >/dev/null 2>&1; then
    if [ -f /etc/apt/sources.list.d/dragonflydb.list ]; then
        apt-get update -y
        apt-get install -y dragonfly
    else
        echo "Dragonfly APT source not configured (/etc/apt/sources.list.d/dragonflydb.list missing). Add the upstream repo per https://www.dragonflydb.io/docs/getting-started/install#debianubuntu and retry." >&2
        exit 1
    fi
fi
command -v dragonfly >/dev/null 2>&1 || { echo "ERROR: dragonfly binary not on PATH after apt install — package may be missing from the configured repositories." >&2; exit 1; }
BASH,
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
        };
    }

    /**
     * Per-instance install step: systemctl enable + verify. For the reserved
     * `default` name, uses the engine's legacy systemd unit (the apt
     * package's default service). For named instances, uses a templated
     * unit (`<engine>-server@<name>.service`) and a per-instance config
     * file.
     *
     * Note: the templated path requires the template unit + per-instance
     * config to already exist on the box. Phase 3 (add-instance flow) is
     * where that scaffolding gets dropped; this method is the consumer.
     */
    public static function installInstanceScript(
        string $engine,
        string $name = ServerCacheService::DEFAULT_INSTANCE_NAME,
        ?int $port = null,
        ?string $authPassword = null,
    ): string {
        if ($name === ServerCacheService::DEFAULT_INSTANCE_NAME) {
            return self::legacyDefaultInstanceInstallScript($engine);
        }

        return self::templatedInstanceInstallScript($engine, $name, $port ?? ServerCacheService::defaultPortFor($engine));
    }

    /**
     * Per-instance uninstall step. When `$isLastInstance` is true, the
     * package itself is `apt purge`d; otherwise only this instance's unit
     * is disabled and its config file is removed (the package and other
     * instances stay intact).
     */
    public static function uninstallInstanceScript(
        string $engine,
        string $name = ServerCacheService::DEFAULT_INSTANCE_NAME,
        bool $isLastInstance = true,
    ): string {
        if ($name === ServerCacheService::DEFAULT_INSTANCE_NAME) {
            return $isLastInstance
                ? self::legacyDefaultInstanceUninstallScriptWithApt($engine)
                : self::legacyDefaultInstanceUninstallScriptDisableOnly($engine);
        }

        return self::templatedInstanceUninstallScript($engine, $name, $isLastInstance);
    }

    /**
     * Legacy single-instance install wrapper. Composes package install +
     * default-instance install so existing call sites (the single-instance
     * install/switch jobs) keep working without touching the multi-instance
     * surface.
     */
    public static function installScript(string $engine): string
    {
        return self::installPackageScript($engine)
            ."\n"
            .self::installInstanceScript($engine, ServerCacheService::DEFAULT_INSTANCE_NAME);
    }

    /**
     * Full install script for a `ServerCacheService` row, accounting for the
     * instance's name. For `default`, the result is identical to the legacy
     * `installScript($engine)` — apt + legacy unit + ping. For named
     * instances, the script also writes (a) the engine's templated systemd
     * unit if missing and (b) the instance's per-instance config file +
     * state directory before enabling and pinging the templated unit.
     */
    public static function installScriptForRow(ServerCacheService $row): string
    {
        $parts = [self::installPackageScript($row->engine)];

        if (! $row->isDefaultInstance()) {
            $parts[] = self::scaffoldTemplateUnitScript($row->engine);
            $parts[] = self::scaffoldInstanceConfigScript($row->engine, $row->name, $row->port, $row->auth_password);
            $parts[] = 'systemctl daemon-reload';
        }

        $parts[] = self::installInstanceScript(
            $row->engine,
            $row->name,
            $row->port,
            $row->auth_password,
        );

        return implode("\n", $parts);
    }

    /**
     * Bash that writes the engine's templated systemd unit
     * (`/etc/systemd/system/<engine>-server@.service`) only if it doesn't
     * already exist. Idempotent — re-running on a server that already has
     * the template is a no-op.
     */
    public static function scaffoldTemplateUnitScript(string $engine): string
    {
        $unitPath = '/etc/systemd/system/'.self::systemdServiceFor($engine).'@.service';
        $content = self::templateUnitContent($engine);

        // heredoc-quoted so PHP-side interpolation doesn't alter the shell-side delimiter
        return <<<BASH
if [ ! -f {$unitPath} ]; then
    cat > {$unitPath} <<'DPLY_UNIT_EOF'
{$content}
DPLY_UNIT_EOF
    chmod 0644 {$unitPath}
fi
BASH;
    }

    /**
     * Bash that writes a per-instance config file and ensures the state
     * directory exists. Engine-specific (Redis, Valkey, KeyDB, Dragonfly all
     * have different config syntax conventions, but share the same shape).
     */
    public static function scaffoldInstanceConfigScript(string $engine, string $name, int $port, ?string $authPassword): string
    {
        $configPath = self::instanceConfigPath($engine, $name);
        $content = self::instanceConfigContent($engine, $name, $port, $authPassword);
        $stateDir = self::instanceStateDir($engine, $name);
        $owner = self::engineSystemUser($engine);

        return <<<BASH
mkdir -p {$stateDir}
chown -R {$owner}:{$owner} {$stateDir}
mkdir -p $(dirname {$configPath})
cat > {$configPath} <<'DPLY_CONFIG_EOF'
{$content}
DPLY_CONFIG_EOF
chown {$owner}:{$owner} {$configPath}
chmod 0640 {$configPath}
BASH;
    }

    /**
     * Per-engine state directory for non-default instances. The path is also
     * referenced inside the per-instance config (`dir`/`dbfilename` for
     * Redis-family) so dply must keep these two consistent.
     */
    public static function instanceStateDir(string $engine, string $name): string
    {
        if ($name === ServerCacheService::DEFAULT_INSTANCE_NAME) {
            // The legacy single-instance install uses the apt package's default
            // state dir; we don't try to relocate it.
            return match ($engine) {
                'redis' => '/var/lib/redis',
                'valkey' => '/var/lib/valkey',
                'keydb' => '/var/lib/keydb',
                'dragonfly' => '/var/lib/dragonfly',
                'memcached' => '/var/lib/memcached',
                default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
            };
        }

        return match ($engine) {
            'redis' => "/var/lib/redis/{$name}",
            'valkey' => "/var/lib/valkey/{$name}",
            'keydb' => "/var/lib/keydb/{$name}",
            'dragonfly' => "/var/lib/dragonfly/{$name}",
            'memcached' => throw new \InvalidArgumentException('Memcached multi-instance is not supported.'),
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
        };
    }

    /**
     * UNIX user the engine's systemd unit runs as. The package install
     * creates this user, so for the templated unit we just reuse it for the
     * non-default instance's process and file ownership.
     */
    public static function engineSystemUser(string $engine): string
    {
        return match ($engine) {
            'redis' => 'redis',
            'valkey' => 'valkey',
            'keydb' => 'keydb',
            'dragonfly' => 'dragonfly',
            'memcached' => 'memcache',
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
        };
    }

    /**
     * Templated systemd unit content for an engine. Written once per server
     * (idempotent) at `/etc/systemd/system/<engine>-server@.service` when the
     * first non-default instance is installed.
     */
    public static function templateUnitContent(string $engine): string
    {
        return match ($engine) {
            'redis' => <<<'UNIT'
[Unit]
Description=Redis instance %i (dply-managed)
After=network.target

[Service]
Type=notify
ExecStart=/usr/bin/redis-server /etc/redis/redis-%i.conf --supervised systemd
TimeoutStopSec=20
Restart=on-failure
User=redis
Group=redis
RuntimeDirectory=redis
RuntimeDirectoryMode=2755

[Install]
WantedBy=multi-user.target
UNIT,
            'valkey' => <<<'UNIT'
[Unit]
Description=Valkey instance %i (dply-managed)
After=network.target

[Service]
Type=notify
ExecStart=/usr/bin/valkey-server /etc/valkey/valkey-%i.conf --supervised systemd
TimeoutStopSec=20
Restart=on-failure
User=valkey
Group=valkey
RuntimeDirectory=valkey
RuntimeDirectoryMode=2755

[Install]
WantedBy=multi-user.target
UNIT,
            'keydb' => <<<'UNIT'
[Unit]
Description=KeyDB instance %i (dply-managed)
After=network.target

[Service]
Type=notify
ExecStart=/usr/bin/keydb-server /etc/keydb/keydb-%i.conf --supervised systemd
TimeoutStopSec=20
Restart=on-failure
User=keydb
Group=keydb
RuntimeDirectory=keydb
RuntimeDirectoryMode=2755

[Install]
WantedBy=multi-user.target
UNIT,
            'dragonfly' => <<<'UNIT'
[Unit]
Description=Dragonfly instance %i (dply-managed)
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/dragonfly --conf=/etc/dragonfly/dragonfly-%i.conf
TimeoutStopSec=20
Restart=on-failure
User=dragonfly
Group=dragonfly

[Install]
WantedBy=multi-user.target
UNIT,
            default => throw new \InvalidArgumentException("Unsupported cache engine for template unit: {$engine}"),
        };
    }

    /**
     * Per-instance config file content. Minimum-viable: enough to start the
     * engine, bind to loopback only, write data into the per-instance state
     * dir, and (optionally) require AUTH. Operators can edit this through the
     * existing config viewer once the instance is running.
     */
    public static function instanceConfigContent(string $engine, string $name, int $port, ?string $authPassword): string
    {
        $stateDir = self::instanceStateDir($engine, $name);
        $auth = $authPassword !== null && $authPassword !== ''
            ? 'requirepass '.$authPassword."\n"
            : '';

        return match ($engine) {
            'redis', 'valkey', 'keydb' => <<<CONF
# dply-managed instance config for {$name}
port {$port}
bind 127.0.0.1 -::1
protected-mode yes
dir {$stateDir}
dbfilename {$name}.rdb
appendfilename "{$name}.aof"
appendonly no
pidfile /var/run/{$engine}/{$engine}-{$name}.pid
logfile ""
loglevel notice
{$auth}
CONF,
            'dragonfly' => <<<CONF
# dply-managed instance config for {$name}
--port={$port}
--bind=127.0.0.1
--dir={$stateDir}
--dbfilename={$name}
{$auth}
CONF,
            default => throw new \InvalidArgumentException("Unsupported cache engine for instance config: {$engine}"),
        };
    }

    /**
     * Legacy single-instance uninstall wrapper.
     */
    public static function uninstallScript(string $engine): string
    {
        return self::uninstallInstanceScript($engine, ServerCacheService::DEFAULT_INSTANCE_NAME, isLastInstance: true);
    }

    /**
     * Path to the engine's main config file for a given instance. The
     * `default` name routes to the legacy path the apt package writes; named
     * instances route to a templated path under the engine's config dir.
     */
    public static function instanceConfigPath(string $engine, string $name = ServerCacheService::DEFAULT_INSTANCE_NAME): string
    {
        if ($name === ServerCacheService::DEFAULT_INSTANCE_NAME) {
            return self::configFilePathFor($engine);
        }

        return match ($engine) {
            'redis' => "/etc/redis/redis-{$name}.conf",
            'valkey' => "/etc/valkey/valkey-{$name}.conf",
            'memcached' => "/etc/memcached.conf.d/{$name}.conf",
            'keydb' => "/etc/keydb/keydb-{$name}.conf",
            'dragonfly' => "/etc/dragonfly/dragonfly-{$name}.conf",
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
        };
    }

    /**
     * Systemd service unit for a given instance. The `default` name routes
     * to the legacy unit (e.g. `redis-server.service`); named instances
     * route to the templated form (`redis-server@<name>.service`).
     */
    public static function instanceServiceUnit(string $engine, string $name = ServerCacheService::DEFAULT_INSTANCE_NAME): string
    {
        if ($name === ServerCacheService::DEFAULT_INSTANCE_NAME) {
            return self::systemdServiceFor($engine);
        }

        return self::systemdServiceFor($engine)."@{$name}";
    }

    /**
     * Emit the engine's raw `--version` line(s). Parsing happens PHP-side via
     * {@see CacheServiceInstallScripts::parseVersionFromBuffer()}.
     *
     * The probe deliberately avoids awk-based field extraction because the previous
     * `awk "{print \$2}"` shape was fragile (set -euo pipefail + PATH-restricted
     * non-login root shell could silently produce empty output). Each `||` chain
     * picks the first CLI that actually exists; trailing `|| true` keeps the script
     * exit code 0 even when the engine isn't on PATH so set -e doesn't abort.
     */
    public static function versionProbeScript(string $engine): string
    {
        return match ($engine) {
            'redis' => '(command -v redis-cli >/dev/null 2>&1 && redis-cli --version) || true',
            'valkey' => '(command -v valkey-cli >/dev/null 2>&1 && valkey-cli --version) || (command -v redis-cli >/dev/null 2>&1 && redis-cli --version) || true',
            'memcached' => '(command -v memcached >/dev/null 2>&1 && memcached --version) || true',
            'keydb' => '(command -v keydb-cli >/dev/null 2>&1 && keydb-cli --version) || (command -v redis-cli >/dev/null 2>&1 && redis-cli --version) || true',
            'dragonfly' => '(command -v dragonfly >/dev/null 2>&1 && dragonfly --version | head -n1) || true',
            default => 'true',
        };
    }

    /**
     * Pull the version token out of a `--version` style output buffer. The line shape varies by
     * engine — `redis-cli 7.0.5`, `memcached 1.6.18`, `dragonfly v1.13.0` — so we walk lines from
     * the bottom up and return the first whitespace-separated token that looks like a version
     * (optional `v` + digit + dot/digit/letter). Returns null when nothing parseable is present.
     *
     * We deliberately do NOT fall back to "return the last line as-is" — that path used to leak
     * apt error messages ("E: Unable to locate package keydb") into the version field whenever
     * the install script's exit code was 0 but the version probe produced no output (e.g. the
     * `cli` binary isn't on PATH after a partially-failed install).
     */
    public static function parseVersionFromBuffer(string $buffer): ?string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $buffer)), fn ($l) => $l !== ''));

        // Walk from the bottom — version probe output is always last. Stop as soon as a line
        // yields a version-shaped token; otherwise keep looking (handles the case where the
        // last line is an unrelated stderr message that landed after the probe).
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            foreach (preg_split('/\s+/', $lines[$i]) as $token) {
                if ($token === '' || ! preg_match('/^v?\d[\d.][\w.\-]*$/', $token)) {
                    continue;
                }

                return ltrim($token, 'v');
            }
        }

        return null;
    }

    /**
     * Legacy single-instance systemd unit name. Equivalent to
     * `instanceServiceUnit($engine, 'default')`; kept as a separate
     * top-level method because callers that don't (yet) carry an instance
     * name (the AUTH/memory/config writers) read this.
     */
    public static function systemdServiceFor(string $engine): string
    {
        return match ($engine) {
            'redis' => 'redis-server',
            'valkey' => 'valkey-server',
            'memcached' => 'memcached',
            'keydb' => 'keydb-server',
            'dragonfly' => 'dragonfly',
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
        };
    }

    /**
     * Path to the engine's main config file for the legacy (single-instance)
     * install. Equivalent to `instanceConfigPath($engine, 'default')`; kept
     * separate so the AUTH/memory/config writers (which today operate on a
     * single ServerCacheService row without an instance dimension) can read
     * it directly.
     */
    public static function configFilePathFor(string $engine): string
    {
        return match ($engine) {
            'redis' => '/etc/redis/redis.conf',
            'valkey' => '/etc/valkey/valkey.conf',
            'memcached' => '/etc/memcached.conf',
            'keydb' => '/etc/keydb/keydb.conf',
            'dragonfly' => '/etc/dragonfly/dragonfly.conf',
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
        };
    }

    private static function legacyDefaultInstanceInstallScript(string $engine): string
    {
        return match ($engine) {
            'redis' => <<<'BASH'
systemctl enable --now redis-server
redis-cli ping
BASH,
            'valkey' => <<<'BASH'
systemctl enable --now valkey-server || systemctl enable --now valkey
(valkey-cli ping || redis-cli ping) 2>/dev/null
BASH,
            'memcached' => <<<'BASH'
systemctl enable --now memcached
systemctl is-active memcached
BASH,
            'keydb' => <<<'BASH'
systemctl enable --now keydb-server || systemctl enable --now keydb
keydb-cli ping || redis-cli ping
BASH,
            'dragonfly' => <<<'BASH'
systemctl enable --now dragonfly
redis-cli ping
BASH,
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
        };
    }

    private static function legacyDefaultInstanceUninstallScriptWithApt(string $engine): string
    {
        return match ($engine) {
            'redis' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
systemctl disable --now redis-server || true
apt-get purge -y redis-server redis-tools || true
apt-get autoremove -y
BASH,
            'valkey' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
systemctl disable --now valkey-server valkey || true
apt-get purge -y valkey-server valkey || true
apt-get autoremove -y
BASH,
            'memcached' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
systemctl disable --now memcached || true
apt-get purge -y memcached || true
apt-get autoremove -y
BASH,
            'keydb' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
systemctl disable --now keydb-server keydb || true
apt-get purge -y keydb-server keydb || true
apt-get autoremove -y
BASH,
            'dragonfly' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
systemctl disable --now dragonfly || true
apt-get purge -y dragonfly || true
apt-get autoremove -y
BASH,
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
        };
    }

    /**
     * Disable + stop the legacy unit but leave the package installed because
     * other instances of the same engine are still running on this server.
     * Without this branch, uninstalling `default` would `apt purge` the
     * binary out from under the other instances.
     */
    private static function legacyDefaultInstanceUninstallScriptDisableOnly(string $engine): string
    {
        $unit = self::systemdServiceFor($engine);

        return <<<BASH
systemctl disable --now {$unit} || true
BASH;
    }

    /**
     * Install a non-default instance via the templated systemd unit. The
     * template unit + per-instance config file must already exist on the
     * box — Phase 3's add-instance flow is where they get dropped.
     */
    private static function templatedInstanceInstallScript(string $engine, string $name, int $port): string
    {
        $unit = self::systemdServiceFor($engine)."@{$name}";

        return match ($engine) {
            'redis' => 'systemctl enable --now '.escapeshellarg($unit)."\nredis-cli -p {$port} ping",
            'valkey' => 'systemctl enable --now '.escapeshellarg($unit)."\n(valkey-cli -p {$port} ping || redis-cli -p {$port} ping) 2>/dev/null",
            'keydb' => 'systemctl enable --now '.escapeshellarg($unit)."\n(keydb-cli -p {$port} ping || redis-cli -p {$port} ping) 2>/dev/null",
            'dragonfly' => 'systemctl enable --now '.escapeshellarg($unit)."\nredis-cli -p {$port} ping",
            // Memcached multi-instance is out of scope for v1 of multi-port; reject loudly.
            'memcached' => throw new \InvalidArgumentException('Memcached multi-instance is not supported. Use the default instance only.'),
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
        };
    }

    private static function templatedInstanceUninstallScript(string $engine, string $name, bool $isLastInstance): string
    {
        $unit = self::systemdServiceFor($engine)."@{$name}";
        $configPath = self::instanceConfigPath($engine, $name);

        $head = <<<BASH
systemctl disable --now {$unit} || true
rm -f {$configPath} || true
BASH;

        if (! $isLastInstance) {
            // Keep the package; another instance still uses it.
            return $head;
        }

        // Last instance of this engine — also purge the package. We delegate
        // to the legacy script since the package-removal commands are the
        // same regardless of which instances existed.
        return $head."\n".self::legacyDefaultInstanceUninstallScriptWithApt($engine);
    }
}
