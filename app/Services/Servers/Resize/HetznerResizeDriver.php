<?php

declare(strict_types=1);

namespace App\Services\Servers\Resize;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Modules\Providers\Services\HetznerService;
use App\Services\Servers\Resize\Concerns\BuildsResizeOptions;

/**
 * Hetzner Cloud servers. `change_type` needs the server powered off and takes
 * an `upgrade_disk` flag with the same permanence as DigitalOcean's `disk`.
 *
 * Two Hetzner-specific wrinkles the filtering has to respect:
 *   - server types are sold per-location with their own availability, carried
 *     in each type's `prices[]` rather than a flat region list;
 *   - Hetzner reports memory in GB (as a float), not MB.
 */
class HetznerResizeDriver implements ServerResizeDriver
{
    use BuildsResizeOptions;

    public function supports(Server $server): bool
    {
        return $server->provider === ServerProvider::Hetzner
            && $server->providerCredential !== null
            && filled($server->provider_id);
    }

    public function requiresPowerCycle(): bool
    {
        return true;
    }

    public function catalog(Server $server): array
    {
        $hetzner = new HetznerService($server->providerCredential);

        $instance = $hetzner->getInstance((int) $server->provider_id);
        $type = is_array($instance['server_type'] ?? null) ? $instance['server_type'] : [];

        $current = [
            'slug' => self::str($type['name'] ?? null),
            'vcpus' => self::int($type['cores'] ?? null),
            'memory_mb' => self::gbToMb($type['memory'] ?? null),
            'disk_gb' => self::int($type['disk'] ?? null),
            'region' => self::str($instance['datacenter']['location']['name'] ?? null),
        ];

        $options = [];
        foreach ($hetzner->getServerTypes() as $candidate) {
            $slug = self::str($candidate['name'] ?? null);
            if ($slug === null || $slug === $current['slug']) {
                continue;
            }
            if (($candidate['deprecated'] ?? false) === true) {
                continue;
            }

            $disk = self::int($candidate['disk'] ?? null);
            if (! $this->diskCanHold($disk, $current['disk_gb'])) {
                continue;
            }

            $price = $this->priceInLocation($candidate, $current['region']);
            // No price row for this location means the type is not sold there,
            // so it is not a legal target for this server.
            if ($current['region'] !== null && $price === null) {
                continue;
            }

            $options[] = $this->option(
                $slug,
                self::int($candidate['cores'] ?? null) ?? 0,
                self::gbToMb($candidate['memory'] ?? null) ?? 0,
                $disk,
                $price,
                $current['disk_gb'],
                $current['memory_mb'] ?? 0,
            );
        }

        return ['current' => $current, 'options' => $this->sortOptions($options)];
    }

    public function execute(Server $server, array $target, callable $progress): void
    {
        $hetzner = new HetznerService($server->providerCredential);
        $id = (int) $server->provider_id;

        $progress('powering_off');
        $off = $hetzner->powerOffServer($id);
        $hetzner->waitForAction((int) $off['id'], timeoutSeconds: 600);

        $progress('resizing');
        $change = $hetzner->changeServerType($id, $target['slug'], $target['grows_disk']);
        $hetzner->waitForAction((int) $change['id'], timeoutSeconds: 3000);

        $progress('powering_on');
        $on = $hetzner->powerOnServer($id);
        $hetzner->waitForAction((int) $on['id'], timeoutSeconds: 600);
    }

    /**
     * Monthly gross price for a type in one location, or null when the type is
     * not offered there.
     *
     * @param  array<string, mixed>  $type
     */
    private function priceInLocation(array $type, ?string $location): ?float
    {
        $prices = is_array($type['prices'] ?? null) ? $type['prices'] : [];

        foreach ($prices as $price) {
            if (! is_array($price)) {
                continue;
            }
            if ($location !== null && self::str($price['location'] ?? null) !== $location) {
                continue;
            }

            return self::float($price['price_monthly']['gross'] ?? $price['price_monthly']['net'] ?? null);
        }

        return null;
    }

    /** Hetzner reports memory in GB (float); everything downstream wants MB. */
    private static function gbToMb(mixed $gb): ?int
    {
        return is_numeric($gb) ? (int) round((float) $gb * 1024) : null;
    }
}
