<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Services\AwsEc2Service;
use App\Services\AzureComputeService;
use App\Services\DigitalOceanService;
use App\Services\EquinixMetalService;
use App\Services\FlyIoService;
use App\Services\GcpComputeService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\OracleComputeService;
use App\Services\ScalewayService;
use App\Services\UpCloudService;
use App\Services\OvhService;
use App\Services\VultrService;
use Illuminate\Support\Collection;

/**
 * Region/plan options for the server create wizard (API-backed per credential).
 */
final class ResolveServerCreateCatalog
{
    use AsObject;

    /**
     * @return array{
     *     credentials: Collection<int, ProviderCredential>,
     *     regions: list<array{value: string, label: string}>,
     *     sizes: list<array{value: string, label: string, price_monthly?: float|null, price_hourly?: float|null, pricing_source?: string|null, memory_mb?: int|null, vcpus?: int|null, disk_gb?: int|null}>,
     *     region_label: string,
     *     size_label: string,
     *     kubernetes_clusters?: list<array<string, mixed>>
     * }
     */
    public function handle(
        Organization $org,
        string $type,
        string $providerCredentialId,
        string $selectedRegion,
    ): array {
        $empty = [
            'credentials' => collect(),
            'regions' => [],
            'sizes' => [],
            'region_label' => __('Region'),
            'size_label' => __('Plan / size'),
        ];

        if ($type === 'custom') {
            return $empty;
        }

        $credentials = GetProviderCredentialsForServerType::run($org, $type);
        if (in_array($type, ['digitalocean_functions', 'aws_lambda'], true)) {
            return array_merge($empty, ['credentials' => $credentials]);
        }

        if ($type === 'fly_io') {
            return $this->catalogFlyIo($credentials);
        }

        $credential = ($providerCredentialId !== '' && $providerCredentialId !== '0')
            ? $credentials->firstWhere('id', $providerCredentialId)
            : null;

        if ($credentials->isNotEmpty() && $providerCredentialId !== '' && $providerCredentialId !== '0' && ! $credential) {
            return array_merge($empty, ['credentials' => $credentials]);
        }

        if (! $credential) {
            if ($type === 'digitalocean' && filled((string) config('services.digitalocean.token'))) {
                return $this->catalogDigitalOcean($credentials, null, $selectedRegion);
            }

            if ($type === 'vultr' && filled((string) config('services.vultr.token'))) {
                return $this->catalogVultr($credentials, null, $selectedRegion);
            }

            if (in_array($type, ['linode', 'akamai'], true) && filled((string) config('services.linode.token'))) {
                return $this->catalogLinodeApi($credentials, null, __('Region'), __('Plan / type'));
            }

            return array_merge($empty, ['credentials' => $credentials]);
        }

        return match ($type) {
            'digitalocean' => $this->catalogDigitalOcean($credentials, $credential, $selectedRegion),
            'digitalocean_kubernetes' => $this->catalogDigitalOceanKubernetes($credentials, $credential),
            'hetzner' => $this->catalogHetzner($credentials, $credential, $selectedRegion),
            'linode' => $this->catalogLinode($credentials, $credential),
            'vultr' => $this->catalogVultr($credentials, $credential, $selectedRegion),
            'akamai' => $this->catalogAkamai($credentials, $credential),
            'scaleway' => $this->catalogScaleway($credentials, $credential, $selectedRegion),
            'ovh' => $this->catalogOvh($credentials, $credential, $selectedRegion),
            'upcloud' => $this->catalogUpcloud($credentials, $credential),
            'equinix_metal' => $this->catalogEquinixMetal($credentials, $credential),
            'aws' => $this->catalogAws($credentials, $credential),
            'gcp' => $this->catalogGcp($credentials, $credential, $selectedRegion),
            'azure' => $this->catalogAzure($credentials, $credential, $selectedRegion),
            'oracle' => $this->catalogOracle($credentials, $credential),
            default => array_merge($empty, ['credentials' => $credentials]),
        };
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string}>, region_label: string, size_label: string}
     */
    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     */
    private function catalogDigitalOcean(Collection $credentials, ?ProviderCredential $credential, string $selectedRegion = ''): array
    {
        $regions = [];
        $sizes = [];
        $selectedRegion = trim($selectedRegion);
        try {
            $token = config('services.digitalocean.token');
            $do = match (true) {
                $credential !== null => new DigitalOceanService($credential),
                filled((string) $token) => new DigitalOceanService((string) $token),
                default => throw new \RuntimeException('No DigitalOcean token for catalog.'),
            };

            foreach ($do->getRegions() as $r) {
                if (array_key_exists('available', $r) && $r['available'] === false) {
                    continue;
                }
                $v = (string) ($r['slug'] ?? $r['id'] ?? '');
                if ($v === '') {
                    continue;
                }
                $regions[] = [
                    'value' => $v,
                    'label' => ($r['name'] ?? $r['slug'] ?? $v).' ('.$v.')',
                ];
            }

            usort($regions, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

            foreach ($do->getSizes() as $s) {
                if (array_key_exists('available', $s) && $s['available'] === false) {
                    continue;
                }
                $v = (string) ($s['slug'] ?? $s['id'] ?? '');
                if ($v === '') {
                    continue;
                }

                // DO publishes per-size region availability — filter so the
                // picker never offers a combo that the create-droplet API
                // will reject with "Size is not available in this region."
                $sizeRegions = is_array($s['regions'] ?? null) ? array_map('strval', $s['regions']) : [];
                if ($selectedRegion !== '' && $sizeRegions !== [] && ! in_array($selectedRegion, $sizeRegions, true)) {
                    continue;
                }

                $memMb = (int) ($s['memory'] ?? 0);
                $vcpus = (int) ($s['vcpus'] ?? 0);
                $diskGb = (int) ($s['disk'] ?? 0);
                $monthly = $this->extractFloat($s, ['price_monthly']);
                $hourly = $this->extractFloat($s, ['price_hourly']);
                $priceSuffix = $this->formatPriceSuffix($monthly, $hourly);
                $spec = $memMb >= 1024
                    ? sprintf('%dGB', (int) round($memMb / 1024))
                    : $memMb.'MB';
                $spec .= ' / '.$vcpus.' '.__('vCPU');
                if ($diskGb > 0) {
                    $spec .= ' / '.$diskGb.'GB '.__('disk');
                }

                $sizes[] = [
                    'value' => $v,
                    'label' => $v.' — '.$spec.$priceSuffix,
                    'price_monthly' => $monthly,
                    'price_hourly' => $hourly,
                    'pricing_source' => ($monthly !== null || $hourly !== null) ? 'provider_catalog' : null,
                    'memory_mb' => $memMb > 0 ? $memMb : null,
                    'vcpus' => $vcpus > 0 ? $vcpus : null,
                    'disk_gb' => $diskGb > 0 ? $diskGb : null,
                ];
            }

            $this->sortSizesByPriceAscending($sizes);
        } catch (\Throwable) {
            //
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Region'),
            'size_label' => __('Droplet size'),
        ];
    }

    /**
     * DOKS catalog: we don't expose region/size pickers (the user picks an
     * existing cluster), but we DO want droplet pricing in the catalog so
     * buildCostPreview can sum node_pool sizes into a monthly estimate. The
     * cluster list comes back with node_pools inline — no second API call.
     *
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, price_monthly?: float|null, price_hourly?: float|null, pricing_source?: string|null, memory_mb?: int|null, vcpus?: int|null, disk_gb?: int|null}>, region_label: string, size_label: string, kubernetes_clusters: list<array<string, mixed>>}
     */
    private function catalogDigitalOceanKubernetes(Collection $credentials, ProviderCredential $credential): array
    {
        $clusters = [];
        $sizes = [];
        $regions = [];
        $kubernetesVersions = [];
        try {
            $do = new DigitalOceanService($credential);

            $rawClusters = $do->getKubernetesClusters();
            foreach ($rawClusters as $cluster) {
                if (! is_array($cluster)) {
                    continue;
                }
                $clusters[] = $cluster;
            }

            // /kubernetes/options publishes the *DOKS-eligible* subset of
            // regions, sizes, and versions. Not every droplet/region from the
            // top-level /sizes /regions catalogs is valid for a node pool;
            // showing the full list lets the user pick a slug DO will reject
            // ("invalid droplet size") on create. We use options as the
            // allow-list and pull the rich spec/pricing from the full catalog.
            $options = $do->getKubernetesOptions();
            $allowedSizeSlugs = [];
            foreach ((array) ($options['sizes'] ?? []) as $sizeOption) {
                if (is_array($sizeOption) && ($slug = (string) ($sizeOption['slug'] ?? '')) !== '') {
                    $allowedSizeSlugs[$slug] = true;
                }
            }
            $allowedRegionSlugs = [];
            foreach ((array) ($options['regions'] ?? []) as $regionOption) {
                if (is_array($regionOption) && ($slug = (string) ($regionOption['slug'] ?? '')) !== '') {
                    $allowedRegionSlugs[$slug] = true;
                }
            }

            foreach ($do->getRegions() as $r) {
                if (array_key_exists('available', $r) && $r['available'] === false) {
                    continue;
                }
                $v = (string) ($r['slug'] ?? $r['id'] ?? '');
                if ($v === '') {
                    continue;
                }
                // Filter against the DOKS allow-list when present; if the
                // options endpoint returned nothing (older accounts / API
                // hiccup), fall back to the full list rather than wiping
                // the picker.
                if ($allowedRegionSlugs !== [] && ! isset($allowedRegionSlugs[$v])) {
                    continue;
                }
                $regions[] = [
                    'value' => $v,
                    'label' => ($r['name'] ?? $r['slug'] ?? $v).' ('.$v.')',
                ];
            }
            usort($regions, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

            foreach ($do->getSizes() as $s) {
                if (array_key_exists('available', $s) && $s['available'] === false) {
                    continue;
                }
                $v = (string) ($s['slug'] ?? $s['id'] ?? '');
                if ($v === '') {
                    continue;
                }
                if ($allowedSizeSlugs !== [] && ! isset($allowedSizeSlugs[$v])) {
                    continue;
                }
                $monthly = $this->extractFloat($s, ['price_monthly']);
                $memMb = (int) ($s['memory'] ?? 0);
                $vcpus = (int) ($s['vcpus'] ?? 0);
                $diskGb = (int) ($s['disk'] ?? 0);
                $spec = $memMb >= 1024 ? sprintf('%dGB', (int) round($memMb / 1024)) : $memMb.'MB';
                $spec .= ' / '.$vcpus.' '.__('vCPU');
                $label = $v.' — '.$spec.($monthly !== null ? ' ($'.number_format($monthly, $monthly < 10 ? 2 : 0).'/mo)' : '');
                $sizes[] = [
                    'value' => $v,
                    'label' => $label,
                    'price_monthly' => $monthly,
                    'price_hourly' => $this->extractFloat($s, ['price_hourly']),
                    'pricing_source' => 'provider_catalog',
                    'memory_mb' => $memMb > 0 ? $memMb : null,
                    'vcpus' => $vcpus > 0 ? $vcpus : null,
                    'disk_gb' => $diskGb > 0 ? $diskGb : null,
                ];
            }
            $this->sortSizesByPriceAscending($sizes);

            // DOKS-specific: published K8s versions (one flagged as default).
            $rawVersions = is_array($options['versions'] ?? null) ? $options['versions'] : [];
            foreach ($rawVersions as $version) {
                if (! is_array($version)) {
                    continue;
                }
                $slug = (string) ($version['slug'] ?? '');
                $kubeVer = (string) ($version['kubernetes_version'] ?? $slug);
                if ($slug === '') {
                    continue;
                }
                $kubernetesVersions[] = [
                    'value' => $slug,
                    'label' => 'v'.$kubeVer,
                ];
            }
        } catch (\Throwable) {
            //
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Region'),
            'size_label' => __('Droplet size'),
            'kubernetes_clusters' => $clusters,
            'kubernetes_versions' => $kubernetesVersions,
        ];
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string}>, region_label: string, size_label: string}
     */
    private function catalogHetzner(Collection $credentials, ProviderCredential $credential, string $selectedRegion = ''): array
    {
        $regions = [];
        $sizes = [];
        $selectedRegion = trim($selectedRegion);
        try {
            $svc = new HetznerService($credential);
            foreach ($svc->getLocations() as $loc) {
                $v = (string) ($loc['name'] ?? $loc['id'] ?? '');
                if ($v === '') {
                    continue;
                }
                $regions[] = [
                    'value' => $v,
                    'label' => ($loc['description'] ?? $loc['name'] ?? $v).' ('.$v.')',
                ];
            }
            foreach ($svc->getServerTypes() as $st) {
                $v = (string) ($st['name'] ?? '');
                if ($v === '') {
                    continue;
                }

                // Hetzner publishes per-server-type location availability
                // inline on /server_types via the `prices` array. Use that
                // as the availability matrix so the picker never offers a
                // (location, server_type) combo the create API will reject
                // with "unsupported location for server type" — e.g. CX
                // series in US locations like hil/ash, which only carry
                // the CPX/CCX lines.
                $availableLocations = [];
                foreach ((array) ($st['prices'] ?? []) as $price) {
                    if (! is_array($price)) {
                        continue;
                    }
                    $loc = (string) ($price['location'] ?? '');
                    if ($loc !== '') {
                        $availableLocations[$loc] = true;
                    }
                }
                $availableLocations = array_keys($availableLocations);

                if ($selectedRegion !== '' && $availableLocations !== [] && ! in_array($selectedRegion, $availableLocations, true)) {
                    continue;
                }

                $memGb = (int) ($st['memory'] ?? 0);
                $cores = (int) ($st['cores'] ?? 0);
                $diskGb = (int) ($st['disk'] ?? 0);
                $monthly = $this->priceForLocation($st, $selectedRegion, 'price_monthly')
                    ?? $this->extractFloat($st, ['prices.0.price_monthly.gross', 'prices.0.price_monthly.net']);
                $hourly = $this->priceForLocation($st, $selectedRegion, 'price_hourly')
                    ?? $this->extractFloat($st, ['prices.0.price_hourly.gross', 'prices.0.price_hourly.net']);
                $spec = $memGb.'GB / '.$cores.' '.__('vCPU');
                if ($diskGb > 0) {
                    $spec .= ' / '.$diskGb.'GB '.__('disk');
                }
                $sizes[] = [
                    'value' => $v,
                    'label' => $v.' — '.$spec.$this->formatPriceSuffix($monthly, $hourly),
                    'price_monthly' => $monthly,
                    'price_hourly' => $hourly,
                    'pricing_source' => ($monthly !== null || $hourly !== null) ? 'provider_catalog' : null,
                    'memory_mb' => $memGb > 0 ? $memGb * 1024 : null,
                    'vcpus' => $cores > 0 ? $cores : null,
                    'disk_gb' => $diskGb > 0 ? $diskGb : null,
                    'available_in_regions' => $availableLocations,
                ];
            }
            $this->sortSizesByPriceAscending($sizes);
        } catch (\Throwable) {
            //
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Location'),
            'size_label' => __('Server type'),
        ];
    }

    /**
     * Pull a per-location price from a Hetzner server_type payload. Falls
     * back to null when the requested location is missing — callers then
     * use prices.0.* as the catalog-default price.
     */
    private function priceForLocation(array $serverType, string $location, string $key): ?float
    {
        if ($location === '') {
            return null;
        }

        foreach ((array) ($serverType['prices'] ?? []) as $price) {
            if (! is_array($price)) {
                continue;
            }
            if ((string) ($price['location'] ?? '') !== $location) {
                continue;
            }
            $entry = $price[$key] ?? null;
            if (! is_array($entry)) {
                continue;
            }
            foreach (['gross', 'net'] as $field) {
                $raw = $entry[$field] ?? null;
                if (is_numeric($raw)) {
                    return (float) $raw;
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string}>, region_label: string, size_label: string}
     */
    private function catalogLinode(Collection $credentials, ProviderCredential $credential): array
    {
        return $this->catalogLinodeApi($credentials, $credential, __('Region'), __('Plan / type'));
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string}>, region_label: string, size_label: string}
     */
    private function catalogAkamai(Collection $credentials, ProviderCredential $credential): array
    {
        return $this->catalogLinodeApi($credentials, $credential, __('Region'), __('Plan / type'));
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string}>, region_label: string, size_label: string}
     */
    private function catalogLinodeApi(Collection $credentials, ?ProviderCredential $credential, string $regionLabel, string $sizeLabel): array
    {
        $regions = [];
        $sizes = [];
        try {
            $token = config('services.linode.token');
            $svc = match (true) {
                $credential !== null => new LinodeService($credential),
                filled((string) $token) => new LinodeService((string) $token),
                default => throw new \RuntimeException('No Linode token for catalog.'),
            };
            foreach ($svc->getRegions() as $reg) {
                $v = (string) ($reg['id'] ?? '');
                if ($v === '') {
                    continue;
                }
                $regions[] = [
                    'value' => $v,
                    'label' => ($reg['label'] ?? $v).' ('.$v.')',
                ];
            }
            foreach ($svc->getTypes() as $t) {
                $v = (string) ($t['id'] ?? '');
                if ($v === '') {
                    continue;
                }
                $memGb = ((int) ($t['memory'] ?? 0)) / 1024;
                $monthly = $this->extractFloat($t, ['price.monthly', 'monthly']);
                $hourly = $this->extractFloat($t, ['price.hourly', 'hourly']);
                $sizes[] = [
                    'value' => $v,
                    'label' => ($t['label'] ?? $v).' — '.$memGb.'GB / '.((int) ($t['vcpus'] ?? 0)).' vCPU'.$this->formatPriceSuffix($monthly, $hourly),
                    'price_monthly' => $monthly,
                    'price_hourly' => $hourly,
                    'pricing_source' => ($monthly !== null || $hourly !== null) ? 'provider_catalog' : null,
                    'memory_mb' => ((int) ($t['memory'] ?? 0)) > 0 ? (int) ($t['memory'] ?? 0) : null,
                    'vcpus' => ((int) ($t['vcpus'] ?? 0)) > 0 ? (int) ($t['vcpus'] ?? 0) : null,
                    'disk_gb' => ((int) ($t['disk'] ?? 0)) > 0 ? (int) ($t['disk'] ?? 0) : null,
                ];
            }
            $this->sortSizesByPriceAscending($sizes);
        } catch (\Throwable) {
            //
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => $regionLabel,
            'size_label' => $sizeLabel,
        ];
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string}>, region_label: string, size_label: string}
     */
    private function catalogVultr(Collection $credentials, ?ProviderCredential $credential, string $selectedRegion = ''): array
    {
        $regions = [];
        $sizes = [];
        $selectedRegion = trim($selectedRegion);
        try {
            $token = config('services.vultr.token');
            $svc = match (true) {
                $credential !== null => new VultrService($credential),
                filled((string) $token) => new VultrService((string) $token),
                default => throw new \RuntimeException('No Vultr token for catalog.'),
            };
            foreach ($svc->getRegions() as $reg) {
                $v = (string) ($reg['id'] ?? '');
                if ($v === '') {
                    continue;
                }
                $regions[] = [
                    'value' => $v,
                    'label' => ($reg['city'] ?? $v).' ('.$v.')',
                ];
            }
            foreach ($svc->getPlans() as $p) {
                $v = (string) ($p['id'] ?? '');
                if ($v === '') {
                    continue;
                }
                $planRegions = is_array($p['locations'] ?? null) ? array_map('strval', $p['locations']) : [];
                if ($selectedRegion !== '' && $planRegions !== [] && ! in_array($selectedRegion, $planRegions, true)) {
                    continue;
                }
                $monthly = $this->extractFloat($p, ['monthly_cost', 'price_monthly', 'month']);
                $hourly = $this->extractFloat($p, ['hourly_cost', 'price_hourly', 'hour']);
                $sizes[] = [
                    'value' => $v,
                    'label' => $v.' — '.((int) ($p['ram'] ?? 0)).'MB / '.((int) ($p['vcpu_count'] ?? 0)).' vCPU'.$this->formatPriceSuffix($monthly, $hourly),
                    'price_monthly' => $monthly,
                    'price_hourly' => $hourly,
                    'pricing_source' => ($monthly !== null || $hourly !== null) ? 'provider_catalog' : null,
                    'memory_mb' => ((int) ($p['ram'] ?? 0)) > 0 ? (int) ($p['ram'] ?? 0) : null,
                    'vcpus' => ((int) ($p['vcpu_count'] ?? 0)) > 0 ? (int) ($p['vcpu_count'] ?? 0) : null,
                    'disk_gb' => ((int) ($p['disk'] ?? 0)) > 0 ? (int) ($p['disk'] ?? 0) : null,
                ];
            }
            $this->sortSizesByPriceAscending($sizes);
        } catch (\Throwable) {
            //
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Region'),
            'size_label' => __('Plan'),
        ];
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string}>, region_label: string, size_label: string}
     */
    private function catalogScaleway(Collection $credentials, ProviderCredential $credential, string $selectedRegion): array
    {
        $regions = [];
        $sizes = [];
        try {
            $svc = new ScalewayService($credential);
            $zones = $svc->getZones();
            foreach ($zones as $z) {
                $v = (string) ($z['id'] ?? '');
                if ($v === '') {
                    continue;
                }
                $regions[] = [
                    'value' => $v,
                    'label' => ($z['name'] ?? $v).' ('.$v.')',
                ];
            }
            $valid = collect($zones)->contains(fn ($z) => ($z['id'] ?? '') === $selectedRegion);
            if ($valid && $selectedRegion !== '') {
                foreach ($svc->getServerTypes($selectedRegion) as $t) {
                    $v = (string) ($t['name'] ?? $t['id'] ?? '');
                    if ($v === '') {
                        continue;
                    }
                    $sizes[] = [
                        'value' => $v,
                        'label' => $v,
                        'memory_mb' => null,
                        'vcpus' => $this->extractInt($t, ['ncpus', 'cpus']),
                        'disk_gb' => null,
                    ];
                }
            }
        } catch (\Throwable) {
            //
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Zone'),
            'size_label' => __('Instance type'),
        ];
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     */
    private function catalogOvh(Collection $credentials, ProviderCredential $credential, string $selectedRegion): array
    {
        $regions = [];
        $sizes = [];
        try {
            $svc = new OvhService($credential);
            $project = $svc->projectId();

            foreach ($svc->getRegions($project) as $r) {
                $regions[] = ['value' => $r, 'label' => $r];
            }

            if ($selectedRegion !== '' && in_array($selectedRegion, array_column($regions, 'value'), true)) {
                foreach ($svc->getFlavors($project, $selectedRegion) as $f) {
                    if (! is_array($f)) {
                        continue;
                    }
                    if (($f['osType'] ?? 'linux') !== 'linux' || ($f['available'] ?? true) === false) {
                        continue;
                    }
                    $v = (string) ($f['id'] ?? '');
                    if ($v === '') {
                        continue;
                    }
                    $ram = $this->extractInt($f, ['ram']);
                    $sizes[] = [
                        'value' => $v,
                        'label' => (string) ($f['name'] ?? $v),
                        'memory_mb' => $ram,
                        'vcpus' => $this->extractInt($f, ['vcpus']),
                        'disk_gb' => $this->extractInt($f, ['disk']),
                    ];
                }
            }
        } catch (\Throwable) {
            //
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Region'),
            'size_label' => __('Flavor'),
        ];
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string}>, region_label: string, size_label: string}
     */
    private function catalogUpcloud(Collection $credentials, ProviderCredential $credential): array
    {
        $regions = [];
        $sizes = [];
        try {
            $svc = new UpCloudService($credential);
            foreach ($svc->getZones() as $z) {
                $v = (string) ($z['id'] ?? '');
                if ($v === '') {
                    continue;
                }
                $regions[] = [
                    'value' => $v,
                    'label' => ($z['description'] ?? $v).' ('.$v.')',
                ];
            }
            foreach ($svc->getPlans() as $p) {
                $v = (string) ($p['name'] ?? '');
                if ($v === '') {
                    continue;
                }
                $monthly = $this->extractFloat($p, ['price', 'price_monthly']);
                $sizes[] = [
                    'value' => $v,
                    'label' => $v.' — '.((int) ($p['core_number'] ?? 0)).' CPU / '.((int) ($p['memory_amount'] ?? 0)).'MB'.$this->formatPriceSuffix($monthly, null),
                    'price_monthly' => $monthly,
                    'price_hourly' => null,
                    'pricing_source' => $monthly !== null ? 'provider_catalog' : null,
                    'memory_mb' => ((int) ($p['memory_amount'] ?? 0)) > 0 ? (int) ($p['memory_amount'] ?? 0) : null,
                    'vcpus' => ((int) ($p['core_number'] ?? 0)) > 0 ? (int) ($p['core_number'] ?? 0) : null,
                    'disk_gb' => ((int) ($p['storage_size'] ?? 0)) > 0 ? (int) ($p['storage_size'] ?? 0) : null,
                ];
            }
            $this->sortSizesByPriceAscending($sizes);
        } catch (\Throwable) {
            //
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Zone'),
            'size_label' => __('Plan'),
        ];
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string}>, region_label: string, size_label: string}
     */
    private function catalogEquinixMetal(Collection $credentials, ProviderCredential $credential): array
    {
        $regions = [];
        $sizes = [];
        try {
            $svc = new EquinixMetalService($credential);
            foreach ($svc->getMetros() as $m) {
                $v = (string) ($m['code'] ?? $m['id'] ?? '');
                if ($v === '') {
                    continue;
                }
                $regions[] = [
                    'value' => $v,
                    'label' => ($m['name'] ?? $v).' ('.$v.')',
                ];
            }
            foreach ($svc->getPlans() as $p) {
                $v = (string) ($p['slug'] ?? $p['id'] ?? '');
                if ($v === '') {
                    continue;
                }
                $monthly = $this->extractFloat($p, ['pricing.monthly', 'price_monthly']);
                $hourly = $this->extractFloat($p, ['pricing.hourly', 'price_hourly']);
                $sizes[] = [
                    'value' => $v,
                    'label' => (string) ($p['name'] ?? $v).$this->formatPriceSuffix($monthly, $hourly),
                    'price_monthly' => $monthly,
                    'price_hourly' => $hourly,
                    'pricing_source' => ($monthly !== null || $hourly !== null) ? 'provider_catalog' : null,
                    'memory_mb' => ((int) ($p['specs']['memory']['total'] ?? 0)) > 0 ? ((int) ($p['specs']['memory']['total'] ?? 0)) : null,
                    'vcpus' => ((int) ($p['specs']['cpus'][0]['count'] ?? 0)) > 0 ? ((int) ($p['specs']['cpus'][0]['count'] ?? 0)) : null,
                    'disk_gb' => null,
                ];
            }
            $this->sortSizesByPriceAscending($sizes);
        } catch (\Throwable) {
            //
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Metro'),
            'size_label' => __('Plan'),
        ];
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string}>, region_label: string, size_label: string}
     */
    private function catalogFlyIo(Collection $credentials): array
    {
        $regions = [];
        $sizes = [];
        foreach (FlyIoService::getRegions() as $r) {
            $v = (string) ($r['id'] ?? '');
            if ($v === '') {
                continue;
            }
            $regions[] = [
                'value' => $v,
                'label' => ($r['name'] ?? $v).' ('.$v.')',
            ];
        }
        foreach (FlyIoService::getVmSizes() as $s) {
            $v = (string) ($s['id'] ?? '');
            if ($v === '') {
                continue;
            }
            $sizes[] = [
                'value' => $v,
                'label' => (string) ($s['name'] ?? $v),
                'memory_mb' => match ($v) {
                    'shared-cpu-1x' => 256,
                    'shared-cpu-2x' => 512,
                    'shared-cpu-4x' => 1024,
                    'performance-1x' => 2048,
                    'performance-2x' => 4096,
                    'performance-4x' => 8192,
                    default => null,
                },
                'vcpus' => match ($v) {
                    'shared-cpu-1x', 'performance-1x' => 1,
                    'shared-cpu-2x', 'performance-2x' => 2,
                    'shared-cpu-4x', 'performance-4x' => 4,
                    default => null,
                },
                'disk_gb' => null,
            ];
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Region'),
            'size_label' => __('VM size'),
        ];
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string}>, region_label: string, size_label: string}
     */
    private function catalogAws(Collection $credentials, ProviderCredential $credential): array
    {
        $regions = [];
        $sizes = [];
        $awsRegions = AwsEc2Service::getDefaultRegions();
        try {
            $fetched = (new AwsEc2Service($credential))->getRegions();
            if ($fetched !== []) {
                $awsRegions = $fetched;
            }
        } catch (\Throwable) {
            //
        }
        foreach ($awsRegions as $r) {
            $v = (string) ($r['id'] ?? '');
            if ($v === '') {
                continue;
            }
            $regions[] = [
                'value' => $v,
                'label' => (string) ($r['name'] ?? $v),
            ];
        }
        foreach (AwsEc2Service::getInstanceTypes() as $s) {
            $v = (string) ($s['id'] ?? '');
            if ($v === '') {
                continue;
            }
            $sizes[] = [
                'value' => $v,
                'label' => (string) ($s['name'] ?? $v),
                'memory_mb' => $this->awsMemoryForInstanceType($v),
                'vcpus' => $this->awsVcpusForInstanceType($v),
                'disk_gb' => null,
            ];
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Region'),
            'size_label' => __('Instance type'),
        ];
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, memory_mb?: int|null, vcpus?: int|null, disk_gb?: int|null}>, region_label: string, size_label: string}
     */
    private function catalogOracle(Collection $credentials, ProviderCredential $credential): array
    {
        $regions = [];
        foreach (OracleComputeService::defaultRegions() as $region) {
            $id = (string) ($region['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $regions[] = [
                'value' => $id,
                'label' => (string) ($region['name'] ?? $id),
            ];
        }

        $sizes = [];
        try {
            $service = new OracleComputeService($credential);
            $availabilityDomains = $service->listAvailabilityDomains();
            $firstAd = (string) ($availabilityDomains[0]['name'] ?? $availabilityDomains[0]['id'] ?? '');
            foreach ($service->listShapes($firstAd !== '' ? $firstAd : null) as $shape) {
                $id = (string) ($shape['shape'] ?? '');
                if ($id === '') {
                    continue;
                }

                $memoryGb = $this->extractFloat($shape, ['memoryInGBs']);
                $ocpus = $this->extractFloat($shape, ['ocpus']);
                $label = $id;
                if ($ocpus !== null || $memoryGb !== null) {
                    $label .= ' — ';
                    if ($ocpus !== null) {
                        $label .= rtrim(rtrim((string) $ocpus, '0'), '.').' OCPU';
                    }
                    if ($memoryGb !== null) {
                        if ($ocpus !== null) {
                            $label .= ' / ';
                        }
                        $label .= rtrim(rtrim((string) $memoryGb, '0'), '.').' GB';
                    }
                }

                $sizes[] = [
                    'value' => $id,
                    'label' => $label,
                    'memory_mb' => $memoryGb !== null ? (int) round($memoryGb * 1024) : null,
                    'vcpus' => $ocpus !== null ? (int) round($ocpus) : null,
                    'disk_gb' => null,
                ];
            }
        } catch (\Throwable) {
            foreach (OracleComputeService::defaultShapes() as $shape) {
                $id = (string) ($shape['shape'] ?? '');
                if ($id === '') {
                    continue;
                }

                $memoryGb = isset($shape['memoryInGBs']) ? (float) $shape['memoryInGBs'] : null;
                $ocpus = isset($shape['ocpus']) ? (float) $shape['ocpus'] : null;
                $sizes[] = [
                    'value' => $id,
                    'label' => $id,
                    'memory_mb' => $memoryGb !== null ? (int) round($memoryGb * 1024) : null,
                    'vcpus' => $ocpus !== null ? (int) round($ocpus) : null,
                    'disk_gb' => null,
                ];
            }
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Region'),
            'size_label' => __('Shape'),
        ];
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, memory_mb?: int|null, vcpus?: int|null, disk_gb?: int|null}>, region_label: string, size_label: string}
     */
    private function catalogGcp(Collection $credentials, ProviderCredential $credential, string $selectedRegion = ''): array
    {
        $regions = [];
        $sizes = [];

        $service = new GcpComputeService($credential);

        foreach ($service->getZones() as $zone) {
            $id = (string) ($zone['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $regions[] = [
                'value' => $id,
                'label' => (string) ($zone['name'] ?? $id),
            ];
        }

        $selectedZone = trim($selectedRegion) !== ''
            ? trim($selectedRegion)
            : (string) ($regions[0]['value'] ?? config('services.gcp.default_zone', 'us-central1-a'));
        foreach ($service->getMachineTypes($selectedZone) as $size) {
            $id = (string) ($size['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $memoryMb = is_numeric($size['memory_mb'] ?? null) ? (int) $size['memory_mb'] : null;
            $vcpus = is_numeric($size['vcpus'] ?? null) ? (int) $size['vcpus'] : null;
            $label = (string) ($size['name'] ?? $id);
            if ($memoryMb !== null && $vcpus !== null) {
                $label .= sprintf(' (%d vCPU, %dMB RAM)', $vcpus, $memoryMb);
            }

            $sizes[] = [
                'value' => $id,
                'label' => $label,
                'memory_mb' => $memoryMb,
                'vcpus' => $vcpus,
                'disk_gb' => null,
            ];
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Zone'),
            'size_label' => __('Machine type'),
        ];
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, memory_mb?: int|null, vcpus?: int|null, disk_gb?: int|null}>, region_label: string, size_label: string}
     */
    private function catalogAzure(Collection $credentials, ProviderCredential $credential, string $selectedRegion = ''): array
    {
        $regions = [];
        $sizes = [];

        try {
            $service = new AzureComputeService($credential);
            foreach ($service->listLocations() as $region) {
                $id = (string) ($region['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $regions[] = [
                    'value' => $id,
                    'label' => (string) ($region['name'] ?? $id),
                ];
            }
        } catch (\Throwable) {
            foreach (AzureComputeService::defaultLocations() as $region) {
                $regions[] = [
                    'value' => (string) $region['id'],
                    'label' => (string) $region['name'],
                ];
            }
        }

        try {
            $service = isset($service) ? $service : new AzureComputeService($credential);
            $regionForSizes = trim($selectedRegion) !== '' ? trim($selectedRegion) : (string) ($regions[0]['value'] ?? '');
            foreach ($service->listVmSizes($regionForSizes) as $size) {
                $id = (string) ($size['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $memoryMb = is_numeric($size['memory_mb'] ?? null) ? (int) $size['memory_mb'] : null;
                $vcpus = is_numeric($size['vcpus'] ?? null) ? (int) $size['vcpus'] : null;
                $sizes[] = [
                    'value' => $id,
                    'label' => (string) ($size['name'] ?? $id),
                    'memory_mb' => $memoryMb,
                    'vcpus' => $vcpus,
                    'disk_gb' => null,
                ];
            }
        } catch (\Throwable) {
            foreach (AzureComputeService::defaultVmSizes() as $size) {
                $sizes[] = [
                    'value' => (string) $size['id'],
                    'label' => (string) $size['name'],
                    'memory_mb' => (int) ($size['memory_mb'] ?? 0) ?: null,
                    'vcpus' => (int) ($size['vcpus'] ?? 0) ?: null,
                    'disk_gb' => null,
                ];
            }
        }

        return [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'region_label' => __('Region'),
            'size_label' => __('VM size'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $paths
     */
    private function extractFloat(array $row, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = data_get($row, $path);
            if (is_numeric($value)) {
                return round((float) $value, 4);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $paths
     */
    private function extractInt(array $row, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($row, $path);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function formatPriceSuffix(?float $monthly, ?float $hourly): string
    {
        if ($monthly !== null && $monthly > 0) {
            return ' — $'.number_format($monthly, $monthly < 10 ? 2 : 0).'/'.__('mo');
        }

        if ($hourly !== null && $hourly > 0) {
            return ' — $'.number_format($hourly, 4).'/'.__('hr');
        }

        return '';
    }

    /**
     * Sort size options by monthly price ascending so the wizard defaults
     * to the cheapest plan. Sizes without pricing sink to the bottom; ties
     * break alphabetically by SKU for stability.
     *
     * @param  list<array<string, mixed>>  $sizes
     */
    private function sortSizesByPriceAscending(array &$sizes): void
    {
        usort($sizes, static function (array $a, array $b): int {
            $priceA = $a['price_monthly'] ?? null;
            $priceB = $b['price_monthly'] ?? null;

            if ($priceA === null && $priceB === null) {
                return strnatcasecmp((string) $a['value'], (string) $b['value']);
            }
            if ($priceA === null) {
                return 1;
            }
            if ($priceB === null) {
                return -1;
            }
            if ($priceA === $priceB) {
                return strnatcasecmp((string) $a['value'], (string) $b['value']);
            }

            return $priceA <=> $priceB;
        });
    }

    private function awsMemoryForInstanceType(string $instanceType): ?int
    {
        return match ($instanceType) {
            't3.micro', 't2.micro' => 1024,
            't3.small', 't2.small' => 2048,
            't3.medium', 't2.medium' => 4096,
            't3.large' => 8192,
            't3.xlarge' => 16384,
            default => null,
        };
    }

    private function awsVcpusForInstanceType(string $instanceType): ?int
    {
        return match ($instanceType) {
            't3.micro', 't2.micro' => 1,
            't3.small', 't3.medium', 't3.large', 't2.medium' => 2,
            't3.xlarge' => 4,
            't2.small' => 1,
            default => null,
        };
    }
}
