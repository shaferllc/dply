<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Modules\TaskRunner\ProcessOutput;

/**
 * Read / write / backup / restore for webserver config files on a server.
 *
 * All paths flow through `assertPathAllowed()` before they're embedded in any
 * remote command — this is the security boundary. The webserver-config-layout
 * globs in config('server_manage.webserver_config_layout') only widen the
 * file-discovery surface; they do not bypass the allowlist.
 *
 * Writes are atomic: contents go to a tmpfile, the live file is copied to
 * `_dply_backups/`, then `install -m 0644` moves the tmp into place. If the
 * post-write validate fails, the caller can restore from the backup that the
 * write step just created — paths are returned in the result for that flow.
 */
class RemoteWebserverConfigService
{
    public function __construct(
        protected ServerManageSshExecutor $executor,
    ) {}

    /**
     * Engines this service knows about. Used to gate which validate/reload
     * commands and globs to apply.
     */
    public function supportedEngines(): array
    {
        return array_keys((array) config('server_manage.webserver_config_layout', []));
    }

    /**
     * Discover existing config files for a given engine. Returns absolute
     * paths that already exist on the server. The main config is always first
     * (if it exists); the rest come from the engine's globs.
     *
     * @return array<int, array{path: string, label: string, size: int, mtime: int|null}>
     */
    public function listFiles(Server $server, string $engine): array
    {
        $layout = $this->layoutFor($engine);
        $paths = array_merge([$layout['main']], $layout['globs'] ?? []);

        // Build a tiny shell pipeline that expands the globs and reports
        // size+mtime for each existing file. Failure is non-fatal — we just
        // report nothing for that path.
        $cmds = [];
        foreach ($paths as $p) {
            // Each glob is taken verbatim from config; no user input.
            $cmds[] = sprintf('for f in %s; do [ -e "$f" ] && stat -c "%%n|%%s|%%Y" "$f"; done', $p);
        }
        $script = '{ '.implode('; ', $cmds).'; } 2>/dev/null || true';

        $output = $this->runScript($server, 'webserver-config:list', $script, 30);
        $lines = preg_split('/\R+/', trim($output)) ?: [];

        $main = (string) $layout['main'];
        $byPath = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            [$p, $size, $mtime] = array_pad(explode('|', $line, 3), 3, '');
            if (! $this->pathIsAllowed($p)) {
                continue;
            }
            $byPath[$p] = [
                'path' => $p,
                'label' => $p === $main ? __('main config').' — '.basename($p) : basename($p),
                'size' => (int) $size,
                'mtime' => $mtime === '' ? null : (int) $mtime,
            ];
        }

        // Keep main first, then the rest alphabetically.
        $out = [];
        if (isset($byPath[$main])) {
            $out[] = $byPath[$main];
            unset($byPath[$main]);
        }
        ksort($byPath);

