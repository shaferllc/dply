<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Enums\ServerProvider;
use App\Jobs\CloneServerOnDigitalOceanJob;
use App\Jobs\CreateServerImageJob;
use App\Jobs\RefreshServerPrivateIpJob;
use App\Models\Server;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\VultrService;
use Carbon\Carbon;

/**
 * Thin capability + dispatch layer for full-disk server images, sitting in front
 * of the per-provider service classes (which share no common interface — see
 * {@see RefreshServerPrivateIpJob} for the same match-on-provider idiom).
 *
 * DigitalOcean, Hetzner, Vultr, and Linode wrap the image API today; everything
 * else reports unsupported so the Snapshots workspace can render a "not
 * available" state rather than a broken button.
 *
 * `create()` blocks while it polls the provider action to completion — it MUST run
 * inside a queue job ({@see CreateServerImageJob}), never in a web request.
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
        // Vultr instance IDs are UUIDs, not ints — keep the raw string and only
        // narrow to int inside the DO/Hetzner branches that expect numeric IDs.
        $providerId = trim((string) $server->provider_id);
        if ($providerId === '') {
            throw new \RuntimeException('Server has no provider_id to image.');
        }

        $credential = $server->providerCredential;
        if ($credential === null) {
            throw new \RuntimeException('Server has no provider credential to call the image API with.');
        }

        return match ($server->provider) {
            ServerProvider::DigitalOcean => $this->createDigitalOcean(new DigitalOceanService($credential), (int) $providerId, $name, $onTick),
            ServerProvider::Hetzner => $this->createHetzner(new HetznerService($credential), (int) $providerId, $name, $onTick),
            ServerProvider::Vultr => $this->createVultr(new VultrService($credential), $providerId, $name, $onTick),
            ServerProvider::Linode => $this->createLinode(new LinodeService($credential), (int) $providerId, $name, $onTick),
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
            ServerProvider::Vultr => (new VultrService($credential))->deleteSnapshot($providerImageId),
            ServerProvider::Linode => (new LinodeService($credential))->deleteImage($providerImageId),
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
     * Vultr has no action-object model — the capture's progress is read off the
     * snapshot's own `status` field, and snapshots aren't region-scoped (region is
     * left null so the job falls back to the source server's region). `size` is
     * already in bytes.
     *
     * @param  callable(string):void|null  $onTick
     * @return array{provider_image_id: string, provider_action_id: ?string, region: ?string, bytes: ?int}
     */
    protected function createVultr(VultrService $vultr, string $instanceId, string $name, ?callable $onTick): array
    {
        $snapshotId = $vultr->createSnapshot($instanceId, $name);

        $snapshot = $vultr->waitForSnapshot($snapshotId, onTick: function (array $s) use ($onTick): void {
            if ($onTick !== null) {
                $onTick('Vultr snapshot '.(string) ($s['status'] ?? 'in progress'));
            }
        });

        // Vultr bills snapshots on their compressed size, so that's the figure that
        // matches the monthly-cost estimate (ServerProvider::imageSnapshotRatePerGbMonth).
        // Fall back to the raw disk size if compression info is absent.
        $bytes = (int) ($snapshot['compressed_size'] ?? $snapshot['size'] ?? 0);

        return [
            'provider_image_id' => (string) ($snapshot['id'] ?? $snapshotId),
            'provider_action_id' => null,
            'region' => null,
            'bytes' => $bytes > 0 ? $bytes : null,
        ];
    }

    /**
     * Linode images are captured from a single disk, not the whole instance — so
     * we image the largest bootable (ext4) disk. Like Vultr there's no action
     * object: progress is read off the image's `status`, and images aren't
     * region-scoped (region left null → falls back to the source server's region).
     * Linode reports `size` in MB.
     *
     * @param  callable(string):void|null  $onTick
     * @return array{provider_image_id: string, provider_action_id: ?string, region: ?string, bytes: ?int}
     */
    protected function createLinode(LinodeService $linode, int $instanceId, string $name, ?callable $onTick): array
    {
        $diskId = $linode->primaryDiskId($instanceId);
        if ($diskId === null) {
            throw new \RuntimeException('Could not find a bootable (ext4) disk on this Linode to image.');
        }

        $created = $linode->createImageFromDisk($diskId, $name);
        $imageId = (string) ($created['id'] ?? '');
        if ($imageId === '') {
            throw new \RuntimeException('Linode accepted the image request but returned no image id.');
        }

        $image = $linode->waitForImage($imageId, onTick: function (array $i) use ($onTick): void {
            if ($onTick !== null) {
                $onTick('Linode image '.(string) ($i['status'] ?? 'in progress'));
            }
        });

        $sizeMb = (int) ($image['size'] ?? 0);
        $bytes = $sizeMb > 0 ? $sizeMb * 1024 * 1024 : null;

        return [
            'provider_image_id' => (string) ($image['id'] ?? $imageId),
            'provider_action_id' => null,
            'region' => null,
            'bytes' => $bytes,
        ];
    }

    /**
     * Locate the just-created DO snapshot: by exact name first, then the most
     * recent snapshot whose resource_id matches the source droplet (DO sometimes
     * suffixes the name). Mirrors {@see CloneServerOnDigitalOceanJob}.
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
