<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Install and enable/disable nginx dynamic modules on Debian/Ubuntu hosts.
 *
 * Follows the distro layout documented at
 * https://docs.nginx.com/nginx/admin-guide/dynamic-modules/dynamic-modules/
 * and Debian's modules-available / modules-enabled symlinks:
 *
 *   - `libnginx-mod-*` apt packages drop `.so` files + snippets under
 *     /etc/nginx/modules-available/
 *   - Enabling symlinks into /etc/nginx/modules-enabled/ (included from
 *     the top of nginx.conf)
 *
 * Built-in modules from `nginx -V` are listed read-only. Toggle runs
 * `nginx -t` before reload and reverts the symlink on validation failure.
 */
class NginxModulesConfig
{
    public const MODULES_AVAILABLE = '/etc/nginx/modules-available';

    public const MODULES_ENABLED = '/etc/nginx/modules-enabled';

    /**
     * Dynamic modules dply must not disable (stub_status observability, TLS).
     *
     * @var list<string> module conf stems (without .conf)
     */
    public const PROTECTED_MODULES = [
        'mod-http-stub-status',
        '50-mod-http-stub-status',
    ];

    /**
     * @return array{
     *     modules: list<array{
     *         name: string,
     *         conf_file: string,
     *         enabled: bool,
     *         protected: bool,
     *         type: string,
     *         source: string,
     *         package: string,
     *         installed: bool,
     *         so_path: string,
     *     }>,
     *     builtins: list<array{name: string, type: string}>,
     *     supports_dynamic: bool,
     *     unreadable: bool,
     * }
     */
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $output = $ssh->exec($this->readScript(), 30);
            if ($ssh->lastExecExitCode() !== 0 && trim($output) === '') {
                return $this->emptyUnreadable();
            }
        } catch (\Throwable) {
            return $this->emptyUnreadable();
        }

        return $this->parseReadOutput($output);
    }

    /**
     * Enable or disable a dynamic module (install apt package first when needed).
     *
     * @throws \RuntimeException
     */
    public function toggle(Server $server, string $name, bool $enable, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
            throw new \RuntimeException('Invalid module name.');
        }

        $stem = $this->normalizeModuleStem($name);

        if (! $enable && in_array($stem, self::PROTECTED_MODULES, true)) {
            throw new \RuntimeException("`{$stem}` is required for dply observability — disabling it is blocked.");
        }

        $inventory = $this->read($server);
        if (! $inventory['supports_dynamic']) {
            throw new \RuntimeException('This server does not use Debian-style nginx dynamic modules (/etc/nginx/modules-available). Install nginx from apt on Ubuntu/Debian to manage modules here.');
        }

        $row = $this->findModuleRow($inventory['modules'], $stem);
        if ($row === null) {
            throw new \RuntimeException("Unknown module `{$stem}`. Reload the module list and try again.");
        }

        if ($enable && ! $row['installed']) {
            $package = trim((string) ($row['package'] ?? ''));
            if ($package === '') {
                throw new \RuntimeException("Module `{$stem}` is not installed and no apt package was found.");
            }
            $this->aptInstall($server, $package, $emit);
        }

        $confFile = (string) ($row['conf_file'] ?? $stem);
        $ssh = new SshConnection($server);
        $availableConf = self::MODULES_AVAILABLE.'/'.basename($confFile).'.conf';
        $enabledLink = self::MODULES_ENABLED.'/'.basename($confFile).'.conf';

        if ($enable) {
            $emit->step('nginx-modules', sprintf('Enabling `%s`', $stem));
            $cmd = sprintf(
                'sudo -n test -f %s && sudo -n ln -sf %s %s 2>&1; echo "__exit__:$?"',
                escapeshellarg($availableConf),
                escapeshellarg('../modules-available/'.basename($confFile).'.conf'),
                escapeshellarg($enabledLink),
            );
        } else {
            $emit->step('nginx-modules', sprintf('Disabling `%s`', $stem));
            $cmd = sprintf('sudo -n rm -f %s 2>&1; echo "__exit__:$?"', escapeshellarg($enabledLink));
        }

        $this->execWithExit($ssh, $cmd, $emit, $enable ? 'enable' : 'disable', 30);

        $this->validateReloadOrRevert($server, $stem, $confFile, $enable, $ssh, $emit);
    }

    /**
     * @return array{
     *     modules: list<array{
     *         name: string,
     *         conf_file: string,
     *         enabled: bool,
     *         protected: bool,
     *         type: string,
     *         source: string,
     *         package: string,
     *         installed: bool,
     *         so_path: string,
     *     }>,
     *     builtins: list<array{name: string, type: string}>,
     *     supports_dynamic: bool,
     *     unreadable: bool,
     * }
     */
    private function parseReadOutput(string $output): array
    {
        $layout = trim($this->section($output, 'LAYOUT'));
        $supportsDynamic = $layout === 'debian';

        $availableRows = [];
        foreach (preg_split('/\R/', trim($this->section($output, 'AVAILABLE'))) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$name, $so, $pkg, $installedFlag] = array_pad(explode('|', $line, 4), 4, '');
            $stem = $this->normalizeModuleStem(trim($name));
            if ($stem === '') {
                continue;
            }
            $availableRows[$stem] = [
                'name' => $stem,
                'conf_file' => trim($name),
                'so_path' => trim($so),
                'package' => trim($pkg),
                'installed' => $installedFlag === '1',
            ];
        }

        $enabled = [];
        foreach ($this->splitLines($this->section($output, 'ENABLED')) as $name) {
            $enabled[$this->normalizeModuleStem($name)] = true;
        }

        $aptPackages = $this->splitLines($this->section($output, 'APT'));
        foreach ($aptPackages as $package) {
            $stem = $this->packageToModuleStem($package);
            if ($stem === '' || isset($availableRows[$stem])) {
                continue;
            }
            $availableRows[$stem] = [
                'name' => $stem,
                'conf_file' => $stem,
                'so_path' => '',
                'package' => $package,
                'installed' => false,
            ];
        }

        $modules = [];
        foreach ($availableRows as $stem => $meta) {
            $modules[] = [
                'name' => $stem,
                'conf_file' => (string) ($meta['conf_file']),
                'enabled' => isset($enabled[$stem]),
                'protected' => in_array($stem, self::PROTECTED_MODULES, true),
                'type' => $this->classify($stem),
                'source' => 'dynamic',
                'package' => $meta['package'],
                'installed' => (bool) ($meta['installed']),
                'so_path' => $meta['so_path'],
            ];
        }

        usort($modules, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $builtins = $this->parseBuiltins($this->section($output, 'BUILTIN'));

        return [
            'modules' => $modules,
            'builtins' => $builtins,
            'supports_dynamic' => $supportsDynamic,
            'unreadable' => false,
        ];
    }

    /**
     * @return list<array{name: string, type: string}>
     */
    private function parseBuiltins(string $nginxV): array
    {
        if ($nginxV === '') {
            return [];
        }

        if (! preg_match('/configure arguments:\s*(.+)$/m', $nginxV, $m)) {
            return [];
        }

        $args = $m[1];
        $rows = [];
        if (preg_match_all('/--with(?:out)?-([a-z0-9_]+)_module\b/', $args, $matches) !== false) {
            foreach ($matches[1] ?? [] as $stem) {
                $rows[] = [
                    'name' => str_replace('_', '-', $stem),
                    'type' => 'builtin',
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     * @return array<string, mixed>|null
     */
    private function findModuleRow(array $modules, string $stem): ?array
    {
        foreach ($modules as $row) {
            if (($row['name'] ?? '') === $stem) {
                return $row;
            }
        }

        return null;
    }

    private function aptInstall(Server $server, string $package, ConsoleEmitter $emit): void
    {
        if (! preg_match('/^libnginx-mod-[a-z0-9][a-z0-9+.-]*$/', $package)) {
            throw new \RuntimeException('Invalid apt package name.');
        }

        $emit->step('nginx-modules', sprintf('Installing apt package `%s`', $package));
        $ssh = new SshConnection($server);
        $cmd = sprintf(
            'sudo -n DEBIAN_FRONTEND=noninteractive apt-get install -y -qq %s 2>&1; echo "__exit__:$?"',
            escapeshellarg($package),
        );
        $this->execWithExit($ssh, $cmd, $emit, 'apt-get install', 300);
    }

    private function validateReloadOrRevert(
        Server $server,
        string $stem,
        string $confFile,
        bool $wasEnable,
        SshConnection $ssh,
        ConsoleEmitter $emit,
    ): void {
        $emit->step('nginx-modules', 'Validating with `nginx -t`');
        $validate = $ssh->exec('sudo -n nginx -t 2>&1; echo "__exit__:$?"', 30);
        $vexit = (preg_match('/__exit__:(\d+)\s*$/', $validate, $vm) === 1) ? (int) $vm[1] : 1;
        $vstripped = (string) preg_replace('/__exit__:\d+\s*$/', '', $validate);
        foreach (preg_split('/\R/', trim($vstripped)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit($line, $vexit !== 0 ? ConsoleAction::LEVEL_WARN : ConsoleAction::LEVEL_INFO);
            }
        }

        $isInvalid = $vexit !== 0
            || (stripos($vstripped, 'test failed') !== false && stripos($vstripped, 'successful') === false);

        if ($isInvalid) {
            $enabledLink = self::MODULES_ENABLED.'/'.basename($confFile).'.conf';
            $undo = $wasEnable
                ? sprintf('sudo -n rm -f %s 2>&1', escapeshellarg($enabledLink))
                : sprintf(
                    'sudo -n ln -sf %s %s 2>&1',
                    escapeshellarg('../modules-available/'.basename($confFile).'.conf'),
                    escapeshellarg($enabledLink),
                );
            $emit->step('nginx-modules', 'Validation failed — reverting module symlink');
            $ssh->exec($undo, 15);
            $emit->error('Module change reverted; nginx was not reloaded.');
            throw new \RuntimeException('Config validation failed after module change; reverted.'."\n".trim($vstripped));
        }

        $emit->success('Config validated.');

        $emit->step('nginx-modules', 'Reloading nginx');
        $reload = $ssh->exec('sudo -n systemctl reload nginx 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart nginx 2>&1', 30);
        }

        $emit->success(sprintf('nginx reloaded — module `%s` is now %s.', $stem, $wasEnable ? 'enabled' : 'disabled'));
    }

    private function execWithExit(SshConnection $ssh, string $cmd, ConsoleEmitter $emit, string $label, int $timeout): void
    {
        $out = $ssh->exec($cmd, $timeout);
        $exit = (preg_match('/__exit__:(\d+)\s*$/', $out, $m) === 1) ? (int) $m[1] : 1;
        $stripped = (string) preg_replace('/__exit__:\d+\s*$/', '', $out);
        foreach (preg_split('/\R/', trim($stripped)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit($line, $exit !== 0 ? ConsoleAction::LEVEL_WARN : ConsoleAction::LEVEL_INFO);
            }
        }
        if ($exit !== 0) {
            $emit->error(sprintf('%s exited %d', $label, $exit));
            throw new \RuntimeException(sprintf('%s failed (exit %d): %s', $label, $exit, trim($stripped)));
        }
    }

    private function readScript(): string
    {
        return <<<'BASH'
set +e
echo '---DPLY_LAYOUT---'
if [ -d /etc/nginx/modules-available ]; then echo debian; else echo legacy; fi
echo '---DPLY_AVAILABLE---'
if [ -d /etc/nginx/modules-available ]; then
  for f in /etc/nginx/modules-available/*.conf; do
    [ -f "$f" ] || continue
    name=$(basename "$f" .conf)
    so=$(grep -m1 '^\s*load_module' "$f" 2>/dev/null | awk '{print $2}' | tr -d ';')
    pkg=""
    if [ -n "$so" ]; then
      full="/usr/lib/nginx/$so"
      [ -f "$full" ] || full="/usr/lib/nginx/modules/${so#modules/}"
      pkg=$(dpkg -S "$full" 2>/dev/null | head -n1 | cut -d: -f1)
    fi
    installed=0
    if [ -n "$so" ]; then
      full="/usr/lib/nginx/$so"
      [ -f "$full" ] || full="/usr/lib/nginx/modules/${so#modules/}"
      [ -f "$full" ] && installed=1
    fi
    echo "${name}|${so}|${pkg}|${installed}"
  done | sort
fi
echo '---DPLY_ENABLED---'
if [ -d /etc/nginx/modules-enabled ]; then
  for f in /etc/nginx/modules-enabled/*.conf; do
    [ -e "$f" ] || continue
    basename "$f" .conf
  done | sort -u
fi
echo '---DPLY_APT---'
apt-cache search --names-only '^libnginx-mod-' 2>/dev/null | awk '{print $1}' | sort -u
echo '---DPLY_BUILTIN---'
nginx -V 2>&1
BASH;
    }

    private function section(string $output, string $key): string
    {
        $head = '---DPLY_'.$key.'---';
        $start = strpos($output, $head);
        if ($start === false) {
            return '';
        }
        $start += strlen($head);
        $next = preg_match('/\n---DPLY_[A-Z]+---/', $output, $m, PREG_OFFSET_CAPTURE, $start);
        $end = $next === 1 ? $m[0][1] : strlen($output);

        return substr($output, $start, $end - $start);
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $blob): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim($blob)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    private function normalizeModuleStem(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^\d+-(.+)$/', $name, $m) === 1) {
            return $m[1];
        }

        return $name;
    }

    private function packageToModuleStem(string $package): string
    {
        if (! str_starts_with($package, 'libnginx-mod-')) {
            return '';
        }

        return 'mod-'.substr($package, strlen('libnginx-mod-'));
    }

    private function classify(string $name): string
    {
        if (str_contains($name, 'stream')) {
            return 'stream';
        }
        if (str_contains($name, 'mail')) {
            return 'mail';
        }
        if (str_contains($name, 'ssl') || str_contains($name, 'tls') || str_contains($name, 'brotli')) {
            return 'tls';
        }
        if (str_contains($name, 'geoip') || str_contains($name, 'geo')) {
            return 'geo';
        }
        if (str_contains($name, 'image') || str_contains($name, 'xslt') || str_contains($name, 'gunzip')) {
            return 'content';
        }
        if (str_contains($name, 'auth') || str_contains($name, 'ldap')) {
            return 'auth';
        }
        if (str_contains($name, 'cache') || str_contains($name, 'limit') || str_contains($name, 'upstream')) {
            return 'perf';
        }
        if (str_contains($name, 'security') || str_contains($name, 'modsecurity') || str_contains($name, 'naxsi')) {
            return 'security';
        }
        if (str_contains($name, 'status') || str_contains($name, 'dav') || str_contains($name, 'perl')) {
            return 'observability';
        }

        return 'other';
    }

    /**
     * @return array{
     *     modules: list<array<string, mixed>>,
     *     builtins: list<array{name: string, type: string}>,
     *     supports_dynamic: bool,
     *     unreadable: bool,
     * }
     */
    private function emptyUnreadable(): array
    {
        return [
            'modules' => [],
            'builtins' => [],
            'supports_dynamic' => false,
            'unreadable' => true,
        ];
    }
}
