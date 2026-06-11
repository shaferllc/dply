<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Live "quick download": stage a fresh artifact (DB dump, files tar, .env,
 * vhost, logs, full home dir, or a combined bundle) into a temp file on the
 * server, stat it so we can enforce the size cap BEFORE a single byte is sent,
 * then stream it straight to the browser over SSH and clean the temp file up.
 *
 * Nothing is persisted to S3 or the control-plane disk — the bytes are proxied
 * through one held-open HTTP connection. The two-phase (build -> stat -> stream)
 * shape mirrors {@see \App\Http\Controllers\Sites\SiteFileDownloadController}:
 * the stat lets us return a clean 413 instead of streaming a truncated payload.
 */
final class QuickDownloadStreamer
{
    /** Marker the remote build scripts echo on a handled failure. */
    private const FAIL_MARKER = 'DPLY_QD_FAIL';

    /** Site artifacts that {@see prepareSiteArtifact()} understands. */
    public const SITE_ARTIFACTS = ['files', 'env', 'vhost', 'logs', 'home', 'bundle'];

    public function __construct(
        private readonly ServerSshConnectionRunner $runner,
    ) {}

    public function maxBytes(): int
    {
        return (int) config('quick_download.max_bytes', 262_144_000);
    }

    private function timeout(): int
    {
        return (int) config('quick_download.timeout_seconds', 1800);
    }

    /**
     * Build a SQL/archive dump of a single database into a server temp file.
     *
     * Reuses the application database credentials stored on the model (the same
     * ones {@see DatabaseBackupExporter} passes to mysqldump/pg_dump), so the
     * dump never needs root — only SSH access to the box.
     */
    public function prepareDatabaseDump(ServerDatabase $db): PreparedQuickDownload
    {
        $server = $db->server;
        if (! $server instanceof Server) {
            throw new \RuntimeException(__('Database is not attached to a server.'));
        }

        $engine = (string) $db->engine;
        $ulid = (string) Str::ulid();

        [$tmp, $command, $ext] = match ($engine) {
            'mysql', 'mariadb' => [
                '/tmp/dply-qd-'.$ulid.'.sql',
                'env MYSQL_PWD='.escapeshellarg((string) $db->password).' mysqldump -u '.escapeshellarg((string) $db->username)
                    .' --single-transaction --quick --routines=false '.escapeshellarg((string) $db->name).' > "$TMP"',
                'sql',
            ],
            'postgres' => [
                '/tmp/dply-qd-'.$ulid.'.sql',
                'env PGPASSWORD='.escapeshellarg((string) $db->password).' pg_dump -h 127.0.0.1 -U '.escapeshellarg((string) $db->username)
                    .' '.escapeshellarg((string) $db->name).' > "$TMP"',
                'sql',
            ],
            'sqlite' => [
                '/tmp/dply-qd-'.$ulid.'.db',
                'sqlite3 '.escapeshellarg((string) $db->host).' ".backup \"$TMP\""',
                'db',
            ],
            'mongodb' => [
                '/tmp/dply-qd-'.$ulid.'.archive.gz',
                'mongodump --db '.escapeshellarg((string) $db->name).' --username '.escapeshellarg((string) $db->username)
                    .' --password '.escapeshellarg((string) $db->password).' --authenticationDatabase '.escapeshellarg((string) $db->name)
                    .' --archive="$TMP" --gzip',
                'archive.gz',
            ],
            default => throw new \RuntimeException(__('Quick download is not supported for :engine databases.', ['engine' => $engine])),
        };

        $script = $this->buildToTempScript($tmp, $command);

        $bytes = $this->runBuild(
            $server,
            $script,
            useRoot: (bool) config('server_database.use_root_ssh', true),
            fallbackToDeploy: (bool) config('server_database.fallback_to_deploy_user_ssh', true),
        );

        $filename = ($db->name !== '' ? $db->name : 'database').'-'.now()->format('Ymd-His').'.'.$ext;

        return new PreparedQuickDownload(
            server: $server,
            remotePath: $tmp,
            bytes: $bytes,
            filename: $filename,
            mime: $engine === 'mongodb' ? 'application/gzip' : 'application/sql',
            useRoot: (bool) config('server_database.use_root_ssh', true),
            cleanup: true,
        );
    }

