<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteRelease;
use App\Services\SshConnection;

class SiteReleaseRollback
{
    public function rollbackTo(Site $site, SiteRelease $release): string
    {
        if ($release->site_id !== $site->id) {
            throw new \InvalidArgumentException('Release does not belong to this site.');
        }

        $server = $site->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $base = rtrim($site->effectiveRepositoryPath(), '/');
        $target = $base.'/releases/'.$release->folder;
        $ssh = new SshConnection($server);

        // Drop a non-symlink `current` (e.g. a root-owned provisioning
        // placeholder dir) first — `ln -sfn` would nest the link inside a real
        // directory rather than replace it. Its contents can be root-owned
        // while we run as the deploy user, so sudo the removal (fall back to a
        // plain rm). See PipelineAnchorScriptRunner::runActivate().
        // Only flip `current` to a release that actually exists AND has content.
        // A missing or empty/stub directory (e.g. pruned by releases_to_keep, or a
        // half-cleaned folder) would otherwise point `current` at a broken tree —
        // turning a failed deploy's auto-rollback into a real outage.
        $out = $ssh->exec(sprintf(
            'if [ -d %1$s ] && [ -n "$(ls -A %1$s 2>/dev/null)" ]; then '
            .'if [ -L %2$s/current ]; then :; elif [ -e %2$s/current ]; then sudo -n rm -rf %2$s/current 2>&1 || rm -rf %2$s/current; fi; '
            .'ln -sfn %1$s %2$s/current && echo DPLY_ROLLBACK_OK; else echo DPLY_ROLLBACK_MISSING; fi',
            escapeshellarg($target),
            escapeshellarg($base)
        ), 60);

        if (! str_contains($out, 'DPLY_ROLLBACK_OK')) {
            throw new \RuntimeException('Rollback failed: target release directory is missing or empty (refusing to point `current` at a broken release). '.$out);
        }

        SiteRelease::query()->where('site_id', $site->id)->update(['is_active' => false]);
        $release->update(['is_active' => true]);

        return $out;
    }
}