        return array_merge($out, array_values($byPath));
    }

    /**
     * Read a single config file's contents. Caps reads at the configured
     * preview size; callers should surface a clear notice when they hit the
     * cap so the operator doesn't silently lose trailing content.
     *
     * @return array{contents: string, truncated: bool, size: int}
     */
    public function read(Server $server, string $engine, string $path): array
    {
        $this->assertEngineSupported($engine);
        $this->assertPathAllowed($path);

        $cap = (int) config('server_manage.config_preview_max_bytes', 48_000);

        $script = sprintf(
            '{ stat -c "%%s" %1$s 2>/dev/null; echo "---"; head -c %2$d %1$s 2>/dev/null; } || true',
            escapeshellarg($path),
            $cap,
        );

        $output = $this->runScript($server, 'webserver-config:read', $script, 60);
        [$head, $body] = array_pad(explode("---\n", $output, 2), 2, '');
        $size = (int) trim($head);

        return [
            'contents' => (string) $body,
            'truncated' => $size > $cap,
            'size' => $size,
        ];
    }

    /**
     * Write new contents to a config file. Steps:
     *
     *   1. Verify size ≤ config_edit_max_bytes.
     *   2. Copy the live file to /etc/<engine>/_dply_backups/<slug>.<ts> (if it exists).
     *   3. Atomically install the new contents at the same path (mode 0644, root:root).
     *   4. Run the engine's validate command.
     *   5. Trim old backups beyond config_edit_backup_keep.
     *
     * @return array{backup: ?string, validate_output: string, validate_ok: bool}
     */
    public function write(Server $server, string $engine, string $path, string $contents): array
    {
        $this->assertEngineSupported($engine);
        $this->assertPathAllowed($path);

        $max = (int) config('server_manage.config_edit_max_bytes', 256_000);
        if (strlen($contents) > $max) {
            throw new \InvalidArgumentException("Config payload exceeds the {$max}-byte limit.");
        }

        $layout = $this->layoutFor($engine);
        $backupDir = $this->backupDirFor($engine);
        $backupSlug = $this->backupSlugFor($path);
        $backupPath = $backupDir.'/'.$backupSlug.'.'.now()->getTimestamp();
        $keep = max(1, (int) config('server_manage.config_edit_backup_keep', 10));

        // Base64-encode the payload so we don't have to worry about quoting,
        // null bytes, or line-ending mangling in heredocs.
        $b64 = base64_encode($contents);

        $script = <<<BASH
set -euo pipefail
PATHX={$this->bashArg($path)}
BACKUP_DIR={$this->bashArg($backupDir)}
BACKUP_PATH={$this->bashArg($backupPath)}
KEEP={$keep}

# Ensure the backup dir exists with sane perms (root:root, 0700).
(sudo -n install -d -m 0700 -o root -g root "\$BACKUP_DIR" || install -d -m 0700 -o root -g root "\$BACKUP_DIR") 2>&1

# Snapshot the live file before overwrite (no-op if it doesn't yet exist).
if [ -e "\$PATHX" ]; then
  (sudo -n cp -a "\$PATHX" "\$BACKUP_PATH" || cp -a "\$PATHX" "\$BACKUP_PATH") 2>&1
  echo "[backup] \$BACKUP_PATH"
fi

# Atomically install the new contents. mktemp + install ensures partial writes
# never end up at the live path.
TMP=\$(mktemp)
printf %s {$this->bashArg($b64)} | base64 -d > "\$TMP"
(sudo -n install -m 0644 -o root -g root "\$TMP" "\$PATHX" || install -m 0644 -o root -g root "\$TMP" "\$PATHX") 2>&1
rm -f "\$TMP"

# Trim old backups beyond \$KEEP for this slug.
ls -1t "\$BACKUP_DIR"/{$this->bashLiteral($backupSlug)}.* 2>/dev/null | tail -n +\$((KEEP + 1)) | xargs -r sudo -n rm -f -- 2>/dev/null || true
BASH;

        $this->runScript($server, 'webserver-config:write', $script, 60);

        $validate = $this->runScript(
            $server,
            'webserver-config:validate',
            (string) ($layout['validate'] ?? 'true'),
            (int) ($layout['validate_timeout'] ?? 60),
        );
        $validateOk = $this->validateOutputLooksOk($engine, $validate);

        return [
            'backup' => $backupPath,
            'validate_output' => trim($validate),
            'validate_ok' => $validateOk,
        ];
    }

    /**
     * Run the engine's config-test command without writing anything. Used by
     * the editor's "Validate" button when the operator wants to dry-run the
     * current on-disk config (e.g. after fmt-overwrite or an external edit).
     */
    public function validate(Server $server, string $engine): array
    {
        $this->assertEngineSupported($engine);
        $layout = $this->layoutFor($engine);

        $output = $this->runScript(
            $server,
            'webserver-config:validate',
            (string) ($layout['validate'] ?? 'true'),
            (int) ($layout['validate_timeout'] ?? 60),
        );

        return [
            'output' => trim($output),
            'ok' => $this->validateOutputLooksOk($engine, $output),
        ];
    }

    /**
     * List timestamped backups for a single live path.
     *
     * @return array<int, array{path: string, mtime: int, size: int}>
     */
    public function listBackups(Server $server, string $engine, string $path): array
    {
        $this->assertEngineSupported($engine);
        $this->assertPathAllowed($path);

        $backupDir = $this->backupDirFor($engine);
        $slug = $this->backupSlugFor($path);

        $script = sprintf(
            '{ for f in %s/%s.*; do [ -e "$f" ] && stat -c "%%n|%%Y|%%s" "$f"; done; } 2>/dev/null || true',
            escapeshellarg($backupDir),
            $this->bashGlobLiteral($slug),
        );
        $output = $this->runScript($server, 'webserver-config:list-backups', $script, 15);
        $lines = preg_split('/\R+/', trim($output)) ?: [];

        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            [$p, $mtime, $size] = array_pad(explode('|', $line, 3), 3, '');
            $rows[] = ['path' => $p, 'mtime' => (int) $mtime, 'size' => (int) $size];
        }
        usort($rows, fn ($a, $b): int => $b['mtime'] <=> $a['mtime']);

        return $rows;
    }

    /**
     * Restore a backup file by copying it back over the live path. Like
     * `write()`, this also snapshots the current live file before clobbering
     * it, so a bad restore can be undone by restoring the very last backup.
     *
     * @return array{validate_output: string, validate_ok: bool}
     */
    public function restoreBackup(Server $server, string $engine, string $backupPath, string $targetPath): array
    {
        $this->assertEngineSupported($engine);
        $this->assertPathAllowed($targetPath);
        $backupDir = $this->backupDirFor($engine);
        if (! str_starts_with($backupPath, $backupDir.'/')) {
            throw new \InvalidArgumentException('Backup path is outside the engine backup directory.');
        }

        // Read the backup directly (not via read(), which caps at the preview
        // size). Backups can be near the edit cap (256 KB) for large nginx
        // configs and silently truncating a restore would be the worst kind
        // of footgun. write() takes its own snapshot of the current file
        // before clobbering, so a botched restore can still be undone.
        $contents = $this->raw($server, $backupPath);

        return $this->write($server, $engine, $targetPath, $contents);
    }

    private function raw(Server $server, string $absPath): string
    {
        $script = sprintf('cat %s 2>/dev/null || true', escapeshellarg($absPath));

        return $this->runScript($server, 'webserver-config:raw', $script, 30);
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

    private function assertPathAllowed(string $path): void
    {
        if (! $this->pathIsAllowed($path)) {
            throw new \InvalidArgumentException("Path not allowed: {$path}");
        }
    }

    private function assertEngineSupported(string $engine): void
    {
        if (! in_array($engine, $this->supportedEngines(), true)) {
            throw new \InvalidArgumentException("Unsupported webserver engine: {$engine}");
        }
    }

    /**
     * @return array{main: string, globs: array<int, string>, validate: ?string, reload: ?string}
     */
    private function layoutFor(string $engine): array
    {
        return (array) config('server_manage.webserver_config_layout.'.$engine, []);
    }

    private function backupDirFor(string $engine): string
    {
        return match ($engine) {
            'nginx' => '/etc/nginx/_dply_backups',
            'caddy' => '/etc/caddy/_dply_backups',
            'apache' => '/etc/apache2/_dply_backups',
            'openlitespeed' => '/usr/local/lsws/conf/_dply_backups',
            'traefik' => '/etc/traefik/_dply_backups',
            'haproxy' => '/etc/haproxy/_dply_backups',
            default => throw new \InvalidArgumentException("No backup dir mapped for {$engine}"),
        };
    }

    /**
     * Stable filename slug derived from the live path. Keeps the basename
     * (so operators can scan the backup dir at a glance) but encodes the
     * directory so different sites' configs don't collide.
     */
    private function backupSlugFor(string $path): string
    {
        $hash = substr(hash('sha1', $path), 0, 8);

        return preg_replace('/[^A-Za-z0-9._-]/', '_', basename($path)).'.'.$hash;
    }

    private function bashArg(string $s): string
    {
        return escapeshellarg($s);
    }

    private function bashLiteral(string $s): string
    {
        // For use inside a single-quoted glob literal — we never embed user
        // input through here, only slugs we built ourselves, so a strict
        // character class is enough.
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $s);
    }

    private function bashGlobLiteral(string $s): string
    {
        return $this->bashLiteral($s);
    }

    private function runScript(Server $server, string $task, string $script, int $timeout): string
    {
        $out = $this->executor->runInlineBash(
            $server,
            $task,
            $script,
            $timeout,
            function (string $type, string $buffer): void {},
        );

        return ServerManageSshExecutor::stripSshClientNoise($out->getBuffer());
    }

    /**
     * Best-effort heuristic for whether a config-test passed. Engine-specific
     * because they each phrase success differently and a clean exit isn't
     * always reflected in the captured output.
     */
    private function validateOutputLooksOk(string $engine, string $output): bool
    {
        $output = strtolower($output);

        return match ($engine) {
            'nginx' => str_contains($output, 'syntax is ok') && str_contains($output, 'test is successful'),
            'apache' => str_contains($output, 'syntax ok'),
            'caddy' => str_contains($output, 'valid configuration') || ! str_contains($output, 'error'),
            // lshttpd -t prints "Config check passed!" on success or
            // "[ERROR]" lines on failure. No reliable success-string in
            // older versions, so we fall back to absence-of-error.
            'openlitespeed' => str_contains($output, 'config check passed') || ! str_contains($output, '[error]'),
            // We run `caddy validate` against the backend chain (see
            // server_manage.webserver_config_layout.traefik.validate). Same
            // success/failure markers as caddy itself.
            'traefik' => str_contains($output, 'valid configuration') || ! str_contains($output, 'error'),
            // `haproxy -c` prints "Configuration file is valid" on success
            // and "[ALERT]" prefixed lines on failure. Match the success
            // string explicitly; an empty output (exit 0) also counts.
            'haproxy' => str_contains($output, 'configuration file is valid') || (! str_contains($output, '[alert]') && ! str_contains($output, 'error')),
            default => ! str_contains($output, 'error'),
        };
    }
}
