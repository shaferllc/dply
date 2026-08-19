<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Models\Server;
use App\Services\Servers\PhpRedisExtensionScripts;
use App\Support\Servers\ServerPhpMutationLock;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsPhpScripts
{
    protected function privilegedShellScript(Server $server, string $quotedVersions): string
    {
        $inner = <<<'BASH'
bash -lc '
php_runtime_installed() {
  local version="$1"
  if dpkg-query -W -f='\''${Status}'\'' "php${version}-cli" 2>/dev/null | grep -q "install ok installed"; then
    return 0
  fi
  if dpkg-query -W -f='\''${Status}'\'' "php${version}-fpm" 2>/dev/null | grep -q "install ok installed"; then
    return 0
  fi
  if dpkg-query -W -f='\''${Package}\n'\'' "php${version}-*" 2>/dev/null | grep -qE "^php${version}-"; then
    return 0
  fi
  if command -v "php${version}" >/dev/null 2>&1; then
    return 0
  fi
  if command -v "php-fpm${version}" >/dev/null 2>&1; then
    return 0
  fi
  if [ -x "/usr/bin/php${version}" ] || [ -x "/usr/sbin/php-fpm${version}" ]; then
    return 0
  fi
  return 1
}

supported_versions=(__SUPPORTED_VERSIONS__)
supported=false
installed_versions=()

if command -v dpkg-query >/dev/null 2>&1; then
  supported=true
  for version in "${supported_versions[@]}"; do
    if php_runtime_installed "$version"; then
      installed_versions+=("$version")
    fi
  done
  for d in /etc/php/*/fpm; do
    [ -d "$d" ] || continue
    version="$(basename "$(dirname "$d")")"
    case " ${installed_versions[*]} " in
      *" ${version} "*) ;;
      *) php_runtime_installed "$version" && installed_versions+=("$version") ;;
    esac
  done
elif command -v php >/dev/null 2>&1; then
  supported=true
fi

default_version=""
if command -v php >/dev/null 2>&1; then
  default_version="$(php -r "echo PHP_MAJOR_VERSION, chr(46), PHP_MINOR_VERSION;" 2>/dev/null || true)"
fi

printf "supported=%s\n" "$supported"
printf "installed_versions=%s\n" "$(IFS=,; echo "${installed_versions[*]}")"
printf "detected_default_version=%s\n" "$default_version"

for extdir in /etc/php/*/; do
  extver="$(basename "$extdir")"
  [ -d "/etc/php/${extver}/mods-available" ] || continue
  ext_available="$(ls -1 "/etc/php/${extver}/mods-available" 2>/dev/null | sed -n "s/\.ini\$//p" | sort -u | paste -sd, -)"
  ext_enabled=""
  for extsapi in cli fpm; do
    if [ -d "/etc/php/${extver}/${extsapi}/conf.d" ]; then
      ext_enabled="${ext_enabled}$(ls -1 "/etc/php/${extver}/${extsapi}/conf.d" 2>/dev/null | sed -n "s/^[0-9]*-//;s/\.ini\$//p")
"
    fi
  done
  ext_enabled="$(printf %s "$ext_enabled" | grep -v "^\$" | sort -u | paste -sd, -)"
  printf "extensions_available[%s]=%s\n" "$extver" "$ext_available"
  printf "extensions_enabled[%s]=%s\n" "$extver" "$ext_enabled"
done
'
BASH;

        $inner = str_replace('__SUPPORTED_VERSIONS__', $quotedVersions, $inner);
        $user = trim((string) $server->ssh_user);

        if ($user === '' || $user === 'root') {
            return $inner;
        }

        return 'sudo -n '.$inner;
    }

    protected function packageActionLockKey(Server $server): string
    {
        return ServerPhpMutationLock::key($server);
    }

    protected function packageActionLockSeconds(string $action): int
    {
        return match ($action) {
            'install', 'patch', 'uninstall' => 630,
            'set_cli_default', 'set_new_site_default', 'migrate_sites' => 630,
            default => 630,
        };
    }

    protected function packageActionSuccessMessage(string $action, string $version): string
    {
        return match ($action) {
            'install' => __('PHP :version installed.', ['version' => $version]),
            'set_cli_default' => __('PHP :version is now the CLI default.', ['version' => $version]),
            'set_new_site_default' => __('PHP :version is now the default for new PHP sites.', ['version' => $version]),
            'migrate_sites' => __('Sites moved off PHP :version.', ['version' => $version]),
            'patch' => __('PHP :version patched.', ['version' => $version]),
            'uninstall' => __('PHP :version uninstalled.', ['version' => $version]),
            default => __('PHP action completed.'),
        };
    }

    protected function packageActionScript(Server $server, string $action, string $version): string
    {
        $versionArg = escapeshellarg($version);

        $inner = match ($action) {
            'install' => $this->installPhpScript($version),
            'set_cli_default' => $this->setCliDefaultScript($version),
            'set_new_site_default' => "printf %s {$versionArg} >/dev/null",
            'patch' => "DEBIAN_FRONTEND=noninteractive apt-get install --only-upgrade -y php{$version}-cli php{$version}-fpm",
            'uninstall' => $this->uninstallPhpScript($version),
            default => throw new \RuntimeException('Unknown PHP package action.'),
        };

        $script = str_contains($inner, "\n")
            ? 'bash -lc '.escapeshellarg($inner)
            : $inner;

        if (trim((string) $server->ssh_user) === '' || trim((string) $server->ssh_user) === 'root') {
            return $script;
        }

        return 'sudo -n '.$script;
    }

    /**
     * Install a PHP version *after* initial provisioning (the Manage → PHP UI
     * and the failed-deploy “Upgrade PHP” remediation).
     *
     * Ubuntu stock repos (e.g. noble) only ship php8.3. Newer catalog IDs
     * (8.4, 8.5, …) need Ondřej Surý’s builds — the same ondrej/sury source
     * first-boot uses in BuildsProvisionWebserverPhp::ensureOndrejPhpRepository.
     * This script adds that repo when apt cannot see php{version}-cli, then
     * installs cli+fpm plus the first-boot required extensions (mysql/pgsql/
     * sqlite3/curl/mbstring/xml/redis). phpredis is required — Laravel defaults
     * to REDIS_CLIENT=phpredis, and a follow-up “Install php-redis” remediation
     * after switching the site to this version is too late. Remaining extras
     * (gd, sodium, …) stay best-effort so one missing package cannot abort
     * the install. FPM is enabled and restarted so the new pool actually
     * loads redis.
     */
    protected function installPhpScript(string $version): string
    {
        $stem = 'php'.$version;

        return implode("\n", [
            'set -e',
            'export DEBIAN_FRONTEND=noninteractive',
            $this->ensureOndrejPhpPackagesAvailableScript($version),
            'echo "[dply] installing '.$stem.' runtime and required extensions (incl. phpredis)..."',
            'apt-get install -y '
                .$stem.'-cli '.$stem.'-fpm '.$stem.'-common '
                .$stem.'-mysql '.$stem.'-pgsql '.$stem.'-sqlite3 '
                .$stem.'-curl '.$stem.'-mbstring '.$stem.'-xml '.$stem.'-redis',
            'for ext in gd sodium gmp apcu igbinary zip intl bcmath opcache; do',
            "  pkg=\"{$stem}-\$ext\"",
            '  if apt-cache show "$pkg" >/dev/null 2>&1; then',
            '    apt-get install -y "$pkg" || true',
            '  fi',
            'done',
            'phpenmod -v '.escapeshellarg($version).' redis 2>/dev/null || true',
            PhpRedisExtensionScripts::dedupe($version),
            'systemctl enable --now '.$stem.'-fpm',
            'systemctl restart '.$stem.'-fpm',
            'if ! '.$stem.' -m 2>/dev/null | grep -qi "^redis$"; then',
            '  echo "[dply] ERROR: phpredis is not loaded in '.$stem.' after install." >&2',
            '  exit 1',
            'fi',
            'systemctl is-active --quiet '.$stem.'-fpm',
            'echo "[dply] '.$stem.'-fpm is active with phpredis."',
        ]);
    }

    /**
     * Refresh apt, then add packages.sury.org (Launchpad fallback) when the
     * requested php{version}-cli package is not in the distro cache.
     */
    protected function ensureOndrejPhpPackagesAvailableScript(string $version): string
    {
        $pkg = 'php'.$version.'-cli';

        return implode("\n", [
            'echo "[dply] refreshing apt metadata..."',
            'apt-get update -y -o Acquire::Retries=3 || true',
            "if apt-cache show '{$pkg}' >/dev/null 2>&1; then",
            "  echo '[dply] {$pkg} is already available in apt; skipping ondrej/sury setup.'",
            'else',
            "  echo '[dply] {$pkg} is not in distro repos — adding ondrej/sury PHP repository.'",
            '  command -v curl >/dev/null 2>&1 || apt-get install -y curl ca-certificates',
            '  command -v gpg >/dev/null 2>&1 || apt-get install -y gnupg',
            '  command -v lsb_release >/dev/null 2>&1 || apt-get install -y lsb-release',
            '  install -d -m 0755 /etc/apt/keyrings',
            '  if curl -fsI -m 5 https://packages.sury.org/php/ >/dev/null 2>&1; then',
            '    echo "[dply] using packages.sury.org (primary upstream)"',
            '    curl -fsSL --retry 3 --retry-delay 2 --max-time 60 https://packages.sury.org/php/apt.gpg \\',
            '      | gpg --dearmor --yes -o /etc/apt/keyrings/sury-php.gpg',
            '    chmod 0644 /etc/apt/keyrings/sury-php.gpg',
            '    echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -cs) main" \\',
            '      > /etc/apt/sources.list.d/sury-php.list',
            '    rm -f /etc/apt/sources.list.d/ondrej-php.list',
            '  elif curl -fsI -m 5 https://ppa.launchpadcontent.net/ondrej/php/ubuntu/ >/dev/null 2>&1; then',
            '    echo "[dply] sury.org unreachable — falling back to ppa.launchpadcontent.net"',
            '    curl -fsSL --retry 3 --retry-delay 2 --max-time 60 \\',
            '      "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c" \\',
            '      | gpg --dearmor --yes -o /etc/apt/keyrings/ondrej-php.gpg',
            '    chmod 0644 /etc/apt/keyrings/ondrej-php.gpg',
            '    echo "deb [signed-by=/etc/apt/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu $(lsb_release -cs) main" \\',
            '      > /etc/apt/sources.list.d/ondrej-php.list',
            '    rm -f /etc/apt/sources.list.d/sury-php.list',
            '  else',
            '    echo "[dply] ERROR: neither packages.sury.org nor ppa.launchpadcontent.net is reachable from this host." >&2',
            '    exit 1',
            '  fi',
            '  apt-get update -y -o Acquire::Retries=3',
            "  if ! apt-cache show '{$pkg}' >/dev/null 2>&1; then",
            "    echo '[dply] ERROR: {$pkg} is still not available after adding ondrej/sury.' >&2",
            '    exit 1',
            '  fi',
            'fi',
        ]);
    }

    protected function setCliDefaultScript(string $version): string
    {
        $versionDigits = preg_replace('/\D/', '', $version) ?? $version;

        return implode("\n", [
            'set -e',
            "target=/usr/bin/php{$version}",
            'if [ ! -x "$target" ]; then',
            '  echo "PHP binary not found: $target" >&2',
            '  exit 1',
            'fi',
            "priority={$versionDigits}",
            'if update-alternatives --query php >/dev/null 2>&1; then',
            '  update-alternatives --install /usr/bin/php php "$target" "$priority" 2>/dev/null || true',
            '  update-alternatives --set php "$target"',
            'else',
            '  update-alternatives --install /usr/bin/php php "$target" "$priority"',
            'fi',
        ]);
    }

    protected function uninstallPhpScript(string $version): string
    {
        return implode("\n", [
            'set -e',
            "version={$version}",
            'packages="$(dpkg-query -W -f=\'${Package}\n\' "php${version}-*" 2>/dev/null | grep -E "^php${version}-" || true)"',
            'if [ -n "$packages" ]; then',
            '  DEBIAN_FRONTEND=noninteractive apt-get purge -y $packages',
            'fi',
            'if [ -d "/etc/php/${version}" ]; then',
            '  rm -rf "/etc/php/${version}"',
            'fi',
            'if command -v update-alternatives >/dev/null 2>&1; then',
            '  update-alternatives --auto php 2>/dev/null || true',
            'fi',
        ]);
    }

    protected function useRootSsh(): bool
    {
        return true;
    }

    protected function fallbackToDeployUserSsh(): bool
    {
        return true;
    }
}