    /**
     * Build one of the per-site artifacts into a server temp file (or, for
     * single small files like .env / the vhost, point straight at the file).
     */
    public function prepareSiteArtifact(Site $site, string $artifact): PreparedQuickDownload
    {
        $server = $site->server;
        if (! $server instanceof Server) {
            throw new \RuntimeException(__('Site is not attached to a server.'));
        }

        if (! in_array($artifact, self::SITE_ARTIFACTS, true)) {
            throw new \RuntimeException(__('Unknown quick-download artifact.'));
        }

        if (in_array($artifact, ['files', 'home', 'logs', 'bundle'], true) && ! $site->supportsSshFileArchive()) {
            throw new \RuntimeException(__('File archives are only available for SSH-ready VM sites.'));
        }

        $slug = $site->slug !== null && $site->slug !== '' ? $site->slug : 'site';
        $stamp = now()->format('Ymd-His');

        return match ($artifact) {
            'env' => $this->prepareExistingFile(
                $server,
                $site->effectiveEnvFilePath(),
                useRoot: false,
                filename: $slug.'.env',
                mime: 'text/plain',
            ),
            'vhost' => $this->prepareVhost($site, $server, $slug),
            'files' => $this->prepareSiteFilesArchive($site, $server, $slug.'-files-'.$stamp.'.tar.gz', withExcludes: true),
            'home' => $this->prepareSiteHomeArchive($site, $server, $slug.'-home-'.$stamp.'.tar.gz'),
            'logs' => $this->prepareSiteLogsArchive($site, $server, $slug.'-logs-'.$stamp.'.tar.gz'),
            'bundle' => $this->prepareSiteBundle($site, $server, $slug.'-bundle-'.$stamp.'.tar.gz'),
            default => throw new \RuntimeException(__('Unknown quick-download artifact.')),
        };
    }

    /**
     * Stream a prepared artifact to the browser, then delete its temp file.
     * Called from inside a StreamedResponse callback.
     */
    public function stream(PreparedQuickDownload $prepared): void
    {
        $inner = 'cat -- '.escapeshellarg($prepared->remotePath);
        if ($prepared->cleanup) {
            $inner .= '; rm -f -- '.escapeshellarg($prepared->remotePath);
        }
        $command = 'bash -lc '.escapeshellarg($inner);

        $this->runner->run(
            $prepared->server,
            function ($ssh) use ($command): void {
                $ssh->execWithCallback($command, function (string $chunk): void {
                    echo $chunk;
                    @ob_flush();
                    @flush();
                }, $this->timeout());
            },
            $prepared->useRoot,
            true,
        );
    }

    // --- artifact builders --------------------------------------------------

    private function prepareSiteFilesArchive(Site $site, Server $server, string $filename, bool $withExcludes): PreparedQuickDownload
    {
        $excludeFlags = '';
        if ($withExcludes) {
            foreach ((array) config('site_file_backup.tar_excludes', []) as $pattern) {
                if (is_string($pattern) && $pattern !== '') {
                    $excludeFlags .= ' --exclude='.escapeshellarg($pattern);
                }
            }
        }

        $tmp = '/tmp/dply-qd-'.Str::ulid().'.tar.gz';
        $command = 'ROOT="$(readlink -f -- '.escapeshellarg($site->effectiveRepositoryPath()).')"; '
            .'[ -d "$ROOT" ] || { echo '.self::FAIL_MARKER.'; echo "site directory not found"; exit 1; }; '
            .'cd "$ROOT" && tar -czf "$TMP"'.$excludeFlags.' .';

        $bytes = $this->runBuild($server, $this->buildToTempScript($tmp, $command, tolerateTarWarnings: true), useRoot: false);

        return new PreparedQuickDownload($server, $tmp, $bytes, $filename, 'application/gzip', useRoot: false, cleanup: true);
    }

    private function prepareSiteHomeArchive(Site $site, Server $server, string $filename): PreparedQuickDownload
    {
        $tmp = '/tmp/dply-qd-'.Str::ulid().'.tar.gz';
        $command = 'ROOT="$(readlink -f -- '.escapeshellarg($site->effectiveRepositoryPath()).')"; '
            .'[ -d "$ROOT" ] || { echo '.self::FAIL_MARKER.'; echo "site directory not found"; exit 1; }; '
            .'DIR="$(dirname "$ROOT")"; BASE="$(basename "$ROOT")"; '
            .'tar -czf "$TMP" -C "$DIR" "$BASE"';

        $bytes = $this->runBuild($server, $this->buildToTempScript($tmp, $command, tolerateTarWarnings: true), useRoot: false);

        return new PreparedQuickDownload($server, $tmp, $bytes, $filename, 'application/gzip', useRoot: false, cleanup: true);
    }

