<?php

declare(strict_types=1);

namespace App\Services\Servers\Resize;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Services\Servers\Resize\Concerns\BuildsResizeOptions;

/**
 * DigitalOcean droplets. Resize is an action on the droplet, requires the
 * droplet to be off, and takes a `disk` flag that is permanent when true.
 */
class DigitalOceanResizeDriver implements ServerResizeDriver
{
    use BuildsResizeOptions;

    public function supports(Server $server): bool
    {
        return $server->provider === ServerProvider::DigitalOcean
            && $server->providerCredential !== null
            && filled($server->provider_id);
    }

    public function requiresPowerCycle(): bool
    {
        return true;
    }

    public function catalog(Server $server): array
    {
        $do = new DigitalOceanService($server->providerCredential);

        // Live droplet, not the stored row: `servers.size` goes stale when an
        // operator resizes in the provider console, and a stale disk baseline
        // is how an illegal shrink gets offered.
        $droplet = $do->getDroplet((int) $server->provider_id);
        $sizeObj = is_array($droplet['size'] ?? null) ? $droplet['size'] : [];

        $current = [
            'slug' => self::str($droplet['size_slug'] ?? $sizeObj['slug'] ?? null),
            'vcpus' => self::int($droplet['vcpus'] ?? $sizeObj['vcpus'] ?? null),
            'memory_mb' => self::int($droplet['memory'] ?? $sizeObj['memory'] ?? null),
            'disk_gb' => self::int($droplet['disk'] ?? $sizeObj['disk'] ?? null),
            'region' => self::str($droplet['region']['slug'] ?? null),
        ];

        $options = [];
        foreach ($do->getSizes() as $size) {
            $slug = self::str($size['slug'] ?? null);
            if ($slug === null || $slug === $current['slug']) {
                continue;
            }
            if (($size['available'] ?? false) !== true) {
                continue;
            }

            // A resize cannot move a droplet between regions.
            $regions = is_array($size['regions'] ?? null) ? $size['regions'] : [];
            if ($current['region'] !== null && ! in_array($current['region'], $regions, true)) {
                continue;
            }

            $disk = self::int($size['disk'] ?? null);
            if (! $this->diskCanHold($disk, $current['disk_gb'])) {
                continue;
            }

            $options[] = $this->option(
                $slug,
                self::int($size['vcpus'] ?? null) ?? 0,
                self::int($size['memory'] ?? null) ?? 0,
                $disk,
                self::float($size['price_monthly'] ?? null),
                $current['disk_gb'],
                $current['memory_mb'] ?? 0,
            );
        }

        return ['current' => $current, 'options' => $this->sortOptions($options)];
    }

    public function execute(Server $server, array $target, callable $progress): void
    {
        $do = new DigitalOceanService($server->providerCredential);
        $dropletId = (int) $server->provider_id;

        $progress('powering_off');
        $off = $do->powerOffDroplet($dropletId);
        $do->waitForDropletAction($dropletId, (int) $off['id'], timeoutSeconds: 600);

        $progress('resizing');
        $resize = $do->resizeDroplet($dropletId, $target['slug'], $target['grows_disk']);
        $do->waitForDropletAction($dropletId, (int) $resize['id'], timeoutSeconds: 3000);

        $progress('powering_on');
        $on = $do->powerOnDroplet($dropletId);
        $do->waitForDropletAction($dropletId, (int) $on['id'], timeoutSeconds: 600);
    }
}
