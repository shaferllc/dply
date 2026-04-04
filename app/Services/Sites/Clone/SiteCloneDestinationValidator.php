<?php

namespace App\Services\Sites\Clone;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Support\Collection;

final class SiteCloneDestinationValidator
{
    /**
     * @throws \RuntimeException
     */
    public static function validateOrFail(User $user, Site $source, Server $destServer, string $primaryHostname): void
    {
        $org = Organization::query()->find($source->organization_id);
        if ($org === null) {
            throw new \RuntimeException(__('The source site organization could not be resolved.'));
        }

        if (! $user->organizations()->whereKey($org->id)->exists()) {
            throw new \RuntimeException(__('You do not have access to this organization.'));
        }

        if ((string) $destServer->organization_id !== (string) $org->id) {
            throw new \RuntimeException(__('The destination server does not belong to your organization.'));
        }

        if (! $destServer->isReady() || ! $destServer->hasAnySshPrivateKey()) {
            throw new \RuntimeException(__('The destination server must be ready with SSH.'));
        }

        if (! $org->canCreateSite()) {
            throw new \RuntimeException(__('Your organization has reached the site limit for the current plan.'));
        }

        $host = strtolower(trim($primaryHostname));
        $exists = SiteDomain::query()->where('hostname', $host)->exists();
        if ($exists) {
            throw new \RuntimeException(__('That domain is already assigned to a site.'));
        }

        self::assertCompatibleRuntime($source, $destServer);

        if (! $source->usesFunctionsRuntime()
            && ! $source->usesDockerRuntime()
            && ! $source->usesKubernetesRuntime()) {
            $srcSrv = $source->server;
            if ($srcSrv === null || ! $srcSrv->isReady() || ! $srcSrv->hasAnySshPrivateKey()) {
                throw new \RuntimeException(__('The source server must be ready with SSH before cloning files.'));
            }
        }
    }

    /**
     * @throws \RuntimeException
     */
    public static function assertCompatibleRuntime(Site $source, Server $destServer): void
    {
        $srcHost = $source->server?->hostKind();
        $dstHost = $destServer->hostKind();

        if ($source->usesFunctionsRuntime()) {
            if (! $destServer->hostCapabilities()->supportsFunctionDeploy()) {
                throw new \RuntimeException(__('Clone a serverless site only to a serverless-capable host (same class of target).'));
            }
            if ($srcHost !== $dstHost) {
                throw new \RuntimeException(__('Serverless clones must use the same host kind as the source (for example AWS Lambda to AWS Lambda).'));
            }

            return;
        }

        if ($source->usesDockerRuntime()) {
            if (! $destServer->hostCapabilities()->supportsContainerDeploy()) {
                throw new \RuntimeException(__('Clone a Docker runtime site only to a Docker-capable server.'));
            }

            return;
        }

        if ($source->usesKubernetesRuntime()) {
            if (! $destServer->hostCapabilities()->supportsClusterDeploy()) {
                throw new \RuntimeException(__('Clone a Kubernetes runtime site only to a Kubernetes-capable server.'));
            }

            return;
        }

        if (! $destServer->hostCapabilities()->supportsSsh()) {
            throw new \RuntimeException(__('Clone a VM site only to a VM (SSH) server.'));
        }
    }

    public static function destinationServersForUser(Organization $org): Collection
    {
        return Server::query()
            ->where('organization_id', $org->id)
            ->where('status', Server::STATUS_READY)
            ->orderBy('name')
            ->get();
    }
}
