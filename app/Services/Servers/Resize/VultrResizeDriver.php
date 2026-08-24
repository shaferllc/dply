<?php

declare(strict_types=1);

namespace App\Services\Servers\Resize;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Modules\Providers\Services\VultrService;
use App\Services\Servers\Resize\Concerns\BuildsResizeOptions;

/**
 * Vultr instances. The odd one out: a plan change is a PATCH on the instance,
 * Vultr reboots it itself, and there is no action object to poll — completion
 * is observed by watching the instance's own status back to running.
 *
 * There is also no CPU/RAM-only mode. A plan change always brings that plan's
 * disk, so every upgrade to a bigger plan grows the disk permanently and
 * `grows_disk` falls out of the same comparison the other drivers use.
 */
class VultrResizeDriver implements ServerResizeDriver
{
    use BuildsResizeOptions;

    public function supports(Server $server): bool
    {
        return $server->provider === ServerProvider::Vultr
            && $server->providerCredential !== null
            && filled($server->provider_id);
    }

    /**
     * Vultr reboots the instance rather than requiring a stop/start, so the
     * outage is real but much shorter than a full power cycle.
     */
    public function requiresPowerCycle(): bool
    {
        return false;
    }

    public function catalog(Server $server): array
    {
        $vultr = new VultrService($server->providerCredential);

        $instance = $vultr->getInstance((string) $server->provider_id);

        $current = [
            'slug' => self::str($instance['plan'] ?? null),
            'vcpus' => self::int($instance['vcpu_count'] ?? null),
            'memory_mb' => self::int($instance['ram'] ?? null),
            'disk_gb' => self::int($instance['disk'] ?? null),
            'region' => self::str($instance['region'] ?? null),
        ];

        $options = [];
        foreach ($vultr->getPlans() as $plan) {
            $slug = self::str($plan['id'] ?? null);
            if ($slug === null || $slug === $current['slug']) {
                continue;
            }

            // Vultr sells plans per-location; `locations` is the authoritative
            // list and a plan missing the instance's region cannot be applied.
            $locations = is_array($plan['locations'] ?? null) ? $plan['locations'] : [];
            if ($current['region'] !== null && $locations !== [] && ! in_array($current['region'], $locations, true)) {
                continue;
            }

            $disk = self::int($plan['disk'] ?? null);
            if (! $this->diskCanHold($disk, $current['disk_gb'])) {
                continue;
            }

            $options[] = $this->option(
                $slug,
                self::int($plan['vcpu_count'] ?? null) ?? 0,
                self::int($plan['ram'] ?? null) ?? 0,
                $disk,
                self::float($plan['monthly_cost'] ?? null),
                $current['disk_gb'],
                $current['memory_mb'] ?? 0,
            );
        }

        return ['current' => $current, 'options' => $this->sortOptions($options)];
    }

    public function execute(Server $server, array $target, callable $progress): void
    {
        $vultr = new VultrService($server->providerCredential);
        $id = (string) $server->provider_id;

        $progress('resizing');
        $vultr->resizeInstance($id, $target['slug']);

        // The PATCH returns immediately; the instance drops out of `running`
        // while Vultr applies the plan. Wait for it to come back rather than
        // reporting success on an accepted request.
        $progress('powering_on');
        $vultr->waitForInstanceActive($id, timeoutSeconds: 3000);
    }
}
