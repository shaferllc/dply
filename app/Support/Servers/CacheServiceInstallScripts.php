<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\ServerCacheService;

/**
 * Install / uninstall bash + systemd-unit + config-path helpers for each supported cache engine.
 *
 * dply allows at most one row per (server, engine) and at most one redis-family engine per server
 * — the multi-instance machinery (templated systemd units, per-instance configs, port-autopick)
 * was removed in the `collapse_cache_services_to_one_per_family` migration once it became clear
 * the bugs it created (default-on-non-default-port config drift, repair-port helpers, orphan
 * rows) outweighed the rare workloads it served. Every cache row is the engine's apt-shipped
 * single-instance install on its default port.
 *
 * Method surface kept compact: `installPackageScript` for the apt step, the legacy
 * `installScript` / `uninstallScript` wrappers, and per-engine path helpers
 * (`systemdServiceFor`, `configFilePathFor`) that other support classes
 * (`CacheServiceNetworkExposure`, `CacheServicePort`) consume.
 *
 * The `instanceConfigPath` / `instanceServiceUnit` aliases are kept as thin wrappers around
 * `configFilePathFor` / `systemdServiceFor` so legacy callers in `CacheServiceNetworkExposure`,
 * `CacheServicePort`, and the workspace component don't have to be rewritten — they always
 * return the default-instance path/unit regardless of any `$name` argument.
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
     * binary is already on PATH and skips the apt step entirely if so — keeps re-runs fast.
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
            'keydb' => self::keydbInstallPackageScript(),
            'dragonfly' => self::dragonflyInstallPackageScript(
                (string) config('server_cache.dragonfly_version', 'v1.38.1')
            ),
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
        };
    }

    /**
     * Codenames the auto-bootstrap install path supports for each engine. Returned as a list of
     * `/etc/os-release` $VERSION_CODENAME values; `null` means "universally available, no
     * distro-specific bootstrap needed" (redis, memcached, valkey). The bash scripts that
     * consume this (keydb, dragonfly) emit the same whitelist inline — keep the two in sync so
     * the UI gate and the install script tell the operator the same story.
     *
     * KeyDB: upstream apt repo at download.keydb.dev only publishes through Ubuntu jammy /
     * Debian bookworm; noble (Ubuntu 24.04) and trixie are not in their dists/ tree.
     *
     * Dragonfly: GitHub-release .debs link against a recent enough glibc that focal/bullseye
     * reject them; jammy/noble/bookworm work in practice.
     *
     * @return list<string>|null
     */
    public static function supportedDistroCodenames(string $engine): ?array
    {
        return match ($engine) {
            'redis', 'memcached', 'valkey' => null,
            'keydb' => ['focal', 'jammy', 'bullseye', 'bookworm'],
            'dragonfly' => ['jammy', 'noble', 'bookworm'],
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$engine}"),
        };
    }

    /**
     * Idempotent KeyDB install: source the host's /etc/os-release, refuse on codenames KeyDB
     * upstream doesn't ship for (no auto-install on noble/trixie — there's literally no package),
     * drop the upstream keyring under /etc/apt/keyrings/ with a signed-by= sources line, then
     * apt install. Repeat-runs are no-ops because of the `command -v keydb-server` short-circuit
     * + the keyring/sources file existence checks.
     */
    private static function keydbInstallPackageScript(): string
    {
        return <<<'BASH'
if ! command -v keydb-server >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive

    # Distro whitelist — KeyDB's repo (download.keydb.dev/open-source-dist) only publishes for
    # focal/jammy/bullseye/bookworm. On anything else (notably Ubuntu 24.04 noble) there is no
    # upstream package, so bail with a message the operator can act on instead of running apt
    # against a 404'd dist tree.
    . /etc/os-release
    codename="${VERSION_CODENAME:-}"
    case "$codename" in
        focal|jammy|bullseye|bookworm) ;;
        *)
            echo "ERROR: KeyDB upstream doesn't ship for ${ID:-unknown} ${codename:-unknown}. Supported: Ubuntu 20.04/22.04 or Debian 11/12. Pick Valkey or Redis on this host, or use an older base image." >&2
            exit 1
            ;;
    esac

    # Tooling we need to add the upstream APT source. ca-certificates handles the https hop.
    need_install=""
    command -v curl >/dev/null 2>&1 || need_install="$need_install curl"
    dpkg -s ca-certificates >/dev/null 2>&1 || need_install="$need_install ca-certificates"
    if [ -n "$need_install" ]; then
        apt-get update -y
        apt-get install -y $need_install
    fi

    # Drop the upstream keyring. KeyDB ships it as binary-format OpenPGP already, so no `gpg
    # --dearmor` step — write it straight under /etc/apt/keyrings/ (modern signed-by location).
    install -m 0755 -d /etc/apt/keyrings
    if [ ! -s /etc/apt/keyrings/keydb.gpg ]; then
        curl -fsSL https://download.keydb.dev/open-source-dist/keyring.gpg \
            -o /etc/apt/keyrings/keydb.gpg
        chmod 0644 /etc/apt/keyrings/keydb.gpg
    fi

    # signed-by= pins the source to THIS keyring only (so a compromised separate key can't sign
    # packages for this repo). Codename is whatever the host actually runs.
    if [ ! -f /etc/apt/sources.list.d/keydb.list ]; then
        echo "deb [signed-by=/etc/apt/keyrings/keydb.gpg] https://download.keydb.dev/open-source-dist $codename main" \
            > /etc/apt/sources.list.d/keydb.list
    fi

    apt-get update -y
    if ! { apt-get install -y keydb-server || apt-get install -y keydb; }; then
        echo "ERROR: apt couldn't install KeyDB after adding the upstream APT source. Check 'apt-cache policy keydb-server keydb' on the host." >&2
        exit 1
    fi
