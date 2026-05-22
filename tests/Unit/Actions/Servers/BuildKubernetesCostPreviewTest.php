<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Servers\BuildKubernetesCostPreviewTest;
use App\Actions\Servers\BuildServerCreatePreflight;
use App\Livewire\Forms\ServerCreateForm;
test('doks zero clusters with no sizes falls back to unavailable placeholder', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'digitalocean_kubernetes';
    $form->do_kubernetes_cluster_name = '';

    $preview = runPreflight($form, catalogWithClusters([], []));

    expect($preview['state'])->toBe('unavailable');
    expect($preview['formatted_price'])->toBeNull();

    // Copy points at the Create New toggle since the credential is fine,
    // they just have no clusters yet.
    $this->assertStringContainsString('Create new', $preview['detail']);
});
test('doks zero clusters with sizes shows starter sample estimate', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'digitalocean_kubernetes';
    $form->do_kubernetes_cluster_name = '';

    $catalog = catalogWithClusters(clusters: [], sizes: [
        ['value' => 's-1vcpu-1gb', 'price_monthly' => 6.0, 'memory_mb' => 1024],
        ['value' => 's-2vcpu-2gb', 'price_monthly' => 18.0, 'memory_mb' => 2048],
        ['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0, 'memory_mb' => 4096],
    ]);

    $preview = runPreflight($form, $catalog);

    // 2 × s-2vcpu-2gb = $36 (skips the 1GB option, which is too small for K8s).
    expect($preview['state'])->toBe('available');
    expect($preview['price_monthly'])->toBe(36.0);
    $this->assertStringContainsString('sample', (string) $preview['formatted_price']);
    $this->assertStringContainsString('Create new', $preview['detail']);
});
test('doks aggregate estimate when one cluster available', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'digitalocean_kubernetes';
    $form->do_kubernetes_cluster_name = '';

    $catalog = catalogWithClusters(clusters: [[
        'id' => 'a',
        'name' => 'prod',
        'region' => 'nyc3',
        'ha' => false,
        'node_pools' => [
            ['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 2],
        ],
    ]], sizes: [['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0]]);

    $preview = runPreflight($form, $catalog);

    expect($preview['state'])->toBe('available');
    expect($preview['price_monthly'])->toBe(48.0);
    expect($preview['formatted_price'])->toBe('$48/mo');
    expect($preview['size'])->toBe('prod');
});
test('doks aggregate estimate renders range across multiple clusters', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'digitalocean_kubernetes';
    $form->do_kubernetes_cluster_name = '';

    $catalog = catalogWithClusters(clusters: [
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
    ], sizes: [
        ['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0],
        ['value' => 's-4vcpu-8gb', 'price_monthly' => 48.0],
    ]);

    $preview = runPreflight($form, $catalog);

    expect($preview['state'])->toBe('available');

    // prod = 3*48 + 40 (HA) = 184; staging = 1*24 = 24
    expect($preview['formatted_price'])->toBe('$24 – $184/mo');
    expect($preview['price_monthly'])->toBeNull();
    $this->assertStringContainsString('2 clusters', (string) $preview['size']);
    expect($preview['extras'])->toHaveCount(2);
});
test('doks with unknown cluster name returns unavailable with refresh copy', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'digitalocean_kubernetes';
    $form->do_kubernetes_cluster_name = 'ghost-cluster';

    $catalog = catalogWithClusters(clusters: [['id' => 'a', 'name' => 'prod', 'region' => 'nyc3', 'ha' => false, 'node_pools' => []]], sizes: []);

    $preview = runPreflight($form, $catalog);

    expect($preview['state'])->toBe('unavailable');
    $this->assertStringContainsString('not found', $preview['detail']);
});
test('doks single node pool sums count times droplet monthly', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'digitalocean_kubernetes';
    $form->do_kubernetes_cluster_name = 'prod';

    $catalog = catalogWithClusters(clusters: [[
        'id' => 'a',
        'name' => 'prod',
        'region' => 'nyc3',
        'ha' => false,
        'node_pools' => [
            ['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 3],
        ],
    ]], sizes: [
        ['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0],
    ]);

    $preview = runPreflight($form, $catalog);

    expect($preview['state'])->toBe('available');
    expect($preview['price_monthly'])->toBe(72.0);
    expect($preview['region'])->toBe('nyc3');
    expect($preview['size'])->toBe('prod');

    // Two extras: the pool + the (free) control-plane line.
    expect($preview['extras'])->toHaveCount(2);
    expect($preview['extras'][0]['amount'])->toBe(72.0);
    expect($preview['extras'][1]['amount'])->toBe(0.0);
});
test('doks multiple node pools sum independently', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'digitalocean_kubernetes';
    $form->do_kubernetes_cluster_name = 'prod';

    $catalog = catalogWithClusters(clusters: [[
        'id' => 'a',
        'name' => 'prod',
        'region' => 'nyc3',
        'ha' => false,
        'node_pools' => [
            ['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 3],
            ['name' => 'jobs', 'size' => 's-4vcpu-8gb', 'count' => 2],
        ],
    ]], sizes: [
        ['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0],
        ['value' => 's-4vcpu-8gb', 'price_monthly' => 48.0],
    ]);

    $preview = runPreflight($form, $catalog);

    expect($preview['state'])->toBe('available');

    // 3*24 + 2*48 = 72 + 96 = 168
    expect($preview['price_monthly'])->toBe(168.0);
});
test('doks ha cluster adds forty dollar control plane fee', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'digitalocean_kubernetes';
    $form->do_kubernetes_cluster_name = 'prod';

    $catalog = catalogWithClusters(clusters: [[
        'id' => 'a',
        'name' => 'prod',
        'region' => 'nyc3',
        'ha' => true,
        'node_pools' => [
            ['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 1],
        ],
    ]], sizes: [
        ['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0],
    ]);

    $preview = runPreflight($form, $catalog);

    // 1*24 (node) + 40 (HA control plane) = 64
    expect($preview['price_monthly'])->toBe(64.0);
    $haExtra = $preview['extras'][1];
    expect($haExtra['amount'])->toBe(40.0);
    expect($haExtra['label'])->toBe('HA control plane');
});
test('doks pool with uncatalogued size marks estimate partial', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'digitalocean_kubernetes';
    $form->do_kubernetes_cluster_name = 'prod';

    $catalog = catalogWithClusters(clusters: [[
        'id' => 'a',
        'name' => 'prod',
        'region' => 'nyc3',
        'ha' => false,
        'node_pools' => [
            ['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 3],
            ['name' => 'gpu', 'size' => 'gpu-h100x1', 'count' => 1],
        ],
    ]], sizes: [
        ['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0],
        // gpu-h100x1 deliberately missing
    ]);

    $preview = runPreflight($form, $catalog);

    expect($preview['state'])->toBe('available');
    expect($preview['price_monthly'])->toBe(72.0);
    $this->assertStringContainsString('partial', (string) $preview['formatted_price']);
    $this->assertStringContainsString('lower bound', $preview['detail']);
});
test('doks create new estimates from form node pool spec', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'digitalocean_kubernetes';
    $form->do_kubernetes_source = 'new';
    $form->do_kubernetes_new_name = 'fresh-cluster';
    $form->do_kubernetes_new_region = 'nyc3';
    $form->do_kubernetes_new_node_size = 's-2vcpu-4gb';
    $form->do_kubernetes_new_node_count = 3;
    $form->do_kubernetes_new_ha = false;

    $catalog = catalogWithClusters(clusters: [], sizes: [['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0]]);

    $preview = runPreflight($form, $catalog);

    expect($preview['state'])->toBe('available');
    expect($preview['price_monthly'])->toBe(72.0);
    expect($preview['size'])->toBe('fresh-cluster');
    expect($preview['region'])->toBe('nyc3');
});
test('doks create new with ha includes forty dollar fee', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'digitalocean_kubernetes';
    $form->do_kubernetes_source = 'new';
    $form->do_kubernetes_new_name = 'fresh-ha';
    $form->do_kubernetes_new_region = 'nyc3';
    $form->do_kubernetes_new_node_size = 's-2vcpu-4gb';
    $form->do_kubernetes_new_node_count = 1;
    $form->do_kubernetes_new_ha = true;

    $catalog = catalogWithClusters(clusters: [], sizes: [['value' => 's-2vcpu-4gb', 'price_monthly' => 24.0]]);

    $preview = runPreflight($form, $catalog);

    // 1*24 + 40 (HA) = 64
    expect($preview['price_monthly'])->toBe(64.0);
});
test('doks create new without size shows pick a size placeholder', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'digitalocean_kubernetes';
    $form->do_kubernetes_source = 'new';
    $form->do_kubernetes_new_name = 'fresh';
    $form->do_kubernetes_new_node_size = '';
    $form->do_kubernetes_new_node_count = 2;

    $preview = runPreflight($form, catalogWithClusters([], []));

    expect($preview['state'])->toBe('unavailable');
    $this->assertStringContainsString('node droplet size', $preview['detail']);
});
test('eks returns control plane starting estimate', function () {
    $form = new ServerCreateForm(makeComponent(), 'form');
    $form->type = 'aws_kubernetes';
    $form->do_kubernetes_cluster_name = 'prod-eks';

    $preview = runPreflight($form, catalogWithClusters([], []));

    expect($preview['state'])->toBe('available');
    expect($preview['price_monthly'])->toBe(73.0);
    $this->assertStringContainsString('73', (string) $preview['formatted_price']);
    $this->assertStringContainsString('+', (string) $preview['formatted_price']);
    expect($preview['size'])->toBe('prod-eks');
});
/**
 * @param  list<array<string, mixed>>  $clusters
 * @param  list<array{value: string, price_monthly: float|int|null}>  $sizes
 * @return array<string, mixed>
 */
function catalogWithClusters(array $clusters, array $sizes): array
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
function runPreflight(ServerCreateForm $form, array $catalog): array
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
function makeComponent(): \Livewire\Component
{
    return new class extends \Livewire\Component
    {
        function render(): string
        {
            return '';
        }
    };
}
