<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * One-shot install / uninstall bash for each supported cache engine. Mirrors the inline blocks the
 * provisioner uses at server-build time (`ServerProvisionCommandBuilder::roleRedis`,
 * `roleValkey`, the application-role install steps), but pulled out so the WorkspaceCaches
 * install/uninstall jobs can run them post-provision without re-entering the full role flow.
 *
 * Each script is idempotent enough to re-run safely (apt-get install reports "already newest" on
 * second runs; systemd enable + start are no-ops when the service is already up).
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

    public static function installScript(string $engine): string
    {
        return match ($engine) {
            'redis' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y redis-server
systemctl enable --now redis-server
redis-cli ping
BASH,
            'valkey' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y valkey-server || apt-get install -y valkey
systemctl enable --now valkey-server || systemctl enable --now valkey
(valkey-cli ping || redis-cli ping) 2>/dev/null
BASH,
            'memcached' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y memcached
systemctl enable --now memcached
systemctl is-active memcached
BASH,
            'keydb' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y keydb-server || apt-get install -y keydb
systemctl enable --now keydb-server || systemctl enable --now keydb
keydb-cli ping || redis-cli ping
BASH,
            'dragonfly' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
# Dragonfly ships its own .deb; install via the upstream APT source if present, else fall back to a release tarball drop-in.
if ! command -v dragonfly >/dev/null 2>&1; then
    if [ -f /etc/apt/sources.list.d/dragonflydb.list ]; then
        apt-get update -y
        apt-get install -y dragonfly
    else
        # No upstream APT repo configured on this host yet; fail loudly so the operator wires it
        # (Dragonfly's apt.dragonflydb.io setup) and re-runs Install rather than silently picking
        # an alternative.
        echo "Dragonfly APT source not configured (/etc/apt/sources.list.d/dragonflydb.list missing). Add the upstream repo per https://www.dragonflydb.io/docs/getting-started/install#debianubuntu and retry." >&2
        exit 1
    fi
fi
systemctl enable --now dragonfly
redis-cli ping
BASH,
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
        };
    }

    public static function uninstallScript(string $engine): string
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
     * engine — `redis-cli 7.0.5`, `memcached 1.6.18`, `dragonfly v1.13.0` — so we walk the last
     * non-empty line and pick the first whitespace-separated token that looks like a version
     * (digits, dots, alphanumerics). Returns null when nothing parseable is present.
     */
    public static function parseVersionFromBuffer(string $buffer): ?string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $buffer)), fn ($l) => $l !== ''));
        if ($lines === []) {
            return null;
        }
        $last = (string) end($lines);

        // Strip a leading "v" (dragonfly emits "dragonfly v1.13.0").
        foreach (preg_split('/\s+/', $last) as $token) {
            if ($token === '' || ! preg_match('/^v?\d/', $token)) {
                continue;
            }

            return ltrim($token, 'v');
        }

        return $last !== '' ? $last : null;
    }

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
     * Path to the engine's main config file. Used by the read-only config viewer in the workspace.
     * Distros vary slightly; we point at the upstream apt package's default. If it's missing, the
     * viewer surfaces a "not found" error which beats a stale absolute-path-misspelling display.
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
}
