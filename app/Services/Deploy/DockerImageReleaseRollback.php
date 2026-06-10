<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;
use App\Models\SiteRelease;
use App\Services\SshConnectionFactory;
use RuntimeException;

/**
 * Rolls a container (image-method) site back to a previous release by re-running
 * its tagged image — no rebuild. The Docker counterpart to SiteReleaseRollback
 * (which flips the atomic `current` symlink). The target image must still exist
 * on the host (not yet pruned). See docs/DEPLOYMENT_METHODS.md.
 */
class DockerImageReleaseRollback
{
    public function __construct(
        private readonly DockerComposeArtifactBuilder $composeBuilder,
        private readonly SshConnectionFactory $sshFactory,
    ) {}

    public function rollbackTo(Site $site, SiteRelease $release): string
    {
        $server = $site->server;
        if (! $server || ! $server->isReady() || empty($server->ssh_private_key)) {
            throw new RuntimeException('Docker host must be reachable over SSH to roll back.');
        }

        $path = rtrim($site->effectiveRepositoryPath(), '/');
        $pathEsc = escapeshellarg($path);
        $imageTag = 'dply-site-'.$site->id.':'.$release->folder;
        $imageEsc = escapeshellarg($imageTag);

        $ssh = $this->sshFactory->forServer($server);

        $exists = trim($ssh->exec(
            sprintf('docker image inspect %s >/dev/null 2>&1 && echo OK || echo MISSING', $imageEsc),
            60
        ));
        if (! str_contains($exists, 'OK')) {
            throw new RuntimeException('Rollback target image is no longer on the host (likely pruned): '.$imageTag);
        }

        // Run the existing image — withBuild:false so compose doesn't rebuild
        // from the current source, it just starts the pinned tag.
        $compose = $this->composeBuilder->build($site, $imageTag, withBuild: false);
        $ssh->putFile($path.'/docker-compose.dply.yml', $compose);

        $log = "[dply] image rollback → {$imageTag}\n";
        $log .= $ssh->exec(
            sprintf('cd %s && docker compose -f docker-compose.dply.yml up -d 2>&1', $pathEsc),
            600
        );

        SiteRelease::query()->where('site_id', $site->id)->update(['is_active' => false]);
        $release->forceFill(['is_active' => true])->save();

        return $log;
    }
}
