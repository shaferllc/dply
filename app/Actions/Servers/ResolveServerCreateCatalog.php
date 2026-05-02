<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Services\AwsEc2Service;
use App\Services\DigitalOceanService;
use App\Services\EquinixMetalService;
use App\Services\FlyIoService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\ScalewayService;
use App\Services\UpCloudService;
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
     *     size_label: string
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
        if (in_array($type, ['digitalocean_functions', 'digitalocean_kubernetes', 'aws_lambda'], true)) {
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
                return $this->catalogDigitalOcean($credentials, null);
            }

            return array_merge($empty, ['credentials' => $credentials]);
        }

        return match ($type) {
            'digitalocean' => $this->catalogDigitalOcean($credentials, $credential),
            'hetzner' => $this->catalogHetzner($credentials, $credential),
            'linode' => $this->catalogLinode($credentials, $credential),
            'vultr' => $this->catalogVultr($credentials, $credential),
            'akamai' => $this->catalogAkamai($credentials, $credential),
            'scaleway' => $this->catalogScaleway($credentials, $credential, $selectedRegion),
            'upcloud' => $this->catalogUpcloud($credentials, $credential),
            'equinix_metal' => $this->catalogEquinixMetal($credentials, $credential),
            'aws' => $this->catalogAws($credentials, $credential),
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
    private function catalogDigitalOcean(Collection $credentials, ?ProviderCredential $credential): array
    {
        $regions = [];
        $sizes = [];
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

            usort($sizes, static function (array $a, array $b): int {
                return strnatcasecmp($a['value'], $b['value']);
            });
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
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return array{credentials: Collection<int, ProviderCredential>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string}>, region_label: string, size_label: string}
     */
    private function catalogHetzner(Collection $credentials, ProviderCredential $credential): array
    {
        $regions = [];
        $sizes = [];
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
                $monthly = $this->extractFloat($st, ['prices.0.price_monthly.gross', 'prices.0.price_monthly.net']);
                $hourly = $this->extractFloat($st, ['prices.0.price_hourly.gross', 'prices.0.price_hourly.net']);
                $sizes[] = [
                    'value' => $v,
                    'label' => $v.' — '.((int) ($st['memory'] ?? 0)).'GB / '.((int) ($st['cores'] ?? 0)).' vCPU'.$this->formatPriceSuffix($monthly, $hourly),
                    'price_monthly' => $monthly,
                    'price_hourly' => $hourly,
                    'pricing_source' => ($monthly !== null || $hourly !== null) ? 'provider_catalog' : null,
                    'memory_mb' => ((int) ($st['memory'] ?? 0)) > 0 ? ((int) ($st['memory'] ?? 0)) * 1024 : null,
                    'vcpus' => ((int) ($st['cores'] ?? 0)) > 0 ? (int) ($st['cores'] ?? 0) : null,
                    'disk_gb' => null,
                ];
            }
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
    private function catalogLinodeApi(Collection $credentials, ProviderCredential $credential, string $regionLabel, string $sizeLabel): array
    {
        $regions = [];
        $sizes = [];
        try {
            $svc = new LinodeService($credential);
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
    private function catalogVultr(Collection $credentials, ProviderCredential $credential): array
    {
        $regions = [];
        $sizes = [];
        try {
            $svc = new VultrService($credential);
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
