<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Services\SshConnectionFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Relocates a site's files on its server to a new base path (default
 * /home/dply/<domain>), updates repository_path / document_root, and
 * re-renders + reloads the web-server vhost via {@see ApplySiteWebserverConfigJob}.
 *
 * SSH runs only here (queued), never inline in a request. The move is
 * idempotent: it only moves when the source exists and the target doesn't,
 * otherwise it just ensures the target exists. The next deploy re-clones into
 * the new path (the clone step is init-in-place), so a missing move is safe.
 */
class RelocateSiteFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $siteId,
        public ?string $newPath = null,
        public ?string $userId = null,
    ) {}

    public function handle(SshConnectionFactory $sshFactory): void
    {
        $site = Site::query()->with(['server', 'domains'])->find($this->siteId);
        if (! $site || ! $site->server) {
            Log::warning('RelocateSiteFilesJob: site or server missing', ['site_id' => $this->siteId]);

            return;
        }

        $server = $site->server;
        $old = rtrim($site->effectiveRepositoryPath(), '/');
        $new = rtrim($this->newPath ?: $site->conventionalRepositoryPath(), '/');

        if ($new === '' || ! str_starts_with($new, '/')) {
            throw new \InvalidArgumentException('Relocation target must be an absolute path.');
        }

        if ($old !== $new) {
            $user = trim((string) $server->ssh_user) ?: (string) config('server_provision.deploy_ssh_user', 'dply');
            $userEsc = escapeshellarg($user);
            $webGroup = $this->webServerGroup();
            $groupEsc = escapeshellarg($webGroup);

            // Group-own /home/dply and the site tree by the web-server group
            // (e.g. www-data, which both nginx and the caddy user belong to) so
            // the serving process can traverse and read the files without making
            // the deploy user's home world-accessible. The setgid bit on the
            // parent (2750) keeps files written by later deploys group-owned by
            // the web group. This mirrors the group model in
            // ServerSystemUserService::resetSiteFilePermissions().
            //
            // NB: relying on the *group* (not adding a specific web user to the
            // deploy group) is what makes this work on Caddy hosts, where the
            // serving user is `caddy` rather than the configured `www-data`.
            // The move is idempotent: only moves when the target is clear.
            $script = sprintf(
                'mkdir -p %1$s && chown %4$s:%5$s %1$s && chmod 2750 %1$s && '
                .'if [ -e %2$s ] && [ ! -e %3$s ]; then mv %2$s %3$s; else mkdir -p %3$s; fi && '
                .'chown -R %4$s:%5$s %3$s && chmod -R g+rX %3$s; '
                // Laravel writes into storage/ and bootstrap/cache/ at runtime
                // (logs, compiled views, caches, sessions) as the web-server
                // user, so those two trees need to be group-WRITABLE — the
                // blanket g+rX above only grants read. 2775 dirs / 664 files
                // give the web group write, and the setgid bit keeps files the
                // app writes later group-owned by the web group. Mirrors the
                // writable-dir handling in
                // ServerSystemUserService::resetSiteFilePermissions().
                .'for d in storage bootstrap/cache; do p=%3$s/"$d"; [ -d "$p" ] || continue; '
                .'find "$p" -type d -exec chmod 2775 {} + ; find "$p" -type f -exec chmod 664 {} + ; done',
                escapeshellarg(dirname($new)),
                escapeshellarg($old),
                escapeshellarg($new),
                $userEsc,
                $groupEsc,
            );

            $ssh = $sshFactory->forServer($server);
            $out = $ssh->exec(sprintf(
                '(%s) 2>&1; printf "\nDPLY_RELOCATE_EXIT:%%s" "$?"',
                $this->privileged($server, $script)
            ), $this->timeout);

            if (! preg_match('/DPLY_RELOCATE_EXIT:0\s*$/', $out)) {
                throw new \RuntimeException('Site relocation failed: '.Str::limit(trim($out), 2000));
            }
        }

        // Preserve the document-root sub-path (e.g. /public) relative to the
        // old base when rewriting it onto the new base.
        $oldRoot = rtrim((string) $site->document_root, '/');
        $subdir = ($old !== '' && str_starts_with($oldRoot, $old))
            ? substr($oldRoot, strlen($old))
            : '';

        $site->update([
            'repository_path' => $new,
            'document_root' => $new.$subdir,
        ]);

        ApplySiteWebserverConfigJob::dispatch($site->id, $this->userId);
    }

    /**
     * The web-server group that owns the served files so the web server (nginx
     * as www-data, or Caddy as the caddy user — both members of this group) can
     * read them. Matches the provisioners' default (www-data). Validated to a
     * safe passwd-style name before it goes into a shell command.
     */
    private function webServerGroup(): string
    {
        $group = trim((string) config('site_settings.vm_site_file_web_group', 'www-data'));

        return preg_match('/^[a-z_][a-z0-9_-]*$/', $group) === 1 ? $group : 'www-data';
    }

    /**
     * Wrap a command for privileged execution when the SSH user isn't root,
     * matching the rest of the provisioning code.
     */
    private function privileged(Server $server, string $command): string
    {
        $user = trim((string) $server->ssh_user);

        return $user === '' || $user === 'root'
            ? $command
            : 'sudo -n bash -lc '.escapeshellarg($command);
    }
}
