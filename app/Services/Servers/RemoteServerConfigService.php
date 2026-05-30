<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;

/**
 * Read / write / backup / restore for non-webserver allowlisted config files.
 *
 * Webserver engine paths are handled by {@see RemoteWebserverConfigService}.
 * Validation uses {@see config('server_manage.config_validation_hooks')}.
 */
class RemoteServerConfigService
{
    private const BACKUP_DIR = '/var/lib/dply/config-backups';

    public function __construct(
        protected ServerManageSshExecutor $executor,
    ) {}

    /**
     * @return array{contents: string, truncated: bool, size: int}
     */
    public function read(Server $server, string $path, ?ConsoleEmitter $emitter = null): array
    {
        $this->assertPathAllowed($path);

        $cap = (int) config('server_manage.config_preview_max_bytes', 48_000);

        $emitter?->step('config', 'Reading '.$path);
        $script = sprintf(
            '{ stat -c "%%s" %1$s 2>/dev/null; echo "---"; head -c %2$d %1$s 2>/dev/null; } || true',
            escapeshellarg($path),
            $cap,
        );

        $output = $this->runScript($server, 'server-config:read', $script, 60);
        [$head, $body] = array_pad(explode("---\n", $output, 2), 2, '');
        $size = (int) trim($head);
        $truncated = $size > $cap;

        $emitter?->info(sprintf('Read %d bytes%s.', $size, $truncated ? ' (truncated for preview)' : ''));

        return [
            'contents' => (string) $body,
            'truncated' => $truncated,
            'size' => $size,
        ];
    }

    /**
     * @return array{backup: ?string, validate_output: string, validate_ok: bool, reverted?: bool}
     */
    public function write(Server $server, string $path, string $contents, ?ConsoleEmitter $emitter = null): array
    {
        $this->assertPathAllowed($path);

        $max = (int) config('server_manage.config_edit_max_bytes', 256_000);
        if (strlen($contents) > $max) {
            throw new \InvalidArgumentException("Config payload exceeds the {$max}-byte limit.");
        }

        $backupDir = self::BACKUP_DIR;
        $backupSlug = $this->backupSlugFor($path);
        $backupPath = $backupDir.'/'.$backupSlug.'.'.now()->getTimestamp();
        $keep = max(1, (int) config('server_manage.config_edit_backup_keep', 10));
        $b64 = base64_encode($contents);

        $script = <<<BASH
set -euo pipefail
PATHX={$this->bashArg($path)}
BACKUP_DIR={$this->bashArg($backupDir)}
BACKUP_PATH={$this->bashArg($backupPath)}
KEEP={$keep}

(sudo -n install -d -m 0700 -o root -g root "\$BACKUP_DIR" || install -d -m 0700 -o root -g root "\$BACKUP_DIR") 2>&1

if [ -e "\$PATHX" ]; then
  (sudo -n cp -a "\$PATHX" "\$BACKUP_PATH" || cp -a "\$PATHX" "\$BACKUP_PATH") 2>&1
  echo "[backup] \$BACKUP_PATH"
fi

TMP=\$(mktemp)
printf %s {$this->bashArg($b64)} | base64 -d > "\$TMP"
(sudo -n install -m 0644 -o root -g root "\$TMP" "\$PATHX" || install -m 0644 -o root -g root "\$TMP" "\$PATHX") 2>&1
rm -f "\$TMP"

ls -1t "\$BACKUP_DIR"/{$this->bashLiteral($backupSlug)}.* 2>/dev/null | tail -n +\$((KEEP + 1)) | xargs -r sudo -n rm -f -- 2>/dev/null || true
BASH;

        $emitter?->step('config', 'Backing up live file to '.$backupPath);
        $emitter?->step('config', 'Atomically installing new contents at '.$path);
        $this->runScript($server, 'server-config:write', $script, 60, $emitter);

        $hook = $this->validationHookFor($path);
        $validateOutput = '';
        $validateOk = true;

        if ($hook !== null && ! empty($hook['validate'])) {
            $emitter?->step('config', 'Running validation hook');
            $validateOutput = $this->runScript(
                $server,
                'server-config:validate',
                (string) $hook['validate'],
                (int) ($hook['validate_timeout'] ?? 60),
                $emitter,
            );
            $validateOk = $this->validateOutputLooksOk($validateOutput, $hook);
        } else {
            $emitter?->info('No validation hook for this path — save accepted.');
        }

        if (! $validateOk) {
            $emitter?->warn('Validator rejected the new file — restoring snapshot.');
            $revertScript = <<<BASH
set -uo pipefail
PATHX={$this->bashArg($path)}
BACKUP_PATH={$this->bashArg($backupPath)}
if [ -e "\$BACKUP_PATH" ]; then
  (sudo -n install -m 0644 -o root -g root "\$BACKUP_PATH" "\$PATHX" || install -m 0644 -o root -g root "\$BACKUP_PATH" "\$PATHX") 2>&1
  echo "[revert] restored \$BACKUP_PATH"
fi
BASH;
            $this->runScript($server, 'server-config:revert', $revertScript, 30, $emitter);
            $emitter?->error('Save aborted; previous file restored.');
        } else {
            $emitter?->success('Saved'.($hook !== null ? ' + validated' : '').'.');
        }

        return [
            'backup' => $backupPath,
            'validate_output' => trim($validateOutput),
            'validate_ok' => $validateOk,
            'reverted' => ! $validateOk,
        ];
    }

