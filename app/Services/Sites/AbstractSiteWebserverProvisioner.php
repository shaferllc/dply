<?php

namespace App\Services\Sites;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\Contracts\SiteWebserverProvisioner;
use App\Services\SshConnection;
use App\Services\SshConnectionFactory;
use App\Support\Sites\SiteManagedErrorPageSupport;
use Illuminate\Support\Str;

abstract class AbstractSiteWebserverProvisioner implements SiteWebserverProvisioner
{
    public function __construct(
        protected ?SitePlaceholderPageBuilder $placeholderPageBuilder = null,
    ) {}

    protected function ensureServerReady(Site $site): Server
    {
        $server = $site->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        return $server;
    }

    protected function systemSsh(Site $site): SshConnection
    {
        $server = $site->server;
        $factory = app(SshConnectionFactory::class);

        if ($server->recoverySshPrivateKey()) {
            $root = $factory->recoveryForServer($server);
            if ($root->connect()) {
                return $root;
            }
        }

        return $factory->forServer($server);
    }

    protected function writeSystemFile(SshConnection $ssh, string $remotePath, string $contents): void
    {
        if ($ssh->effectiveUsername() === 'root') {
            $ssh->putFile($remotePath, $contents);

            return;
        }

        $tmpFile = '/tmp/'.basename($remotePath).'.'.Str::random(8);
        $ssh->putFile($tmpFile, $contents);
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_FILE_EXIT:%%s" "$?"',
            sprintf(
                'sudo -n mkdir -p %1$s && sudo -n mv %2$s %3$s && sudo -n chown root:root %3$s && sudo -n chmod 644 %3$s',
                escapeshellarg(dirname($remotePath)),
                escapeshellarg($tmpFile),
                escapeshellarg($remotePath)
            )
        ), 60);

        if (! preg_match('/DPLY_FILE_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Dply needs root SSH access or passwordless sudo to write '.$remotePath.'. Output: '.Str::limit($out, 1000));
        }
    }

    /**
     * Reads the remote file's current contents and only writes when the desired
     * payload differs. Returns true when a write happened, false when the file
     * was already in the desired state. Used by the steady-state idempotent
     * helpers (engine HTTP cache, layer snippets, htpasswd, main vhost) so an
     * apply triggered by a tiny change (one credential rotation, etc.) doesn't
     * spam the banner with rewrites that produced no actual change.
     */
    protected function writeSystemFileIfChanged(Server $server, SshConnection $ssh, string $remotePath, string $contents): bool
    {
        // readRemoteFile() trims whitespace and returns null for empty; trim
        // the desired side too so a file whose only difference is a trailing
        // newline doesn't trigger a "changed" branch on every apply.
        $current = $this->readRemoteFile($server, $ssh, $remotePath);
        if ($current !== null && trim($current) === trim($contents)) {
            return false;
        }

        $this->writeSystemFile($ssh, $remotePath, $contents);

        return true;
    }

    /**
     * Writes a friendly "site is being set up" page when the doc root has no
     * index file yet (freshly-created site between provision and first deploy).
     * Callers gate this on a first-apply signal (nginx: nginx_installed_at;
     * other webservers: their meta.{webserver}_last_output key) so steady-state
     * applies (basic-auth rotations, SSL renewals, any config rewrite) never
     * even invoke this helper. A site whose doc root is wiped after the first
     * provision relies on the next deploy to repopulate it.
     */
    protected function installPlaceholderPage(Site $site, SshConnection $ssh, ?ConsoleEmitter $emit = null): void
    {
        if (! in_array($site->type, [SiteType::Php, SiteType::Static], true)) {
            return;
        }

        $root = rtrim($site->effectiveDocumentRoot(), '/');
        if ($root === '') {
            return;
        }

        // Inspect first; only mkdir/write when we know the placeholder is needed.
        // The probe checks for any index.* (php, html, htm) so once a placeholder
        // or real deploy has populated the root we never re-enter the write path.
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_INDEX_PLACEHOLDER_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $site->server,
                sprintf(
                    'if ls -A %1$s/index.php %1$s/index.html %1$s/index.htm 2>/dev/null | grep -q .; then echo present; else echo missing; fi',
                    escapeshellarg($root)
                )
            )
        ), 60);

        if (! preg_match('/DPLY_INDEX_PLACEHOLDER_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Unable to inspect the site web root before writing a placeholder page. Output: '.Str::limit($out, 1000));
        }

        // Anything index.* present means either a deploy populated this root or
        // a previous provision wrote our placeholder. Either way: leave it alone.
        if (str_contains($out, 'present')) {
            return;
        }

        // Doc root is empty — make sure the directory exists, then write the
        // placeholder. mkdir -p closes a tiny race where nginx could serve a
        // bare 404 between us inspecting and writing.
        $mkdir = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_PLACEHOLDER_MKDIR:%%s" "$?"',
            $this->privilegedCommand(
                $site->server,
                sprintf('mkdir -p %s', escapeshellarg($root))
            )
        ), 30);
        if (! preg_match('/DPLY_PLACEHOLDER_MKDIR:0\s*$/', $mkdir)) {
            throw new \RuntimeException('Unable to create the site web root for the placeholder. Output: '.Str::limit($mkdir, 1000));
        }

        $builder = $this->placeholderPageBuilder ??= new SitePlaceholderPageBuilder;
        $this->writeSystemFile($ssh, $root.'/index.html', $builder->render($site));
        $emit?->step($this->emitterSource(), 'installing placeholder page');
    }

    /**
     * Writes {@see SiteSuspendedPageBuilder} HTML under {@see Site::suspendedStaticRoot()}.
     * No-op unless the site is suspended; emits a `step` only when actual work happens.
     */
    protected function ensureSuspendedPage(Site $site, SshConnection $ssh, ?ConsoleEmitter $emit = null): void
    {
        if (! $site->isSuspended()) {
            return;
        }

        $dir = rtrim($site->suspendedStaticRoot(), '/');
        if ($dir === '') {
            return;
        }

        $emit?->step($this->emitterSource(), 'ensuring suspended page');
        $builder = new SiteSuspendedPageBuilder;
        $this->writeSystemFile($ssh, $dir.'/index.html', $builder->render($site));
    }

    /**
     * Writes the managed 500-series HTML page under {@see Site::managedErrorPagesRoot()}.
     */
    protected function ensureManagedErrorPages(Site $site, SshConnection $ssh, ?ConsoleEmitter $emit = null): void
    {
        if ($site->type === SiteType::Custom) {
            return;
        }

        $dir = rtrim($site->managedErrorPagesRoot(), '/');
        if ($dir === '') {
            return;
        }

        $emit?->step($this->emitterSource(), 'ensuring managed error pages');
        $builder = new SiteServerErrorPageBuilder;
        $this->writeSystemFile($ssh, $dir.'/'.SiteManagedErrorPageSupport::ERROR_FILENAME, $builder->render($site));
    }

    /**
     * Source label for emit lines from helpers shared across all webservers.
     * Falls back to 'webserver' when a subclass doesn't expose webserver()
     * (only AbstractSiteWebserverProvisioner without the contract — shouldn't
     * happen in practice, but the guard keeps the helper defensive).
     */
    private function emitterSource(): string
    {
        return method_exists($this, 'webserver') ? $this->webserver() : 'webserver';
    }

    protected function privilegedCommand(Server $server, string $command): string
    {
        $user = trim((string) $server->ssh_user);

        if ($user === '' || $user === 'root') {
            return $command;
        }

        return 'sudo -n bash -lc '.escapeshellarg($command);
    }

    protected function updateSiteMeta(Site $site, string $key, string $output): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta[$key] = $output;

        $site->meta = $meta;
        // Skip the DB save for transient sites (test fixtures, in-memory probes). When
        // the row isn't persisted there's nothing to update back to — saving here would
        // try to INSERT and trip the Site creating hook against a fixture without an
        // organization_id/user_id pair. Persisted sites still get their meta written.
        if ($site->exists) {
            $site->save();
        }
    }

    protected function readRemoteFile(Server $server, SshConnection $ssh, string $path): ?string
    {
        $out = $ssh->exec($this->privilegedCommand($server, 'cat '.escapeshellarg($path).' 2>/dev/null || true'), 30);
        $out = trim($out);

        return $out === '' ? null : $out;
    }

    /**
     * @param  string|null  $previousContent  null = treat as missing file
     */
    protected function restoreRemoteFile(SshConnection $ssh, Server $server, string $path, ?string $previousContent): void
    {
        if ($previousContent === null) {
            $ssh->exec($this->privilegedCommand($server, 'rm -f '.escapeshellarg($path)), 30);

            return;
        }

        $this->writeSystemFile($ssh, $path, $previousContent);
    }

    protected function configBasename(Site $site): string
    {
        return $site->webserverConfigBasename();
    }

    /**
     * Writes grouped htpasswd files under {@see Site::basicAuthStorageDirectoryOnHost()}.
     * Emits per-file `step` lines only when an htpasswd file actually changed —
     * a no-op apply (no managed rows, or content already on-disk) produces no
     * banner noise from this helper.
     *
     * Iterates over every URL path that EVER had a managed row (including the
     * paths whose users are all pending-removal). For each path, if there are
     * active users we rewrite the file; if all users at that path are pending,
     * we unlink the stale file so the next sync doesn't re-discover the
     * just-removed credentials. Without this, removing the last user at a path
     * leaves an orphan .htpasswd around forever.
     */
    protected function syncBasicAuthHtpasswdFiles(Site $site, SshConnection $ssh, ?ConsoleEmitter $emit = null): void
    {
        $site->loadMissing('basicAuthUsers');
        $server = $site->server;
        $base = $site->basicAuthStorageDirectoryOnHost();

        // Discovered rows (source_file_path set) live in arbitrary files outside
        // the managed group dir; their reconcile loop is below. Here we only
        // touch Dply-owned group files derived from the URL path.
        $managed = $site->basicAuthUsers->filter(
            fn (SiteBasicAuthUser $u): bool => ! $u->isDiscoveredFromServer()
        );

        $activeManaged = $managed->filter(fn (SiteBasicAuthUser $u): bool => ! $u->isPendingRemoval());

        // The set of paths Dply has EVER managed for this site (incl. paths
        // whose users are all pending-removal). The apply loop iterates this
        // set so a "remove last credential at /" leg correctly unlinks
        // /…/group-<hash>.htpasswd instead of leaving a stale file behind.
        $allPaths = $managed
            ->map(fn (SiteBasicAuthUser $u): string => $u->normalizedPath())
            ->unique()
            ->values();

        foreach ($allPaths as $normalizedPath) {
            $usersForPath = $activeManaged->filter(
                fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === $normalizedPath
            );
            $path = $site->basicAuthHtpasswdPathForNormalizedPath($normalizedPath);

            if ($usersForPath->isEmpty()) {
                // All users at this path are pending — remove the htpasswd file.
                if ($server !== null) {
                    $check = $ssh->exec(
                        $this->privilegedCommand($server, 'test -f '.escapeshellarg($path).' && echo present || echo missing'),
                        15
                    );
                    if (str_contains((string) $check, 'present')) {
                        $ssh->exec($this->privilegedCommand($server, 'rm -f '.escapeshellarg($path)), 15);
                        $emit?->step($this->emitterSource(), 'removed basic-auth credentials for '.$normalizedPath);
                    }
                } else {
                    $ssh->exec('rm -f '.escapeshellarg($path), 15);
                }

                continue;
            }

            $lines = $usersForPath->map(function (SiteBasicAuthUser $user): string {
                $name = trim($user->username);

                return $name.':'.trim($user->password_hash);
            })->filter(fn (string $line): bool => $line !== ':' && ! str_starts_with($line, ':'));

            $content = $lines->implode("\n").($lines->isNotEmpty() ? "\n" : '');

            if ($server !== null) {
                if ($this->writeSystemFileIfChanged($server, $ssh, $path, $content)) {
                    $emit?->step($this->emitterSource(), 'updating basic-auth credentials for '.$normalizedPath);
                }
            } else {
                $this->writeSystemFile($ssh, $path, $content);
            }
        }

        // Once every path has been cleared, drop the managed dir entirely so a
        // subsequent sync doesn't even see an empty `.dply/basic-auth/` lying
        // around. Discovered entries live elsewhere — they don't keep this dir
        // alive.
        if ($activeManaged->isEmpty()) {
            $out = $ssh->exec(sprintf('test -d %1$s && rm -rf %1$s && echo dropped 2>/dev/null || true', escapeshellarg($base)), 30);
            if (str_contains((string) $out, 'dropped')) {
                $emit?->step($this->emitterSource(), 'removed basic-auth directory (no credentials remaining)');
            }
        }

        $this->reconcileDiscoveredBasicAuthFiles($site, $ssh);
    }

    /**
     * For each discovered (source_file_path-bearing) row marked pending_removal_at,
     * drop the matching `username:` line from the source file on the server.
     * If the file ends up with no credential lines we unlink it; otherwise we
     * leave any unrelated lines (comments, entries Dply never imported because
     * the username collided) in place. That conservative rewrite avoids wiping
     * configuration the operator might still want.
     */
    protected function reconcileDiscoveredBasicAuthFiles(Site $site, SshConnection $ssh): void
    {
        $server = $site->server;
        if ($server === null) {
            return;
        }

        $pendingDiscovered = $site->basicAuthUsers->filter(
            fn (SiteBasicAuthUser $u): bool => $u->isDiscoveredFromServer() && $u->isPendingRemoval()
        );

        if ($pendingDiscovered->isEmpty()) {
            return;
        }

        $byFile = $pendingDiscovered->groupBy(fn (SiteBasicAuthUser $u): string => (string) $u->source_file_path);

        foreach ($byFile as $filePath => $rows) {
            $filePath = (string) $filePath;
            if ($filePath === '') {
                continue;
            }

            $contents = $this->readRemoteFile($server, $ssh, $filePath);
            if ($contents === null) {
                // File already gone — nothing to strip; the row gets hard-deleted by the job.
                continue;
            }

            $usernamesToDrop = $rows
                ->map(fn (SiteBasicAuthUser $u): string => trim($u->username))
                ->filter(fn (string $u): bool => $u !== '')
                ->unique()
                ->all();

            $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
            $kept = [];
            $hasCredentialLine = false;

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    $kept[] = $line;

                    continue;
                }

                $colon = strpos($trimmed, ':');
                if ($colon === false || str_starts_with($trimmed, '#')) {
                    $kept[] = $line;

                    continue;
                }

                $username = trim(substr($trimmed, 0, $colon));
                if (in_array($username, $usernamesToDrop, true)) {
                    continue;
                }

                $kept[] = $line;
                $hasCredentialLine = true;
            }

            if (! $hasCredentialLine) {
                // No credential lines remain — unlink the file so the gate is fully gone.
                // We use rm via the privileged channel because writeSystemFile already
                // assumes root ownership for the path; matching that here keeps perms consistent.
                $ssh->exec(
                    $this->privilegedCommand($server, 'rm -f '.escapeshellarg($filePath)),
                    30
                );

                continue;
            }

            $newContents = implode("\n", $kept);
            if ($newContents !== '' && substr($newContents, -1) !== "\n") {
                $newContents .= "\n";
            }

            $this->writeSystemFile($ssh, $filePath, $newContents);
        }
    }

    /**
     * Deploy or remove the on-host form-password gate bundle under
     * {@see Site::accessGateStorageDirectoryOnHost()}.
     */
    protected function syncAccessGateFiles(Site $site, SshConnection $ssh, ?ConsoleEmitter $emit = null): void
    {
        $payload = app(SiteAccessGateService::class)->configPayload($site);
        $base = $site->accessGateStorageDirectoryOnHost();
        $scriptPath = $site->accessGateScriptPathOnHost();
        $configPath = $site->accessGateConfigPathOnHost();
        $server = $site->server;

        if ($payload === null) {
            if ($server !== null) {
                $out = $ssh->exec(sprintf(
                    'test -d %1$s && rm -rf %1$s && echo dropped 2>/dev/null || true',
                    escapeshellarg($base),
                ), 30);
                if (str_contains((string) $out, 'dropped')) {
                    $emit?->step($this->emitterSource(), 'removed form-password gate directory');
                }
            } else {
                $ssh->exec(sprintf('test -d %1$s && rm -rf %1$s || true', escapeshellarg($base)), 30);
            }

            return;
        }

        $scriptSource = (string) file_get_contents(resource_path('site-scripts/vm-access-gate.php'));
        $configJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $logPath = $site->accessGateLoginLogPathOnHost();

        if ($server !== null) {
            $ssh->exec($this->privilegedCommand($server, 'mkdir -p '.escapeshellarg($base)), 30);
            if ($this->writeSystemFileIfChanged($server, $ssh, $scriptPath, $scriptSource)) {
                $emit?->step($this->emitterSource(), 'updating form-password gate script');
            }
            if ($this->writeSystemFileIfChanged($server, $ssh, $configPath, $configJson."\n")) {
                $emit?->step($this->emitterSource(), 'updating form-password gate config');
            }
            $this->ensureAccessGateLoginLogWritable($site, $server, $ssh, $base, $logPath, $emit);
        } else {
            $ssh->exec('mkdir -p '.escapeshellarg($base), 30);
            $this->writeSystemFile($ssh, $scriptPath, $scriptSource);
            $this->writeSystemFile($ssh, $configPath, $configJson."\n");
            @touch($logPath);
            @chmod($base, 0775);
            @chmod($logPath, 0664);
        }
    }

    /**
     * PHP-FPM executes the gate script; it must be able to append logins.jsonl even
     * though Dply writes index.php/config.json as root-owned system files.
     */
    protected function ensureAccessGateLoginLogWritable(
        Site $site,
        Server $server,
        SshConnection $ssh,
        string $base,
        string $logPath,
        ?ConsoleEmitter $emit = null,
    ): void {
        $runtimeUser = $site->accessGatePhpRuntimeUser($server);
        $cmd = sprintf(
            'touch %1$s && chown %3$s:%3$s %2$s %1$s && chmod 775 %2$s && chmod 664 %1$s',
            escapeshellarg($logPath),
            escapeshellarg($base),
            escapeshellarg($runtimeUser),
        );

        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_ACCESS_GATE_LOG_PERM_EXIT:%%s" "$?"',
            $this->privilegedCommand($server, $cmd)
        ), 30);

        if (! preg_match('/DPLY_ACCESS_GATE_LOG_PERM_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException(
                'Unable to prepare form-password gate login log permissions on '.$logPath.'. Output: '.Str::limit($out, 1000)
            );
        }

        $emit?->step($this->emitterSource(), 'ensuring form-password gate login log is writable');
    }
}
