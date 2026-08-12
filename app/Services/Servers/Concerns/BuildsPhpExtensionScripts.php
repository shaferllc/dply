<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Models\Server;
use App\Services\Servers\ServerAptLockBash;
use App\Support\Sites\SiteFixers;

/**
 * Remote bash for per-extension management, alongside {@see BuildsPhpScripts}
 * which owns the per-version scripts.
 *
 * The install path is lifted from the `install_php_redis` fixer in
 * {@see SiteFixers}: try the apt package first, fall back to
 * a PECL build when this PHP version has no binary (common on very new
 * versions from ondrej/php). apt lock contention is handled by the caller
 * wrapping these with {@see ServerAptLockBash::wrapManageScript()},
 * so nothing here re-implements the lock wait.
 */
trait BuildsPhpExtensionScripts
{
    /**
     * apt install, falling back to a PECL build for catalog entries that allow
     * it. Ends by restarting FPM (a freshly added .so is not picked up by a
     * reload) and re-checking, so the output states plainly whether the module
     * is live rather than just whether apt exited 0.
     */
    protected function extensionInstallScript(string $version, string $extension, bool $allowPecl): string
    {
        $pkg = "php{$version}-{$extension}";
        $fpm = "php{$version}-fpm";

        $lines = [
            'export DEBIAN_FRONTEND=noninteractive',
            'APT="apt-get -o DPkg::Lock::Timeout=120"',
            '$APT update -y >/dev/null 2>&1 || true',
            "if \$APT install -y {$pkg}; then",
            "  echo \"[dply] installed {$pkg} from apt\"",
        ];

        if ($allowPecl) {
            $lines = array_merge($lines, [
                'else',
                "  echo \"[dply] {$pkg} unavailable from apt — building {$extension} from PECL\"",
                "  \$APT install -y php{$version}-dev php-pear build-essential autoconf pkg-config || exit 1",
                "  (yes \"\" | pecl install -f {$extension}) || exit 1",
                "  echo \"extension={$extension}.so\" > \"/etc/php/{$version}/mods-available/{$extension}.ini\"",
                "  phpenmod -v \"{$version}\" {$extension} || true",
                "  echo \"[dply] built {$extension} from PECL\"",
                'fi',
            ]);
        } else {
            $lines = array_merge($lines, [
                'else',
                "  echo \"[dply] ERROR: {$pkg} is not available from apt for PHP {$version}.\" >&2",
                '  exit 1',
                'fi',
            ]);
        }

        return implode("\n", array_merge($lines, [
            "systemctl restart {$fpm} >/dev/null 2>&1 || true",
            $this->extensionVerifyBash($version, $extension),
        ]));
    }

    /**
     * apt purge for the package. Deliberately does not `apt-get autoremove` —
     * on a shared box that can pull out libraries another PHP version still
     * links against.
     */
    protected function extensionUninstallScript(string $version, string $extension): string
    {
        $pkg = "php{$version}-{$extension}";
        $fpm = "php{$version}-fpm";

        return implode("\n", [
            'export DEBIAN_FRONTEND=noninteractive',
            'APT="apt-get -o DPkg::Lock::Timeout=120"',
            "if dpkg-query -W -f='\${Status}' {$pkg} 2>/dev/null | grep -q \"install ok installed\"; then",
            "  \$APT purge -y {$pkg}",
            "  echo \"[dply] purged {$pkg}\"",
            'else',
            // PECL-built extensions have no apt package — drop the ini we wrote.
            "  phpdismod -v \"{$version}\" {$extension} >/dev/null 2>&1 || true",
            "  rm -f \"/etc/php/{$version}/mods-available/{$extension}.ini\"",
            "  echo \"[dply] removed {$extension} module config (no apt package present)\"",
            'fi',
            "systemctl restart {$fpm} >/dev/null 2>&1 || true",
        ]);
    }

    /**
     * phpenmod / phpdismod against every module the package provides — one apt
     * package can ship several (php-mysql gives mysqli and pdo_mysql), and
     * leaving half of them enabled is worse than either state.
     *
     * @param  list<string>  $modules
     */
    protected function extensionToggleScript(string $version, array $modules, bool $enable): string
    {
        $binary = $enable ? 'phpenmod' : 'phpdismod';
        $verb = $enable ? 'enabled' : 'disabled';
        $fpm = "php{$version}-fpm";
        $lines = [];

        foreach ($modules as $module) {
            $lines[] = "{$binary} -v \"{$version}\" {$module} >/dev/null 2>&1 && echo \"[dply] {$verb} {$module}\" || echo \"[dply] {$module}: nothing to change\"";
        }

        $lines[] = "systemctl restart {$fpm} >/dev/null 2>&1 || true";

        return implode("\n", $lines);
    }

    /**
     * Shared tail that reports whether the module actually loaded. Matches
     * case-insensitively and anchored, so `gd` does not match `gd_info` and
     * OPcache's "Zend OPcache" display name still resolves.
     */
    protected function extensionVerifyBash(string $version, string $extension): string
    {
        return implode("\n", [
            "if php{$version} -m 2>/dev/null | grep -qi \"^{$extension}\$\"; then",
            "  echo \"[dply] php{$version}: {$extension} is loaded\"",
            'else',
            "  echo \"[dply] WARNING: php{$version} does not report {$extension} as loaded — it may need enabling or an FPM restart\"",
            'fi',
        ]);
    }

    /**
     * Wrap for the synchronous SSH path (toggles). apt-based actions go through
     * ServerManageRemoteSshJob instead, which does its own wrapping.
     */
    protected function extensionActionScript(Server $server, string $script): string
    {
        $wrapped = 'bash -lc '.escapeshellarg("set -e\n".$script);
        $user = trim((string) $server->ssh_user);

        if ($user === '' || $user === 'root') {
            return $wrapped;
        }

        return 'sudo -n '.$wrapped;
    }

    /**
     * Timeout budget per action. PECL builds compile from source, so installs
     * get the long end; toggles are a symlink plus an FPM restart.
     */
    protected function extensionActionTimeout(string $action, bool $allowPecl): int
    {
        return match ($action) {
            'install' => $allowPecl ? 900 : 300,
            'uninstall' => 300,
            default => 60,
        };
    }
}
