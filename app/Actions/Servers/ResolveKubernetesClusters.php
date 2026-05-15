<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Models\ProviderCredential;
use App\Services\DigitalOceanService;
use Throwable;

/**
 * Lists managed Kubernetes clusters available to the given credential, normalised
 * into a flat shape the wizard's cluster picker can render directly. Returns an
 * empty list when the credential isn't K8s-capable, the API call fails, or the
 * account has no clusters — the picker handles all three the same way.
 *
 * Currently DO-only; AWS EKS lands in a follow-up.
 */
final class ResolveKubernetesClusters
{
    use AsObject;

    /**
     * @return list<array{id: string, name: string, region: string}>
     */
    public function handle(ProviderCredential $credential): array
    {
        if ($credential->provider !== 'digitalocean') {
            return [];
        }

        try {
            $service = new DigitalOceanService($credential);
            $clusters = $service->getKubernetesClusters();
        } catch (Throwable) {
            // Network / auth failures should not break the wizard render.
            // Caller sees an empty list and the "no clusters available" panel.
            return [];
        }

        $out = [];
        foreach ($clusters as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $id = (string) ($cluster['id'] ?? '');
            $name = (string) ($cluster['name'] ?? '');
            $region = (string) ($cluster['region'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $name, 'region' => $region];
        }

        return $out;
    }
}