    /**
     * @return array{output: string, ok: bool}
     */
    public function validateContent(Server $server, string $path, string $contents, ?ConsoleEmitter $emitter = null): array
    {
        $this->assertPathAllowed($path);

        $engine = app(ServerConfigFileCatalog::class)->webserverEngineForPath($path);
        if ($engine !== null) {
            return app(RemoteWebserverConfigService::class)->validateContent(
                $server,
                $engine,
                $path,
                $contents,
                $emitter,
            );
        }

        $hook = $this->validationHookFor($path);
        if ($hook !== null && ! empty($hook['engine'])) {
            return app(RemoteWebserverConfigService::class)->validateContent(
                $server,
                (string) $hook['engine'],
                $path,
                $contents,
                $emitter,
            );
        }

        if ($hook === null || empty($hook['validate'])) {
            $emitter?->warn('No validation hook for this path.');

            return [
                'output' => __('No validator configured for this path.'),
                'ok' => false,
            ];
        }

        $max = (int) config('server_manage.config_edit_max_bytes', 256_000);
        if (strlen($contents) > $max) {
            throw new \InvalidArgumentException("Config payload exceeds the {$max}-byte limit.");
        }

        $b64 = base64_encode($contents);
        $bakStem = '/tmp/dply-cfg-dryrun.'.bin2hex(random_bytes(6));

        $script = <<<BASH
set -uo pipefail
PATHX={$this->bashArg($path)}
BAK={$this->bashArg($bakStem)}
TMP=\$(mktemp)
printf %s {$this->bashArg($b64)} | base64 -d > "\$TMP"

restore() {
  if [ -e "\$BAK" ]; then
    (sudo -n install -m 0644 -o root -g root "\$BAK" "\$PATHX" || install -m 0644 -o root -g root "\$BAK" "\$PATHX") >/dev/null 2>&1
    (sudo -n rm -f "\$BAK" || rm -f "\$BAK") >/dev/null 2>&1
  fi
  rm -f "\$TMP" 2>/dev/null
}
trap restore EXIT INT TERM HUP

if [ -e "\$PATHX" ]; then
  (sudo -n cp -a "\$PATHX" "\$BAK" || cp -a "\$PATHX" "\$BAK") >/dev/null 2>&1
fi
(sudo -n install -m 0644 -o root -g root "\$TMP" "\$PATHX" || install -m 0644 -o root -g root "\$TMP" "\$PATHX") 2>&1

{$hook['validate']}
BASH;

        $emitter?->step('config', 'Staging proposed contents for dry-run validation');
        $output = $this->runScript(
            $server,
            'server-config:validate-buffer',
            $script,
            (int) ($hook['validate_timeout'] ?? 60) + 10,
            $emitter,
        );

        $ok = $this->validateOutputLooksOk($output, $hook);
        if ($ok) {
            $emitter?->success('Buffer is valid — safe to save.');
        } else {
            $emitter?->error('Buffer failed validation.');
        }

        return [
            'output' => trim($output),
            'ok' => $ok,
        ];
    }

