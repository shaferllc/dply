<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use Carbon\Carbon;

/**
 * Thin capability + dispatch layer for full-disk server images, sitting in front
 * of the per-provider service classes (which share no common interface — see
 * {@see \App\Jobs\RefreshServerPrivateIpJob} for the same match-on-provider idiom).
 *
 * Only DigitalOcean and Hetzner wrap the image API today; everything else reports
 * unsupported so the Snapshots workspace can render a "not available" state rather
 * than a broken button.
 *
 * `create()` blocks while it polls the provider action to completion — it MUST run
 * inside a queue job ({@see \App\Jobs\CreateServerImageJob}), never in a web request.
 */
class ServerImageProvider
{
    public static function supports(Server $server): bool
    {
        return $server->provider?->supportsImageSnapshots() ?? false;
    }

    /**
     * Fire the provider create-image action and poll it to completion.
     *
     * @param  callable(string):void|null  $onTick  receives a short progress note per poll
     * @return array{provider_image_id: string, provider_action_id: ?string, region: ?string, bytes: ?int}
     */
    public function create(Server $server, string $name, ?callable $onTick = null): array
    {
        $dropletId = (int) $server->provider_id;
        if ($dropletId <= 0) {
            throw new \RuntimeException('Server has no provider_id to image.');
        }

        $credential = $server->providerCredential;
        if ($credential === null) {
            throw new \RuntimeException('Server has no provider credential to call the image API with.');
        }

        return match ($server->provider) {
            ServerProvider::DigitalOcean => $this->createDigitalOcean(new DigitalOceanService($credential), $dropletId, $name, $onTick),
            ServerProvider::Hetzner => $this->createHetzner(new HetznerService($credential), $dropletId, $name, $onTick),
            default => throw new \RuntimeException('Image snapshots are not supported on '.($server->provider?->label() ?? 'this provider').'.'),
        };
    }

    public function delete(Server $server, string $providerImageId): void
    {
        $credential = $server->providerCredential;
        if ($credential === null) {
            throw new \RuntimeException('Server has no provider credential to call the image API with.');
        }

        match ($server->provider) {
            ServerProvider::DigitalOcean => (new DigitalOceanService($credential))->deleteSnapshot($providerImageId),
            ServerProvider::Hetzner => (new HetznerService($credential))->deleteImage((int) $providerImageId),
            default => throw new \RuntimeException('Image snapshots are not supported on '.($server->provider?->label() ?? 'this provider').'.'),
        };
    }

    /**
     * @param  callable(string):void|null  $onTick
     * @return array{provider_image_id: string, provider_action_id: ?string, region: ?string, bytes: ?int}
     */
    protected function createDigitalOcean(DigitalOceanService $do, int $dropletId, string $name, ?callable $onTick): array
    {
        $action = $do->snapshotDroplet($dropletId, $name);
        $actionId = (int) ($action['id'] ?? 0);

        $do->waitForDropletAction($dropletId, $actionId, onTick: function (array $a) use ($onTick): void {
            if ($onTick !== null) {
                $onTick('DigitalOcean snapshot '.(string) ($a['status'] ?? 'in progress'));
            }
        });

        $snapshot = $this->findDigitalOceanSnapshot($do, $name, (string) $dropletId);
        if ($snapshot === null) {
            throw new \RuntimeException('DigitalOcean reported the snapshot completed but it could not be found in the snapshot list.');
        }

        $sizeGb = (float) ($snapshot['size_gigabytes'] ?? 0);

        return [
            'provider_image_id' => (string) ($snapshot['id'] ?? ''),
            'provider_action_id' => $actionId > 0 ? (string) $actionId : null,
            'region' => is_array($snapshot['regions'] ?? null) ? (string) ($snapshot['regions'][0] ?? '') : null,
            'bytes' => $sizeGb > 0 ? (int) round($sizeGb * 1024 * 1024 * 1024) : null,
        ];
    }

    /**
     * @param  callable(string):void|null  $onTick
     * @return array{provider_image_id: string, provider_action_id: ?string, region: ?string, bytes: ?int}
     */
    protected function createHetzner(HetznerService $h, int $serverId, string $name, ?callable $onTick): array
    {
        $result = $h->createImageFromServer($serverId, $name);
        $actionId = (int) ($result['action']['id'] ?? 0);
        $imageId = (int) ($result['image_id'] ?? 0);

        $h->waitForAction($actionId, onTick: function (array $a) use ($onTick): void {
            if ($onTick !== null) {
                $onTick('Hetzner snapshot '.(string) ($a['status'] ?? 'in progress'));
            }
        });

        $bytes = null;
        $region = null;
        try {
            $image = $h->getImage($imageId);
            // Hetzner reports image_size in GB (float); disk_size is the GB of the
            // disk it can be restored onto. Prefer image_size for "what it weighs".
            $sizeGb = (float) ($image['image_size'] ?? $image['disk_size'] ?? 0);
            $bytes = $sizeGb > 0 ? (int) round($sizeGb * 1024 * 1024 * 1024) : null;
        } catch (\Throwable) {
            // Size is cosmetic — don't fail the whole capture if the follow-up read hiccups.
        }

        return [
            'provider_image_id' => (string) $imageId,
            'provider_action_id' => $actionId > 0 ? (string) $actionId : null,
            'region' => $region,
            'bytes' => $bytes,
        ];
    }

    /**
     * Locate the just-created DO snapshot: by exact name first, then the most
     * recent snapshot whose resource_id matches the source droplet (DO sometimes
     * suffixes the name). Mirrors {@see \App\Jobs\CloneServerOnDigitalOceanJob}.
     *
     * @return array<string, mixed>|null
     */
    protected function findDigitalOceanSnapshot(DigitalOceanService $do, string $name, string $sourceDropletId): ?array
    {
        $snapshots = $do->getSnapshots('droplet');

        foreach ($snapshots as $snapshot) {
            if (is_array($snapshot) && (string) ($snapshot['name'] ?? '') === $name) {
                return $snapshot;
            }
        }

        $candidate = null;
        $candidateAt = null;
        foreach ($snapshots as $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }
            $resourceIds = $snapshot['resource_id'] ?? null;
            $matches = is_array($resourceIds)
                ? in_array($sourceDropletId, array_map('strval', $resourceIds), true)
                : (string) $resourceIds === $sourceDropletId;
            if (! $matches) {
                continue;
            }
            $createdAt = isset($snapshot['created_at']) && is_string($snapshot['created_at'])
                ? Carbon::parse($snapshot['created_at'])->getTimestamp()
                : 0;
            if ($candidateAt === null || $createdAt > $candidateAt) {
                $candidate = $snapshot;
                $candidateAt = $createdAt;
            }
        }

        return $candidate;
    }
}
