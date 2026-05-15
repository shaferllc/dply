<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Models\ProviderCredential;
use App\Services\AwsEksService;
use App\Services\DigitalOceanService;
use Throwable;

/**
 * Lists managed Kubernetes clusters available to the given credential, normalised
 * into a flat shape the wizard's cluster picker can render directly. Returns an
 * empty list when the credential isn't K8s-capable, the API call fails, or the
 * account has no clusters — the picker handles all three the same way.
 */
final class ResolveKubernetesClusters
{
    use AsObject;

    /**
     * @return list<array{id: string, name: string, region: string}>
     */
    public function handle(ProviderCredential $credential): array
    {
        return match ($credential->provider) {
            'digitalocean' => $this->resolveDigitalOcean($credential),
            'aws' => $this->resolveAws($credential),
            default => [],
        };
    }

    /**
     * @return list<array{id: string, name: string, region: string}>
     */
    private function resolveDigitalOcean(ProviderCredential $credential): array
    {
        try {
            $clusters = (new DigitalOceanService($credential))->getKubernetesClusters();
        } catch (Throwable) {
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

    /**
     * EKS only returns names from listClusters; the credential's configured
     * region is the only one we query (multi-region scan is out of scope —
     * the user picks region-by-credential in the wizard).
     *
     * @return list<array{id: string, name: string, region: string}>
     */
    private function resolveAws(ProviderCredential $credential): array
    {
        try {
            $service = new AwsEksService($credential);
            $names = $service->listClusterNames();
        } catch (Throwable) {
            return [];
        }

        $region = (string) ($credential->credentials['region'] ?? config('services.aws.default_region', 'us-east-1'));

        $out = [];
        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }
            // EKS clusters don't have a separate id — name is the canonical
            // identifier across the API. Mirror the DO shape by using name
            // for both id and name fields.
            $out[] = ['id' => $name, 'name' => $name, 'region' => $region];
        }

        return $out;
    }
}
