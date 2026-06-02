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
            $webUser = $this->webServerUser();
            $webUserEsc = escapeshellarg($webUser);

            // Keep /home/dply private (750) and instead add the web-server
            // user to the deploy user's group, then group-own the tree and
            // make it group-readable (g+rX) — so nginx/Caddy can traverse and
            // serve the files without making the home directory world-readable.
            // The move is idempotent: only moves when the target is clear.
            $script = sprintf(
                'mkdir -p %1$s && chown %4$s:%4$s %1$s && chmod 750 %1$s && '
                .'usermod -aG %4$s %5$s 2>/dev/null || true; '
                .'if [ -e %2$s ] && [ ! -e %3$s ]; then mv %2$s %3$s; else mkdir -p %3$s; fi && '
                .'chown -R %4$s:%4$s %3$s && chmod -R g+rX %3$s',
                escapeshellarg(dirname($new)),
                escapeshellarg($old),
                escapeshellarg($new),
                $userEsc,
                $webUserEsc,
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
     * The web-server user/group that needs read access to the served files,
     * matching the provisioners' default (www-data). Validated to a safe
     * passwd-style name before it goes into a shell command.
     */
    private function webServerUser(): string
    {
        $user = trim((string) config('site_settings.vm_site_file_web_group', 'www-data'));

        return preg_match('/^[a-z_][a-z0-9_-]*$/', $user) === 1 ? $user : 'www-data';
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
