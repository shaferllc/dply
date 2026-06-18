<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Wraps Apache's `a2enmod` / `a2dismod` to toggle modules from the UI
 * without shelling into the box. Lists available modules from
 * `/etc/apache2/mods-available/` and which are enabled (symlinks in
 * `/etc/apache2/mods-enabled/`).
 *
 * Save pipeline: `a2enmod`/`a2dismod` → `apachectl configtest` →
 * `systemctl reload apache2`. Failed config tests revert via
 * `a2dismod`/`a2enmod` (i.e. undo the toggle) so a bad module load
 * can't leave Apache in a non-validating state.
 */
class ApacheModulesConfig
{
    /**
     * Modules dply considers "core" — a2dismod against these breaks the
     * provisioner. We surface them read-only.
     *
     * @var list<string>
     */
    public const PROTECTED_MODULES = [
        'mpm_event',
        'mpm_worker',
        'mpm_prefork',
        'authz_core',
        'unixd',
        'log_config',
        'mime',
        'dir',
        'env',
        'alias',
        'autoindex',
        'filter',
        'reqtimeout',
        'access_compat',
        'auth_basic',
        'authn_file',
        'authn_core',
        'authz_host',
        'authz_user',
    ];

    /**
     * @return array{modules: list<array{name: string, enabled: bool, protected: bool, type: string}>, unreadable: bool}
     */
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $script = <<<'BASH'
set +e
# List of available modules (one .load per module).
ls -1 /etc/apache2/mods-available/*.load 2>/dev/null | xargs -I{} basename {} .load | sort -u
echo '---ENABLED---'
ls -1 /etc/apache2/mods-enabled/*.load 2>/dev/null | xargs -I{} basename {} .load | sort -u
BASH;
            $output = $ssh->exec($script, 15);
            if ($ssh->lastExecExitCode() !== 0 || $output === '') {
                return ['modules' => [], 'unreadable' => true];
            }
        } catch (\Throwable) {
            return ['modules' => [], 'unreadable' => true];
        }

        [$availableBlob, $enabledBlob] = array_pad(explode('---ENABLED---', $output, 2), 2, '');
        $available = $this->splitNames($availableBlob);
        $enabled = array_flip($this->splitNames($enabledBlob));

        $rows = [];
        foreach ($available as $name) {
            $rows[] = [
                'name' => $name,
                'enabled' => isset($enabled[$name]),
                'protected' => in_array($name, self::PROTECTED_MODULES, true),
                'type' => $this->classify($name),
            ];
        }

        return ['modules' => $rows, 'unreadable' => false];
    }

    /**
     * Toggle a module — `a2enmod` if currently off, `a2dismod` if on.
     * Idempotent: a2enmod on an already-enabled module is a no-op.
     *
     * @throws \RuntimeException
     */
    public function toggle(Server $server, string $name, bool $enable, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);

        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \RuntimeException('Invalid module name.');
        }
        if (! $enable && in_array($name, self::PROTECTED_MODULES, true)) {
            throw new \RuntimeException("`{$name}` is a dply / Apache core module — disabling it would break the provisioner.");
        }

        $ssh = new SshConnection($server);
        $cmd = $enable ? 'a2enmod' : 'a2dismod';
        $undo = $enable ? 'a2dismod' : 'a2enmod';

        $emit->step('apache-modules', sprintf('Running `%s %s`', $cmd, $name));
        $out = $ssh->exec(sprintf('sudo -n %s %s 2>&1; echo "__exit__:$?"', $cmd, escapeshellarg($name)), 30);
        $exit = (preg_match('/__exit__:(\d+)\s*$/', $out, $m) === 1) ? (int) $m[1] : 1;
        $stripped = (string) preg_replace('/__exit__:\d+\s*$/', '', $out);
        foreach (preg_split('/\R/', trim($stripped)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit($line, $exit !== 0 ? ConsoleAction::LEVEL_WARN : ConsoleAction::LEVEL_INFO);
            }
        }
        if ($exit !== 0) {
            $emit->error(sprintf('%s exited %d — module list unchanged.', $cmd, $exit));
            throw new \RuntimeException(sprintf('%s failed (exit %d): %s', $cmd, $exit, trim($stripped)));
        }

        // Validate before reloading. If the new state is invalid, undo the toggle.
        $emit->step('apache-modules', 'Validating with `apachectl configtest`');
        $validate = $ssh->exec('sudo -n apachectl configtest 2>&1; echo "__exit__:$?"', 30);
        $vexit = (preg_match('/__exit__:(\d+)\s*$/', $validate, $vm) === 1) ? (int) $vm[1] : 1;
        $vstripped = (string) preg_replace('/__exit__:\d+\s*$/', '', $validate);
        foreach (preg_split('/\R/', trim($vstripped)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit($line, $vexit !== 0 ? ConsoleAction::LEVEL_WARN : ConsoleAction::LEVEL_INFO);
            }
        }
        $isInvalid = $vexit !== 0 || (stripos($vstripped, 'syntax error') !== false && stripos($vstripped, 'syntax ok') === false);
        if ($isInvalid) {
            $emit->step('apache-modules', sprintf('Validation failed — undoing with `%s %s`', $undo, $name));
            $ssh->exec(sprintf('sudo -n %s %s 2>&1', $undo, escapeshellarg($name)), 30);
            $emit->error('Module toggle reverted; Apache was not reloaded.');
            throw new \RuntimeException('Config validation failed after toggle; reverted. apachectl configtest output:'."\n".trim($vstripped));
        }
        $emit->success('Config validated.');

        $emit->step('apache-modules', 'Reloading Apache');
        $reload = $ssh->exec('sudo -n systemctl reload apache2 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart apache2 2>&1', 30);
        }
        $emit->success(sprintf('Apache reloaded — module `%s` is now %s.', $name, $enable ? 'enabled' : 'disabled'));
    }

    /**
     * @return list<string>
     */
    private function splitNames(string $blob): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim($blob)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Group modules into rough categories so the UI can let the operator
     * filter / find what they're after without scrolling 100+ entries.
     */
    private function classify(string $name): string
    {
        if (str_starts_with($name, 'mpm_')) {
            return 'mpm';
        }
        if (str_starts_with($name, 'ssl') || str_starts_with($name, 'tls') || str_contains($name, 'crypto')) {
            return 'tls';
        }
        if (str_starts_with($name, 'auth') || str_contains($name, 'authn') || str_contains($name, 'authz')) {
            return 'auth';
        }
        if (str_starts_with($name, 'proxy') || str_starts_with($name, 'lbmethod')) {
            return 'proxy';
        }
        if (str_starts_with($name, 'cache') || str_contains($name, 'compress') || str_contains($name, 'deflate') || str_contains($name, 'brotli') || str_contains($name, 'expires')) {
            return 'perf';
        }
        if (str_contains($name, 'security') || str_contains($name, 'evasive') || str_contains($name, 'ratelimit')) {
            return 'security';
        }
        if (str_starts_with($name, 'log') || str_contains($name, 'status')) {
            return 'observability';
        }
        if (str_contains($name, 'rewrite') || str_contains($name, 'headers') || str_contains($name, 'expires') || str_contains($name, 'macro')) {
            return 'core';
        }

        return 'other';
    }
}
