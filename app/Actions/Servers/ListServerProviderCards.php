<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Actions\Servers\Support\ServerProviderTypeMap;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Support\ServerProviderGate;

/**
 * Provider picker cards for the server create UI (id, label, credentials linked).
 */
final class ListServerProviderCards
{
    use AsObject;

    /**
     * @return list<array{id: string, label: string, linked: bool}>
     */
    public function handle(?Organization $org): array
    {
        $grouped = $org
            ? ProviderCredential::query()->where('organization_id', $org->id)->get()->groupBy('provider')
            : collect();

        $cards = [];
        foreach ($this->definitions() as $def) {
            if (! ServerProviderGate::enabled($def['id'])) {
                continue;
            }
            $pkey = ServerProviderTypeMap::toCredentialProvider($def['id']);
            $cards[] = [
                'id' => $def['id'],
                'label' => $def['label'],
                'linked' => $def['id'] === 'custom' || ($pkey !== null && $grouped->has($pkey)),
            ];
        }

        return $cards;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function definitions(): array
    {
        return [
            ['id' => 'digitalocean', 'label' => 'DigitalOcean'],
            ['id' => 'hetzner', 'label' => 'Hetzner Cloud'],
            ['id' => 'vultr', 'label' => 'Vultr'],
            ['id' => 'linode', 'label' => 'Linode'],
            ['id' => 'akamai', 'label' => 'Akamai'],
            ['id' => 'scaleway', 'label' => 'Scaleway'],
            ['id' => 'upcloud', 'label' => 'UpCloud'],
            ['id' => 'equinix_metal', 'label' => 'Equinix Metal'],
            ['id' => 'fly_io', 'label' => 'Fly.io'],
            ['id' => 'aws', 'label' => 'Amazon EC2'],
            ['id' => 'custom', 'label' => __('Custom server')],
        ];
    }
}
