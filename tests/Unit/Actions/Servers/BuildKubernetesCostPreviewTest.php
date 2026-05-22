<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Servers;

use App\Actions\Servers\BuildServerCreatePreflight;
use App\Livewire\Forms\ServerCreateForm;
use Tests\TestCase;

/**
 * Covers buildCostPreview() for the managed Kubernetes types. The K8s branch
 * sums node-pool droplets from the pre-fetched cluster list (DigitalOcean) or
 * returns just the control-plane fee (AWS EKS), instead of the old blanket
 * "Unavailable" placeholder.
 */
final class BuildKubernetesCostPreviewTest extends TestCase
{
    public function test_doks_zero_clusters_with_no_sizes_falls_back_to_unavailable_placeholder(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'digitalocean_kubernetes';
        $form->do_kubernetes_cluster_name = '';

        $preview = $this->runPreflight($form, $this->catalogWithClusters([], []));

        $this->assertSame('unavailable', $preview['state']);
        $this->assertNull($preview['formatted_price']);
        // Copy points at the Create New toggle since the credential is fine,
        // they just have no clusters yet.
        $this->assertStringContainsString('Create new', $preview['detail']);
    }

    public function test_doks_zero_clusters_with_sizes_shows_starter_sample_estimate(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'digitalocean_kubernetes';
        $form->do_kubernetes_cluster_name = '';

        $catalog = $this->catalogWithClusters(
            clusters: [],
            sizes: [
                ['value' => 's-1vcpu-1gb', 'price_monthly' => 6.0, 'memory_mb' => 1024],
                ['value' => 's-2vcpu-2gb', 'price_monthly' => 18.0, 'memory_mb' => 2048],
                ['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0, 'memory_mb' => 4096],
            ],
        );

        $preview = $this->runPreflight($form, $catalog);

        // 2 × s-2vcpu-2gb = $36 (skips the 1GB option, which is too small for K8s).
        $this->assertSame('available', $preview['state']);
        $this->assertSame(36.0, $preview['price_monthly']);
        $this->assertStringContainsString('sample', (string) $preview['formatted_price']);
        $this->assertStringContainsString('Create new', $preview['detail']);
    }

    public function test_doks_aggregate_estimate_when_one_cluster_available(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'digitalocean_kubernetes';
        $form->do_kubernetes_cluster_name = '';

        $catalog = $this->catalogWithClusters(
            clusters: [[
                'id' => 'a',
                'name' => 'prod',
                'region' => 'nyc3',
                'ha' => false,
                'node_pools' => [
                    ['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 2],
                ],
            ]],
            sizes: [['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0]],
        );

        $preview = $this->runPreflight($form, $catalog);

        $this->assertSame('available', $preview['state']);
        $this->assertSame(48.0, $preview['price_monthly']);
        $this->assertSame('$48/mo', $preview['formatted_price']);
        $this->assertSame('prod', $preview['size']);
    }

    public function test_doks_aggregate_estimate_renders_range_across_multiple_clusters(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'digitalocean_kubernetes';
        $form->do_kubernetes_cluster_name = '';

        $catalog = $this->catalogWithClusters(
            clusters: [
                [
                    'id' => 'a',
                    'name' => 'prod',
                    'region' => 'nyc3',
                    'ha' => true,
                    'node_pools' => [
                        ['name' => 'workers', 'size' => 's-4vcpu-8gb', 'count' => 3],
                    ],
                ],
                [
                    'id' => 'b',
                    'name' => 'staging',
                    'region' => 'sfo3',
                    'ha' => false,
                    'node_pools' => [
                        ['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 1],
                    ],
                ],
            ],
            sizes: [
                ['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0],
                ['value' => 's-4vcpu-8gb', 'price_monthly' => 48.0],
            ],
        );

        $preview = $this->runPreflight($form, $catalog);

        $this->assertSame('available', $preview['state']);
        // prod = 3*48 + 40 (HA) = 184; staging = 1*24 = 24
        $this->assertSame('$24 – $184/mo', $preview['formatted_price']);
        $this->assertNull($preview['price_monthly']);
        $this->assertStringContainsString('2 clusters', (string) $preview['size']);
        $this->assertCount(2, $preview['extras']);
    }

    public function test_doks_with_unknown_cluster_name_returns_unavailable_with_refresh_copy(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'digitalocean_kubernetes';
        $form->do_kubernetes_cluster_name = 'ghost-cluster';

        $catalog = $this->catalogWithClusters(
            clusters: [['id' => 'a', 'name' => 'prod', 'region' => 'nyc3', 'ha' => false, 'node_pools' => []]],
            sizes: [],
        );

        $preview = $this->runPreflight($form, $catalog);

        $this->assertSame('unavailable', $preview['state']);
        $this->assertStringContainsString('not found', $preview['detail']);
    }

    public function test_doks_single_node_pool_sums_count_times_droplet_monthly(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'digitalocean_kubernetes';
        $form->do_kubernetes_cluster_name = 'prod';

        $catalog = $this->catalogWithClusters(
            clusters: [[
                'id' => 'a',
                'name' => 'prod',
                'region' => 'nyc3',
                'ha' => false,
                'node_pools' => [
                    ['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 3],
                ],
            ]],
            sizes: [
                ['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0],
            ],
        );

        $preview = $this->runPreflight($form, $catalog);

        $this->assertSame('available', $preview['state']);
        $this->assertSame(72.0, $preview['price_monthly']);
        $this->assertSame('nyc3', $preview['region']);
        $this->assertSame('prod', $preview['size']);
        // Two extras: the pool + the (free) control-plane line.
        $this->assertCount(2, $preview['extras']);
        $this->assertSame(72.0, $preview['extras'][0]['amount']);
        $this->assertSame(0.0, $preview['extras'][1]['amount']);
    }

    public function test_doks_multiple_node_pools_sum_independently(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'digitalocean_kubernetes';
        $form->do_kubernetes_cluster_name = 'prod';

        $catalog = $this->catalogWithClusters(
            clusters: [[
                'id' => 'a',
                'name' => 'prod',
                'region' => 'nyc3',
                'ha' => false,
                'node_pools' => [
                    ['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 3],
                    ['name' => 'jobs', 'size' => 's-4vcpu-8gb', 'count' => 2],
                ],
            ]],
            sizes: [
                ['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0],
                ['value' => 's-4vcpu-8gb', 'price_monthly' => 48.0],
            ],
        );

        $preview = $this->runPreflight($form, $catalog);

        $this->assertSame('available', $preview['state']);
        // 3*24 + 2*48 = 72 + 96 = 168
        $this->assertSame(168.0, $preview['price_monthly']);
    }

    public function test_doks_ha_cluster_adds_forty_dollar_control_plane_fee(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'digitalocean_kubernetes';
        $form->do_kubernetes_cluster_name = 'prod';

        $catalog = $this->catalogWithClusters(
            clusters: [[
                'id' => 'a',
                'name' => 'prod',
                'region' => 'nyc3',
                'ha' => true,
                'node_pools' => [
                    ['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 1],
                ],
            ]],
            sizes: [
                ['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0],
            ],
        );

        $preview = $this->runPreflight($form, $catalog);

        // 1*24 (node) + 40 (HA control plane) = 64
        $this->assertSame(64.0, $preview['price_monthly']);
        $haExtra = $preview['extras'][1];
        $this->assertSame(40.0, $haExtra['amount']);
        $this->assertSame('HA control plane', $haExtra['label']);
    }

    public function test_doks_pool_with_uncatalogued_size_marks_estimate_partial(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'digitalocean_kubernetes';
        $form->do_kubernetes_cluster_name = 'prod';

        $catalog = $this->catalogWithClusters(
            clusters: [[
                'id' => 'a',
                'name' => 'prod',
                'region' => 'nyc3',
                'ha' => false,
                'node_pools' => [
                    ['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 3],
                    ['name' => 'gpu', 'size' => 'gpu-h100x1', 'count' => 1],
                ],
            ]],
            sizes: [
                ['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0],
                // gpu-h100x1 deliberately missing
            ],
        );

        $preview = $this->runPreflight($form, $catalog);

        $this->assertSame('available', $preview['state']);
        $this->assertSame(72.0, $preview['price_monthly']);
        $this->assertStringContainsString('partial', (string) $preview['formatted_price']);
        $this->assertStringContainsString('lower bound', $preview['detail']);
    }

    public function test_doks_create_new_estimates_from_form_node_pool_spec(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'digitalocean_kubernetes';
        $form->do_kubernetes_source = 'new';
        $form->do_kubernetes_new_name = 'fresh-cluster';
        $form->do_kubernetes_new_region = 'nyc3';
        $form->do_kubernetes_new_node_size = 's-2vcpu-4gb';
        $form->do_kubernetes_new_node_count = 3;
        $form->do_kubernetes_new_ha = false;

        $catalog = $this->catalogWithClusters(
            clusters: [],
            sizes: [['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0]],
        );

        $preview = $this->runPreflight($form, $catalog);

        $this->assertSame('available', $preview['state']);
        $this->assertSame(72.0, $preview['price_monthly']);
        $this->assertSame('fresh-cluster', $preview['size']);
        $this->assertSame('nyc3', $preview['region']);
    }

    public function test_doks_create_new_with_ha_includes_forty_dollar_fee(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'digitalocean_kubernetes';
        $form->do_kubernetes_source = 'new';
        $form->do_kubernetes_new_name = 'fresh-ha';
        $form->do_kubernetes_new_region = 'nyc3';
        $form->do_kubernetes_new_node_size = 's-2vcpu-4gb';
        $form->do_kubernetes_new_node_count = 1;
        $form->do_kubernetes_new_ha = true;

        $catalog = $this->catalogWithClusters(
            clusters: [],
            sizes: [['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0]],
        );

        $preview = $this->runPreflight($form, $catalog);

        // 1*24 + 40 (HA) = 64
        $this->assertSame(64.0, $preview['price_monthly']);
    }

    public function test_doks_create_new_without_size_shows_pick_a_size_placeholder(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'digitalocean_kubernetes';
        $form->do_kubernetes_source = 'new';
        $form->do_kubernetes_new_name = 'fresh';
        $form->do_kubernetes_new_node_size = '';
        $form->do_kubernetes_new_node_count = 2;

        $preview = $this->runPreflight($form, $this->catalogWithClusters([], []));

        $this->assertSame('unavailable', $preview['state']);
        $this->assertStringContainsString('node droplet size', $preview['detail']);
    }

    public function test_eks_returns_control_plane_starting_estimate(): void
    {
        $form = new ServerCreateForm($this->makeComponent(), 'form');
        $form->type = 'aws_kubernetes';
        $form->do_kubernetes_cluster_name = 'prod-eks';

        $preview = $this->runPreflight($form, $this->catalogWithClusters([], []));

        $this->assertSame('available', $preview['state']);
        $this->assertSame(73.0, $preview['price_monthly']);
        $this->assertStringContainsString('73', (string) $preview['formatted_price']);
        $this->assertStringContainsString('+', (string) $preview['formatted_price']);
        $this->assertSame('prod-eks', $preview['size']);
    }

    /**
     * @param  list<array<string, mixed>>  $clusters
     * @param  list<array{value: string, price_monthly: float|int|null}>  $sizes
     * @return array<string, mixed>
     */
    private function catalogWithClusters(array $clusters, array $sizes): array
    {
        return [
            'credentials' => collect(),
            'regions' => [],
            'sizes' => array_map(fn (array $s) => array_merge([
                'label' => (string) $s['value'],
                'price_hourly' => null,
                'pricing_source' => 'provider_catalog',
                'memory_mb' => null,
                'vcpus' => null,
                'disk_gb' => null,
            ], $s), $sizes),
            'region_label' => 'Region',
            'size_label' => 'Droplet size',
            'kubernetes_clusters' => $clusters,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runPreflight(ServerCreateForm $form, array $catalog): array
    {
        $provisionOptions = [
            'server_roles' => [],
            'cache_services' => [],
            'webservers' => [],
            'php_versions' => [],
            'databases' => [],
        ];

        $result = BuildServerCreatePreflight::run(
            $form,
            $catalog,
            $provisionOptions,
            true,
            true,
            true,
            true,
            true,
        );

        return $result['cost_preview'];
    }

    /**
     * Livewire forms need a Component reference to instantiate; for these
     * pure-data tests we just need *something* that satisfies the constructor.
     */
    private function makeComponent(): \Livewire\Component
    {
        return new class extends \Livewire\Component
        {
            public function render(): string
            {
                return '';
            }
        };
    }
}
