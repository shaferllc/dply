<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Models\Server;
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
  default_version="$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || true)"
fi

printf "supported=%s\n" "$supported"
printf "installed_versions=%s\n" "$(IFS=,; echo "${installed_versions[*]}")"
printf "detected_default_version=%s\n" "$default_version"
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
            'install' => "DEBIAN_FRONTEND=noninteractive apt-get install -y php{$version}-cli php{$version}-fpm",
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
