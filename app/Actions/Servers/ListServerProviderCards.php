<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Actions\Servers\Support\ServerProviderTypeMap;
use App\Enums\ServerProvider;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Support\ServerProviderGate;

/**
 * Provider picker cards for the server create UI (id, label, credentials linked).
 */
final class ListServerProviderCards
{
    use AsObject;

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     linked: bool,
     *     server_count: int,
     *     site_count: int,
     *     installed_roles: list<array{id: string, label: string, count: int}>,
     *     installed_locations: list<array{region: string, label: string, count: int}>
     * }>
     */
    public function handle(?Organization $org): array
    {
        $grouped = $org
            ? ProviderCredential::query()->where('organization_id', $org->id)->get()->groupBy('provider')
            : collect();

        $serverCounts = $this->serverCountsByProvider($org);
        $siteCounts = $this->siteCountsByProvider($org);
        $existingServers = ListExistingProviderServers::make();
        $installedRoles = $existingServers->rolesByProvider($org);
        $installedLocations = $existingServers->locationsByProvider($org);

        $cards = [];
        foreach ($this->definitions() as $def) {
            if (! ServerProviderGate::enabled($def['id'])) {
                continue;
            }
            $pkey = ServerProviderTypeMap::toCredentialProvider($def['id']);
            $countKey = $pkey ?? $def['id'];
            $cards[] = [
                'id' => $def['id'],
                'label' => $def['label'],
                'linked' => $def['id'] === 'custom' || ($pkey !== null && $grouped->has($pkey)),
                'server_count' => (int) ($serverCounts[$countKey] ?? 0),
                'site_count' => (int) ($siteCounts[$countKey] ?? 0),
                'installed_roles' => $installedRoles[$countKey] ?? [],
                'installed_locations' => $installedLocations[$countKey] ?? [],
            ];
        }

        return $cards;
    }

    /**
     * @return array<string, int>
     */
    private function serverCountsByProvider(?Organization $org): array
    {
        if (! $org) {
            return [];
        }

        return Server::query()
            ->where('organization_id', $org->id)
            ->whereNotNull('provider')
            ->groupBy('provider')
            ->selectRaw('provider, COUNT(*) as aggregate')
            ->pluck('aggregate', 'provider')
            ->mapWithKeys(fn (int|string $count, mixed $provider): array => [
                $this->normalizeProviderKey($provider) => (int) $count,
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function siteCountsByProvider(?Organization $org): array
    {
        if (! $org) {
            return [];
        }

        return Site::query()
            ->where('sites.organization_id', $org->id)
            ->whereNotNull('sites.server_id')
            ->join('servers', 'sites.server_id', '=', 'servers.id')
            ->whereNotNull('servers.provider')
            ->groupBy('servers.provider')
            ->selectRaw('servers.provider, COUNT(sites.id) as aggregate')
            ->pluck('aggregate', 'provider')
            ->mapWithKeys(fn (int|string $count, mixed $provider): array => [
                $this->normalizeProviderKey($provider) => (int) $count,
            ])
            ->all();
    }

    private function normalizeProviderKey(mixed $provider): string
    {
        if ($provider instanceof ServerProvider) {
            return $provider->value;
        }

        return (string) $provider;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function definitions(): array
    {
        return [
            ['id' => 'digitalocean', 'label' => 'DigitalOcean'],
            ['id' => 'digitalocean_functions', 'label' => 'DigitalOcean Functions'],
            ['id' => 'digitalocean_kubernetes', 'label' => 'DigitalOcean Kubernetes'],
            ['id' => 'hetzner', 'label' => 'Hetzner Cloud'],
            ['id' => 'vultr', 'label' => 'Vultr'],
            ['id' => 'linode', 'label' => 'Linode'],
            ['id' => 'akamai', 'label' => 'Akamai'],
            ['id' => 'scaleway', 'label' => 'Scaleway'],
            ['id' => 'upcloud', 'label' => 'UpCloud'],
            ['id' => 'equinix_metal', 'label' => 'Equinix Metal'],
            ['id' => 'fly_io', 'label' => 'Fly.io'],
            ['id' => 'aws', 'label' => 'Amazon EC2'],
            ['id' => 'gcp', 'label' => 'Google Cloud'],
            ['id' => 'azure', 'label' => 'Azure'],
            ['id' => 'oracle', 'label' => 'Oracle Cloud'],
            ['id' => 'aws_lambda', 'label' => 'AWS Lambda'],
            ['id' => 'custom', 'label' => __('Custom server')],
        ];
    }
}