    private function prepareSiteLogsArchive(Site $site, Server $server, string $filename): PreparedQuickDownload
    {
        $tmp = '/tmp/dply-qd-'.Str::ulid().'.tar.gz';
        $command = 'ROOT="$(readlink -f -- '.escapeshellarg($site->effectiveRepositoryPath()).')"; '
            .'[ -d "$ROOT" ] || { echo '.self::FAIL_MARKER.'; echo "site directory not found"; exit 1; }; '
            .'cd "$ROOT"; '
            .'if [ -d storage/logs ]; then tar -czf "$TMP" storage/logs; else tar -czf "$TMP" -T /dev/null; fi';

        $bytes = $this->runBuild($server, $this->buildToTempScript($tmp, $command, tolerateTarWarnings: true), useRoot: false);

        return new PreparedQuickDownload($server, $tmp, $bytes, $filename, 'application/gzip', useRoot: false, cleanup: true);
    }

    /**
     * Combined site bundle: the repository tree (full home dir, no excludes) plus
     * the deployed .env and a SQL dump of every linked database, all staged into a
     * scratch dir and tarred together. Heaviest artifact — most likely to trip the cap.
     */
    private function prepareSiteBundle(Site $site, Server $server, string $filename): PreparedQuickDownload
    {
        $tmp = '/tmp/dply-qd-'.Str::ulid().'.tar.gz';

        $lines = [
            'ROOT="$(readlink -f -- '.escapeshellarg($site->effectiveRepositoryPath()).')"',
            '[ -d "$ROOT" ] || { echo '.self::FAIL_MARKER.'; echo "site directory not found"; exit 1; }',
            'STAGE="$(mktemp -d)"',
            'trap \'rm -rf "$STAGE"\' EXIT',
            '[ -f '.escapeshellarg($site->effectiveEnvFilePath()).' ] && cp '.escapeshellarg($site->effectiveEnvFilePath()).' "$STAGE/.env" || true',
        ];

        foreach ($site->serverDatabases as $db) {
            $line = $this->bundleDumpLine($db);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        $lines[] = 'DIR="$(dirname "$ROOT")"; BASE="$(basename "$ROOT")"';
        $lines[] = 'tar -czf "$TMP" -C "$DIR" "$BASE" -C "$STAGE" .';

        $command = implode('; ', $lines);

        $bytes = $this->runBuild($server, $this->buildToTempScript($tmp, $command, tolerateTarWarnings: true), useRoot: false);

        return new PreparedQuickDownload($server, $tmp, $bytes, $filename, 'application/gzip', useRoot: false, cleanup: true);
    }

    private function bundleDumpLine(ServerDatabase $db): ?string
    {
        $dest = '"$STAGE/'.preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $db->name).'.sql"';

        return match ((string) $db->engine) {
            'mysql', 'mariadb' => 'env MYSQL_PWD='.escapeshellarg((string) $db->password).' mysqldump -u '.escapeshellarg((string) $db->username)
                .' --single-transaction --quick --routines=false '.escapeshellarg((string) $db->name).' > '.$dest.' 2>/dev/null || true',
            'postgres' => 'env PGPASSWORD='.escapeshellarg((string) $db->password).' pg_dump -h 127.0.0.1 -U '.escapeshellarg((string) $db->username)
                .' '.escapeshellarg((string) $db->name).' > '.$dest.' 2>/dev/null || true',
            default => null,
        };
    }