    /**
     * @return array<int, array{path: string, mtime: int, size: int}>
     */
    public function listBackups(Server $server, string $path): array
    {
        $this->assertPathAllowed($path);
        $backupDir = self::BACKUP_DIR;
        $slug = $this->backupSlugFor($path);

        $script = sprintf(
            '{ for f in %s/%s.*; do [ -e "$f" ] && stat -c "%%n|%%Y|%%s" "$f"; done; } 2>/dev/null || true',
            escapeshellarg($backupDir),
            $this->bashGlobLiteral($slug),
        );
        $output = $this->runScript($server, 'server-config:list-backups', $script, 15);
        $lines = preg_split('/\R+/', trim($output)) ?: [];

        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            [$p, $mtime, $size] = array_pad(explode('|', $line, 3), 3, '');
            if (! str_starts_with($p, $backupDir.'/')) {
                continue;
            }
            $rows[] = ['path' => $p, 'mtime' => (int) $mtime, 'size' => (int) $size];
        }
        usort($rows, fn ($a, $b): int => $b['mtime'] <=> $a['mtime']);

        return $rows;
    }

    /**
     * @return array{validate_output: string, validate_ok: bool}
     */
    public function restoreBackup(Server $server, string $backupPath, string $targetPath, ?ConsoleEmitter $emitter = null): array
    {
        $this->assertPathAllowed($targetPath);
        if (! str_starts_with($backupPath, self::BACKUP_DIR.'/')) {
            throw new \InvalidArgumentException('Backup path is outside the config backup directory.');
        }

        $emitter?->step('config', 'Reading revision '.$backupPath);
        $contents = $this->raw($server, $backupPath);

        return $this->write($server, $targetPath, $contents, $emitter);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validationHookFor(string $path): ?array
    {
        $hooks = (array) config('server_manage.config_validation_hooks', []);
        $exact = $hooks['exact'] ?? [];
        if (is_array($exact) && isset($exact[$path]) && is_array($exact[$path])) {
            return $exact[$path];
        }

        $prefixes = $hooks['prefixes'] ?? [];
        if (! is_array($prefixes)) {
            return null;
        }

        $best = null;
        $bestLen = -1;
        foreach ($prefixes as $prefix => $hook) {
            if (! is_string($prefix) || ! is_array($hook)) {
                continue;
            }
            if (str_starts_with($path, $prefix) && strlen($prefix) > $bestLen) {
                $best = $hook;
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }

    private function raw(Server $server, string $absPath): string
    {
        $script = sprintf('cat %s 2>/dev/null || true', escapeshellarg($absPath));

        return $this->runScript($server, 'server-config:raw', $script, 30);
    }

    private function assertPathAllowed(string $path): void
    {
        if (! $this->pathIsAllowed($path)) {
            throw new \InvalidArgumentException("Path not allowed: {$path}");
        }
    }

    private function pathIsAllowed(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '/../') || str_ends_with($path, '/..')) {
            return false;
        }
        if ($path[0] !== '/') {
            return false;
        }
        $exact = (array) config('server_manage.allowed_config_paths_exact', []);
        if (in_array($path, $exact, true)) {
            return true;
        }
        foreach ((array) config('server_manage.allowed_config_path_prefixes', []) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function backupSlugFor(string $path): string
    {
        $hash = substr(hash('sha1', $path), 0, 8);

        return preg_replace('/[^A-Za-z0-9._-]/', '_', basename($path)).'.'.$hash;
    }

    /**
     * @param  array<string, mixed>  $hook
     */
    private function validateOutputLooksOk(string $output, array $hook): bool
    {
        $lower = strtolower($output);

        $mustContain = $hook['success_contains'] ?? [];
        if (is_array($mustContain)) {
            foreach ($mustContain as $needle) {
                if (is_string($needle) && $needle !== '' && ! str_contains($lower, strtolower($needle))) {
                    return false;
                }
            }
        }

        $mustNotContain = $hook['failure_contains'] ?? ['error'];
        if (is_array($mustNotContain)) {
            foreach ($mustNotContain as $needle) {
                if (is_string($needle) && $needle !== '' && str_contains($lower, strtolower($needle))) {
                    return false;
                }
            }
        }

        return true;
    }

    private function bashArg(string $s): string
    {
        return escapeshellarg($s);
    }

    private function bashLiteral(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $s);
    }

    private function bashGlobLiteral(string $s): string
    {
        return $this->bashLiteral($s);
    }

    private function runScript(Server $server, string $task, string $script, int $timeout, ?ConsoleEmitter $emitter = null): string
    {
        $onOutput = $emitter === null
            ? function (string $type, string $buffer): void {}
        : function (string $type, string $buffer) use ($emitter): void {
            foreach (preg_split('/\R/', rtrim($buffer, "\n")) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $emitter($line);
                }
            }
        };

        $out = $this->executor->runInlineBash($server, $task, $script, $timeout, $onOutput);

        return ServerManageSshExecutor::stripSshClientNoise($out->getBuffer());
    }
}
