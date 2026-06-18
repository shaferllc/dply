<?php

namespace App\Services\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Services\SshConnection;
use Illuminate\Support\Collection;

/**
 * Discovers .htpasswd files lying around inside a site's repository on the
 * server and returns the user entries they contain. Used by the basic-auth
 * tab's "Sync from server" action so the operator can pull leftover gates
 * (whether Dply once wrote them and didn't clean up, or they were placed by
 * hand before Dply existed) back into the database, then remove them via
 * the normal flow.
 *
 * No DB writes happen here — the caller (Settings.php livewire) decides how
 * to reconcile the discovered rows with existing SiteBasicAuthUser records.
 */
class SiteBasicAuthDiscovery
{
    /**
     * Standard URL paths we'll fingerprint when trying to recover a path from
     * a `group-<hash>.htpasswd` filename. Anything else falls back to '/'.
     */
    private const COMMON_PATH_GUESSES = [
        '/',
        '/admin',
        '/wp-admin',
        '/wp-login.php',
        '/dashboard',
        '/staging',
        '/preview',
    ];

    /**
     * @return Collection<int, array{
     *     username: string,
     *     password_hash: string,
     *     path: string,
     *     source_file_path: string|null,
     *     discovered_file_path: string,
     * }>
     */
    public function discover(Site $site): Collection
    {
        $server = $site->server;
        if ($server === null) {
            return collect();
        }

        $repo = rtrim($site->effectiveRepositoryPath(), '/');
        if ($repo === '' || $repo === '/') {
            return collect();
        }

        $ssh = $this->systemSsh($site);
        $managedDir = rtrim($site->basicAuthStorageDirectoryOnHost(), '/');

        $files = $this->listHtpasswdFiles($server, $ssh, $repo);
        if ($files === []) {
            return collect();
        }

        $hashToPath = $this->buildHashToPathMap($site);
        $rows = collect();

        foreach ($files as $absolutePath) {
            $contents = $this->readRemote($server, $ssh, $absolutePath);
            if ($contents === null) {
                continue;
            }

            $isManaged = $managedDir !== '' && str_starts_with($absolutePath, $managedDir.'/');
            $urlPath = $this->resolveUrlPath($absolutePath, $isManaged, $hashToPath);

            foreach ($this->parseHtpasswd($contents) as [$username, $hash]) {
                $rows->push([
                    'username' => $username,
                    'password_hash' => $hash,
                    'path' => $urlPath,
                    // Managed group files have a deterministic path Dply already
                    // owns — we can re-derive it from the URL path on rewrite,
                    // so leave source_file_path null and treat the row as a
                    // normal Dply-managed credential going forward.
                    'source_file_path' => $isManaged ? null : $absolutePath,
                    'discovered_file_path' => $absolutePath,
                ]);
            }
        }

        return $rows;
    }

    /**
     * Returns absolute paths of all `.htpasswd` / `*.htpasswd` files inside the
     * site repo. Restricted to the repo so we never wander into other sites'
     * directories on shared hosts.
     *
     * @return list<string>
     */
    private function listHtpasswdFiles(Server $server, SshConnection $ssh, string $repoPath): array
    {
        // -L follows symlinks (some apps store .htpasswd via a symlink to a
        // shared config dir); -type f keeps directories named ".htpasswd" out;
        // 2>/dev/null swallows EACCES on subdirs the SSH user can't read so a
        // partially permissioned repo still returns the readable subset. The
        // -prune list skips dependency trees that won't contain a real auth
        // file but could each have thousands of nodes — e.g. a Laravel repo's
        // vendor/ alone often pushes find into seconds-of-walltime territory.
        $cmd = sprintf(
            "find -L %s \\( -type d \\( -name node_modules -o -name vendor -o -name .git -o -name storage \\) -prune \\) -o \\( \\( -name '.htpasswd' -o -name '*.htpasswd' \\) -type f -print \\) 2>/dev/null",
            escapeshellarg($repoPath)
        );

        $out = $ssh->exec($this->privilegedCommand($server, $cmd), 30);
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $out)) ?: [];

        $paths = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '/')) {
                continue;
            }
            $paths[$line] = true;
        }

        return array_keys($paths);
    }

    private function readRemote(Server $server, SshConnection $ssh, string $path): ?string
    {
        $out = $ssh->exec(
            $this->privilegedCommand($server, 'cat '.escapeshellarg($path).' 2>/dev/null || true'),
            30
        );
        $out = (string) $out;

        return $out === '' ? null : $out;
    }

    /**
     * Returns [username, hash] pairs from a htpasswd file body. Skips blank
     * lines, comments, and lines that don't look like `user:hash`.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function parseHtpasswd(string $contents): array
    {
        $entries = [];
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }

            $username = trim(substr($line, 0, $colon));
            $hash = trim(substr($line, $colon + 1));

            if ($username === '' || $hash === '') {
                continue;
            }

            $entries[] = [$username, $hash];
        }

        return $entries;
    }

    /**
     * Computes the hash Dply uses for {@see Site::basicAuthHtpasswdPathForNormalizedPath()}
     * so we can reverse a `group-<hash>.htpasswd` filename back to a URL path.
     *
     * @return array<string, string> hash16 => url path
     */
    private function buildHashToPathMap(Site $site): array
    {
        $paths = collect(self::COMMON_PATH_GUESSES);

        // Include any URL paths Dply already tracks on this site so a previously
        // configured custom prefix (e.g. /staff) gets matched even though it
        // isn't in COMMON_PATH_GUESSES.
        $existing = $site->basicAuthUsers()
            ->whereNull('source_file_path')
            ->pluck('path')
            ->filter(fn ($p): bool => is_string($p) && $p !== '');
        $paths = $paths->merge($existing)->unique();

        $map = [];
        foreach ($paths as $p) {
            $normalized = SiteBasicAuthUser::normalizePath($p);
            $hash = substr(hash('sha256', $normalized), 0, 16);
            $map[$hash] = $normalized;
        }

        return $map;
    }

    /**
     * Best-effort URL path for a discovered file: hash-match for managed group
     * files, otherwise '/'. Operators can edit the URL path later if a
     * non-Dply file is meant for a more specific prefix.
     *
     * @param  array<string, mixed> $hashToPath
     */
    private function resolveUrlPath(string $absolutePath, bool $isManaged, array $hashToPath): string
    {
        if (! $isManaged) {
            return '/';
        }

        $base = basename($absolutePath);
        if (preg_match('/^group-([0-9a-f]{16})\.htpasswd$/', $base, $m) === 1) {
            $hash = $m[1];
            if (isset($hashToPath[$hash])) {
                return $hashToPath[$hash];
            }
        }

        return '/';
    }

    private function systemSsh(Site $site): SshConnection
    {
        $server = $site->server;

        if ($server !== null && $server->recoverySshPrivateKey()) {
            $root = new SshConnection($server, 'root', SshConnection::ROLE_RECOVERY);
            if ($root->connect()) {
                return $root;
            }
        }

        return new SshConnection($server);
    }

    private function privilegedCommand(Server $server, string $command): string
    {
        $user = trim((string) $server->ssh_user);

        if ($user === '' || $user === 'root') {
            return $command;
        }

        return 'sudo -n bash -lc '.escapeshellarg($command);
    }
}