fi
command -v keydb-server >/dev/null 2>&1 || { echo "ERROR: keydb-server binary not on PATH after apt install — package may be missing from the configured repositories." >&2; exit 1; }
BASH;
    }

    /**
     * Idempotent Dragonfly install: Dragonfly has no upstream apt repo, just `.deb` artifacts on
     * each GitHub release. Distro-gate first (the binaries link against a recent-enough glibc that
     * older distros reject them), arch-gate (only amd64/arm64 are published), then download the
     * pinned-version .deb and `dpkg -i` it. Version is supplied at script-build time so callers
     * can pin via config — defaulting to a tag we've validated against the supported codenames.
     */
    private static function dragonflyInstallPackageScript(string $version): string
    {
        $tag = str_starts_with($version, 'v') ? $version : 'v'.$version;
        $tagShell = escapeshellarg($tag);

        return <<<BASH
if ! command -v dragonfly >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive

    # Distro whitelist — Dragonfly's .deb is built against a glibc/openssl that resolves on
    # jammy/noble/bookworm. focal/bullseye reject the deps. Newer codenames not yet validated.
    . /etc/os-release
    codename="\${VERSION_CODENAME:-}"
    case "\$codename" in
        jammy|noble|bookworm) ;;
        *)
            echo "ERROR: Dragonfly doesn't ship a .deb that resolves on \${ID:-unknown} \${codename:-unknown}. Supported: Ubuntu 22.04/24.04 or Debian 12." >&2
            exit 1
            ;;
    esac

    arch="\$(dpkg --print-architecture)"
    case "\$arch" in
        amd64|arm64) ;;
        *)
            echo "ERROR: Dragonfly only publishes .deb for amd64/arm64; this host is \$arch." >&2
            exit 1
            ;;
    esac

    need_install=""
    command -v curl >/dev/null 2>&1 || need_install="\$need_install curl"
    dpkg -s ca-certificates >/dev/null 2>&1 || need_install="\$need_install ca-certificates"
    if [ -n "\$need_install" ]; then
        apt-get update -y
        apt-get install -y \$need_install
    fi

    tag={$tagShell}
    url="https://github.com/dragonflydb/dragonfly/releases/download/\${tag}/dragonfly_\${arch}.deb"
    tmp="\$(mktemp --suffix=.deb)"
    trap 'rm -f "\$tmp"' EXIT

    if ! curl -fsSL "\$url" -o "\$tmp"; then
        echo "ERROR: Dragonfly .deb download failed: \$url" >&2
        exit 1
    fi

    # dpkg -i often fails the first time on missing deps; `apt-get install -f` resolves them, then
    # a second dpkg -i finishes the install. This is the standard pattern for github-released .debs.
    if ! dpkg -i "\$tmp"; then
        apt-get install -f -y
        dpkg -i "\$tmp"
    fi
