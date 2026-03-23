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

        $out = $ssh->exec(sprintf(
            'if [ -d %1$s ]; then ln -sfn %1$s %2$s/current && echo DPLY_ROLLBACK_OK; else echo DPLY_ROLLBACK_MISSING; fi',
            escapeshellarg($target),
            escapeshellarg($base)
        ), 60);

        if (! str_contains($out, 'DPLY_ROLLBACK_OK')) {
            throw new \RuntimeException('Rollback failed: release directory missing or symlink error. '.$out);
        }

        SiteRelease::query()->where('site_id', $site->id)->update(['is_active' => false]);
        $release->update(['is_active' => true]);

        return $out;
    }
}
