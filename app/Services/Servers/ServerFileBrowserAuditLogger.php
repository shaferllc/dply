<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Wraps the global audit_log() helper for file-browser events so the
 * Livewire components don't repeat the action-name + meta scaffolding.
 *
 *  - Writes are always logged.
 *  - Downloads are always logged.
 *  - Opens are only logged when the path matches a sensitive-glob pattern
 *    from config('server_file_browser.sensitive_path_globs').
 *  - View-as-root toggles on the server browser are always logged.
 */
class ServerFileBrowserAuditLogger
{
    public function recordOpen(
        Organization $organization,
        ?User $user,
        Server $server,
        ?Site $site,
        string $path,
        string $loginUser,
    ): void {
        audit_log(
            $organization,
            $user,
            $this->action($site, 'opened'),
            $site ?: $server,
            null,
            [
                'path' => $path,
                'login_user' => $loginUser,
                'server_id' => $server->id,
                'site_id' => $site?->id,
            ],
        );
    }

    public function recordDownload(
        Organization $organization,
        ?User $user,
        Server $server,
        ?Site $site,
        string $path,
        int $bytes,
        string $sha256,
        string $loginUser,
    ): void {
        audit_log(
            $organization,
            $user,
            $this->action($site, 'downloaded'),
            $site ?: $server,
            null,
            [
                'path' => $path,
                'bytes' => $bytes,
                'sha256' => $sha256,
                'login_user' => $loginUser,
                'server_id' => $server->id,
                'site_id' => $site?->id,
            ],
        );
    }

    public function recordWrite(
        Organization $organization,
        ?User $user,
        Server $server,
        Site $site,
        string $path,
        string $oldSha256,
        string $newSha256,
        int $oldBytes,
        int $newBytes,
        string $loginUser,
        bool $insideReleases,
    ): void {
        audit_log(
            $organization,
            $user,
            'site.files.saved',
            $site,
            ['sha256' => $oldSha256, 'bytes' => $oldBytes],
            [
                'path' => $path,
                'sha256' => $newSha256,
                'bytes' => $newBytes,
                'byte_delta' => $newBytes - $oldBytes,
                'login_user' => $loginUser,
                'inside_releases' => $insideReleases,
                'server_id' => $server->id,
                'site_id' => $site->id,
            ],
        );
    }

    public function recordRootToggle(
        Organization $organization,
        ?User $user,
        Server $server,
        bool $enabled,
    ): void {
        audit_log(
            $organization,
            $user,
            'server.files.view_as_root.'.($enabled ? 'enabled' : 'disabled'),
            $server,
            null,
            [
                'server_id' => $server->id,
            ],
        );
    }

    protected function action(?Model $subject, string $verb): string
    {
        return $subject instanceof Site
            ? 'site.files.'.$verb
            : 'server.files.'.$verb;
    }
}