fi
command -v dragonfly >/dev/null 2>&1 || { echo "ERROR: dragonfly binary not on PATH after install — the .deb may have rejected this host's dependencies." >&2; exit 1; }
BASH;
    }

    /**
     * Per-instance install step. Signature retained from the multi-instance era so the install
     * job's call site doesn't have to change — the `$name`, `$port`, and `$authPassword`
     * arguments are now ignored and the script always installs the engine's default unit on its
     * default port.
     */
    public static function installInstanceScript(
        string $engine,
        string $name = ServerCacheService::DEFAULT_INSTANCE_NAME,
        ?int $port = null,
        ?string $authPassword = null,
    ): string {
        return self::legacyDefaultInstanceInstallScript($engine);
    }

    /**
     * Per-instance uninstall step. `$name` and `$isLastInstance` are accepted but ignored — every
     * row is now the only row of its engine on the server, so uninstall is always a full
     * `apt purge`.
     */
    public static function uninstallInstanceScript(
        string $engine,
        string $name = ServerCacheService::DEFAULT_INSTANCE_NAME,
        bool $isLastInstance = true,
    ): string {
        return self::legacyDefaultInstanceUninstallScriptWithApt($engine);
    }

    /**
     * Legacy single-instance install wrapper. Composes the apt-install step + the systemd
     * enable + ping verification.
     */
    public static function installScript(string $engine): string
    {
        return self::installPackageScript($engine)
            ."\n"
            .self::legacyDefaultInstanceInstallScript($engine);
    }

    /**
     * Full install script for a `ServerCacheService` row. Used by `InstallCacheServiceJob`.
     * One row per (server, engine), one engine per family, so the path is always
     * apt-install + systemctl enable + ping — no per-instance scaffolding to splice in.
     */
    public static function installScriptForRow(ServerCacheService $row): string
    {
        return self::installPackageScript($row->engine)
            ."\n"
            .self::legacyDefaultInstanceInstallScript($row->engine);
    }

    /**
     * Legacy single-instance uninstall wrapper.
     */
    public static function uninstallScript(string $engine): string
    {
        return self::legacyDefaultInstanceUninstallScriptWithApt($engine);
    }

    /**
     * Path to the engine's main config file. The `$name` argument is accepted for legacy
     * call-site compatibility but is ignored — every row uses the apt-shipped default config.
     */
    public static function instanceConfigPath(string $engine, string $name = ServerCacheService::DEFAULT_INSTANCE_NAME): string
    {
        return self::configFilePathFor($engine);
    }

    /**
     * Systemd service unit for a given instance. Like `instanceConfigPath`, `$name` is ignored;
     * every row maps to the engine's legacy unit (e.g. `redis-server.service`).
     */
    public static function instanceServiceUnit(string $engine, string $name = ServerCacheService::DEFAULT_INSTANCE_NAME): string
    {
        return self::systemdServiceFor($engine);
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
     * Legacy single-instance systemd unit name. Read by the AUTH / memory / config writers and
     * by `instanceServiceUnit`.
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
     * Path to the engine's main config file. Read by the AUTH / memory / config writers and by
     * `instanceConfigPath`.
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
}
