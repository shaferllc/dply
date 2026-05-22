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
    public function handle(ProviderCredential $credential, ?string $regionOverride = null): array
    {
        return match ($credential->provider) {
            'digitalocean' => $this->resolveDigitalOcean($credential),
            'aws' => $this->resolveAws($credential, $regionOverride),
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
     * EKS only returns names from listClusters; if the wizard's region picker
     * passed an override, hit that region — otherwise fall back to the
     * credential's stored region. Cluster IDs in EKS are the cluster name
     * itself (no separate ARN-as-id is used in the picker UX).
     *
     * @return list<array{id: string, name: string, region: string}>
     */
    private function resolveAws(ProviderCredential $credential, ?string $regionOverride = null): array
    {
        $region = $regionOverride !== null && $regionOverride !== ''
            ? $regionOverride
            : (string) ($credential->credentials['region'] ?? config('services.aws.default_region', 'us-east-1'));

        try {
            $service = new AwsEksService($credential, $region);
            $names = $service->listClusterNames();
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }
            $out[] = ['id' => $name, 'name' => $name, 'region' => $region];
        }

        return $out;
    }
}