    private function prepareVhost(Site $site, Server $server, string $slug): PreparedQuickDownload
    {
        $basename = $site->webserverConfigBasename();
        $candidates = [
            '/etc/nginx/sites-available/'.$basename,
            '/etc/nginx/sites-enabled/'.$basename,
            '/etc/apache2/sites-available/'.$basename.'.conf',
            '/etc/apache2/sites-enabled/'.$basename.'.conf',
            '/etc/caddy/Caddyfile',
        ];

        $loop = '';
        foreach ($candidates as $candidate) {
            $loop .= 'if [ -e '.escapeshellarg($candidate).' ]; then echo '.escapeshellarg($candidate).'; stat -c%s '.escapeshellarg($candidate).'; exit 0; fi; ';
        }
        $loop .= 'echo '.self::FAIL_MARKER.'; echo "no vhost file found"; exit 1';

        [$output, $exit] = $this->runner->run(
            $server,
            fn ($ssh): array => [$ssh->exec('bash -lc '.escapeshellarg($loop), 60), $ssh->lastExecExitCode()],
            true,
            true,
        );

        $this->assertOk($output, $exit);

        $rows = array_values(array_filter(array_map('trim', explode("\n", trim($output))), fn ($l) => $l !== ''));
        $path = $rows[0] ?? '';
        $bytes = (int) ($rows[1] ?? 0);
        if ($path === '' || $path[0] !== '/') {
            throw new \RuntimeException(__('No webserver config file was found for this site.'));
        }

        $this->assertWithinCap($bytes);

        return new PreparedQuickDownload($server, $path, $bytes, $slug.'-vhost.conf', 'text/plain', useRoot: true, cleanup: false);
    }

    /**
     * Point straight at an existing single file on the server (no staging):
     * stat it for the cap, then stream it as-is.
     */
    private function prepareExistingFile(Server $server, string $path, bool $useRoot, string $filename, string $mime): PreparedQuickDownload
    {
        if ($path === '' || $path[0] !== '/') {
            throw new \RuntimeException(__('Could not resolve the file path on the server.'));
        }

        $script = '[ -f '.escapeshellarg($path).' ] || { echo '.self::FAIL_MARKER.'; echo "file not found"; exit 1; }; stat -c%s '.escapeshellarg($path);

        [$output, $exit] = $this->runner->run(
            $server,
            fn ($ssh): array => [$ssh->exec('bash -lc '.escapeshellarg($script), 60), $ssh->lastExecExitCode()],
            $useRoot,
            true,
        );

        $this->assertOk($output, $exit);
        $bytes = (int) trim($this->lastLine($output));
        $this->assertWithinCap($bytes);

        return new PreparedQuickDownload($server, $path, $bytes, $filename, $mime, $useRoot, cleanup: false);
    }

    // --- remote helpers -----------------------------------------------------

    /**
     * Wrap a build command with a temp-path assignment + uniform failure handling,
     * ending in `stat -c%s` so the run returns the produced file's byte size.
     */
    private function buildToTempScript(string $tmp, string $command, bool $tolerateTarWarnings = false): string
    {
        // tar exits 1 on benign "file changed as we read it" warnings; only >=2 is fatal.
        $rcGuard = $tolerateTarWarnings ? '[ $rc -ge 2 ]' : '[ $rc -ne 0 ]';

        return implode("\n", [
            'TMP='.escapeshellarg($tmp),
            '{ '.$command.' ; } 2>"$TMP.err"',
            'rc=$?',
            'if '.$rcGuard.'; then echo '.self::FAIL_MARKER.'; sed -n "1,20p" "$TMP.err" 2>/dev/null; rm -f "$TMP" "$TMP.err"; exit 1; fi',
            'rm -f "$TMP.err"',
            'stat -c%s "$TMP"',
        ]);
    }

    /**
     * Run a build script, validate it, enforce the cap, and return the byte size.
     */
    private function runBuild(Server $server, string $script, bool $useRoot, bool $fallbackToDeploy = true): int
    {
        [$output, $exit] = $this->runner->run(
            $server,
            fn ($ssh): array => [$ssh->exec('bash -lc '.escapeshellarg($script), $this->timeout()), $ssh->lastExecExitCode()],
            $useRoot,
            $fallbackToDeploy,
        );

        $this->assertOk($output, $exit);

        $bytes = (int) trim($this->lastLine($output));
        if ($bytes <= 0) {
            throw new \RuntimeException(__('The export produced an empty file.'));
        }

        $this->assertWithinCap($bytes);

        return $bytes;
    }

    private function assertOk(string $output, ?int $exit): void
    {
        if (str_contains($output, self::FAIL_MARKER) || ($exit !== null && $exit !== 0)) {
            $message = trim(str_replace(self::FAIL_MARKER, '', $output));

            throw new \RuntimeException(Str::limit($message !== '' ? $message : __('The export failed on the server.'), 400));
        }
    }

    private function assertWithinCap(int $bytes): void
    {
        $cap = $this->maxBytes();
        if ($bytes > $cap) {
            throw new QuickDownloadTooLargeException($bytes, $cap);
        }
    }

    private function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($output))), fn ($l) => $l !== ''));

        return $lines === [] ? '' : (string) end($lines);
    }
}
