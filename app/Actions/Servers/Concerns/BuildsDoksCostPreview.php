<?php

declare(strict_types=1);

namespace App\Actions\Servers\Concerns;

use App\Livewire\Forms\ServerCreateForm;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsDoksCostPreview
{


    /**
     * Sums the picked DOKS cluster's node-pool droplets (count × monthly droplet
     * price from the DO sizes catalog) and adds DO's HA control plane fee when
     * the cluster has `ha: true`. LBs / storage / bandwidth are listed in notes
     * because they're usage-based and we can't predict them at create time.
     *
     * When the user hasn't picked a specific cluster yet but the catalog already
     * lists clusters from their account, we collapse the per-cluster estimates
     * into a range so the StepWhere sidebar isn't just a dead "Unavailable".
     *
     * @param  array<string, mixed>  $catalog
     * @return array{state: string, provider: string, region: ?string, size: ?string, price_monthly: ?float, price_hourly: ?float, formatted_price: ?string, source: ?string, detail: string, extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>, notes: list<string>}
     */
    private function buildDoksCostPreview(ServerCreateForm $form, array $catalog): array
    {
        $clusterName = $form->do_kubernetes_cluster_name;
        $clusters = is_array($catalog['kubernetes_clusters'] ?? null) ? $catalog['kubernetes_clusters'] : [];
        $priceBySlug = $this->buildDoksPriceBySlugMap($catalog);

        if ($form->do_kubernetes_source === 'new') {
            return $this->buildDoksNewClusterCostPreview($form, $priceBySlug);
        }

        if ($clusterName === '' && $clusters === []) {
            return $this->buildDoksStarterSamplePreview($form, $priceBySlug, $catalog);
        }

        if ($clusterName === '') {
            return $this->buildDoksAggregateCostPreview($form, $clusters, $priceBySlug);
        }

        $cluster = null;
        foreach ($clusters as $candidate) {
            if (is_array($candidate) && (string) ($candidate['name'] ?? '') === $clusterName) {
                $cluster = $candidate;
                break;
            }
        }

        if ($cluster === null) {
            return [
                'state' => 'unavailable',
                'provider' => $form->type,
                'region' => null,
                'size' => null,
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => null,
                'detail' => __('Selected cluster was not found in your DigitalOcean account. Re-pick a cluster to refresh the estimate.'),
                'extras' => [],
                'notes' => [],
            ];
        }

        $cost = $this->computeDoksClusterCost($cluster, $priceBySlug);
        $extras = $cost['extras'];

        if ($cost['is_ha']) {
            $extras[] = [
                'label' => __('HA control plane'),
                'state' => 'included',
                'detail' => __('DigitalOcean charges $40/mo for the highly-available control plane.'),
                'amount' => 40.0,
                'amount_period' => 'monthly',
            ];
        } else {
            $extras[] = [
                'label' => __('Control plane'),
                'state' => 'included',
                'detail' => __('Free for standard (non-HA) DOKS clusters.'),
                'amount' => 0.0,
                'amount_period' => 'monthly',
            ];
        }

        $total = round($cost['total'] + ($cost['is_ha'] ? 40.0 : 0.0), 2);
        $hasUnknownPrice = $cost['has_unknown'];
        $formatted = '$'.number_format($total, $total < 10 ? 2 : 0).'/'.__('mo');

        return [
            'state' => 'available',
            'provider' => $form->type,
            'region' => (string) ($cluster['region'] ?? '') !== '' ? (string) $cluster['region'] : null,
            'size' => $clusterName,
            'price_monthly' => $total,
            'price_hourly' => null,
            'formatted_price' => $hasUnknownPrice ? $formatted.' '.__('(partial)') : $formatted,
            'source' => 'provider_catalog',
            'detail' => $hasUnknownPrice
                ? __('Estimate sums node-pool droplets at DigitalOcean catalog prices. One or more pool sizes were not in the catalog — total is a lower bound.')
                : __('Estimate sums node-pool droplets at DigitalOcean catalog prices plus the control-plane fee.'),
            'extras' => $extras,
            'notes' => [
                __('Load balancers ($12/mo each), block storage, snapshots, and bandwidth overages are billed separately by usage.'),
            ],
        ];
    }

    /**
     * EKS estimate is intentionally partial: AWS charges a flat $73/mo for the
     * control plane on top of node-group EC2 instances and load balancers, both
     * of which need additional API plumbing (DescribeNodegroup + EC2 pricing).
     * We show the control-plane line + a note so the user isn't staring at
     * "Unavailable" with no signal.
     *
     * @return array{state: string, provider: string, region: ?string, size: ?string, price_monthly: ?float, price_hourly: ?float, formatted_price: ?string, source: ?string, detail: string, extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>, notes: list<string>}
     */
    private function buildEksCostPreview(ServerCreateForm $form): array
    {
        $clusterName = $form->do_kubernetes_cluster_name;

        return [
            'state' => 'available',
            'provider' => $form->type,
            'region' => null,
            'size' => $clusterName !== '' ? $clusterName : null,
            'price_monthly' => 73.0,
            'price_hourly' => null,
            'formatted_price' => '$73/'.__('mo').'+',
            'source' => 'aws_published',
            'detail' => __('Starts at $73/mo for the EKS control plane. Node-group EC2 instances and load balancers are billed separately and not summed here.'),
            'extras' => [
                [
                    'label' => __('EKS control plane'),
                    'state' => 'included',
                    'detail' => __('AWS charges $73/mo per EKS cluster control plane.'),
                    'amount' => 73.0,
                    'amount_period' => 'monthly',
                ],
                [
                    'label' => __('Node groups (EC2)'),
                    'state' => 'unknown',
                    'detail' => __('Charged per EC2 instance in each node group. Review your cluster in the AWS console for the running totals.'),
                    'amount' => null,
                    'amount_period' => 'monthly',
                ],
            ],
            'notes' => [
                __('Application load balancers, NAT gateways, EBS volumes, and data transfer are usage-based and not included.'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, float>
     */
    private function buildDoksPriceBySlugMap(array $catalog): array
    {
        $priceBySlug = [];
        foreach ($catalog['sizes'] ?? [] as $size) {
            if (! is_array($size)) {
                continue;
            }
            $slug = (string) ($size['value'] ?? '');
            $monthly = $size['price_monthly'] ?? null;
            if ($slug !== '' && is_numeric($monthly)) {
                $priceBySlug[$slug] = (float) $monthly;
            }
        }

        return $priceBySlug;
    }

    /**
     * Per-cluster node-pool total. Returns the running sum (nodes only, control
     * plane is added by the caller because the HA flag is a per-cluster choice
     * we want to surface as its own line), the extras list of node-pool lines,
     * a flag for any unpriced pool sizes, and the cluster's HA boolean.
     *
     * @param  array<string, mixed>  $cluster
     * @param  array<string, float>  $priceBySlug
     * @return array{total: float, extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>, has_unknown: bool, is_ha: bool}
     */
    private function computeDoksClusterCost(array $cluster, array $priceBySlug): array
    {
        $extras = [];
        $total = 0.0;
        $hasUnknown = false;

        $nodePools = is_array($cluster['node_pools'] ?? null) ? $cluster['node_pools'] : [];
        foreach ($nodePools as $pool) {
            if (! is_array($pool)) {
                continue;
            }
            $poolName = (string) ($pool['name'] ?? __('node pool'));
            $slug = (string) ($pool['size'] ?? '');
            $count = (int) ($pool['count'] ?? 0);
            $unitPrice = $priceBySlug[$slug] ?? null;

            if ($unitPrice === null) {
                $hasUnknown = true;
                $extras[] = [
                    'label' => $poolName.' — '.$count.' × '.$slug,
                    'state' => 'unknown',
                    'detail' => __('Droplet price for :slug not found in the DigitalOcean catalog.', ['slug' => $slug]),
                    'amount' => null,
                    'amount_period' => 'monthly',
                ];

                continue;
            }

            $poolTotal = $unitPrice * $count;
            $total += $poolTotal;
            $extras[] = [
                'label' => $poolName.' — '.$count.' × '.$slug,
                'state' => 'included',
                'detail' => sprintf('$%s × %d', number_format($unitPrice, $unitPrice < 10 ? 2 : 0), $count),
                'amount' => round($poolTotal, 2),
                'amount_period' => 'monthly',
            ];
        }

        return [
            'total' => $total,
            'extras' => $extras,
            'has_unknown' => $hasUnknown,
            'is_ha' => (bool) ($cluster['ha'] ?? false),
        ];
    }

    /**
     * No cluster picked yet but we have credentials + a cluster list. Collapse
     * each cluster's full estimate (nodes + control plane) into a min/max range
     * so the StepWhere sidebar shows real numbers instead of "Unavailable".
     *
     * @param  list<array<string, mixed>|mixed>  $clusters
     * @param  array<string, float>  $priceBySlug
     * @return array{state: string, provider: string, region: ?string, size: ?string, price_monthly: ?float, price_hourly: ?float, formatted_price: ?string, source: ?string, detail: string, extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>, notes: list<string>}
     */
    private function buildDoksAggregateCostPreview(ServerCreateForm $form, array $clusters, array $priceBySlug): array
    {
        $perCluster = [];
        $hasAnyUnknown = false;
        foreach ($clusters as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $cost = $this->computeDoksClusterCost($cluster, $priceBySlug);
            $total = round($cost['total'] + ($cost['is_ha'] ? 40.0 : 0.0), 2);
            $hasAnyUnknown = $hasAnyUnknown || $cost['has_unknown'];
            $perCluster[] = [
                'name' => (string) ($cluster['name'] ?? ''),
                'region' => (string) ($cluster['region'] ?? ''),
                'total' => $total,
                'is_ha' => $cost['is_ha'],
                'has_unknown' => $cost['has_unknown'],
            ];
        }

        if ($perCluster === []) {
            return [
                'state' => 'unavailable',
                'provider' => $form->type,
                'region' => null,
                'size' => null,
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => null,
                'detail' => __('Pick a cluster on the next step to see an estimated monthly cost based on its node pools.'),
                'extras' => [],
                'notes' => [__('DigitalOcean charges for the droplets behind each node pool, plus load balancers, block storage, and bandwidth usage.')],
            ];
        }

        $totals = array_column($perCluster, 'total');
        $min = min($totals);
        $max = max($totals);
        $formattedMin = '$'.number_format($min, $min < 10 ? 2 : 0);
        $formattedMax = '$'.number_format($max, $max < 10 ? 2 : 0);
        $formatted = $min === $max
            ? $formattedMin.'/'.__('mo')
            : $formattedMin.' – '.$formattedMax.'/'.__('mo');
        if ($hasAnyUnknown) {
            $formatted .= ' '.__('(partial)');
        }

        $extras = [];
        foreach ($perCluster as $entry) {
            $label = $entry['name'] !== '' ? $entry['name'] : __('cluster');
            if ($entry['region'] !== '') {
                $label .= ' — '.$entry['region'];
            }
            if ($entry['is_ha']) {
                $label .= ' '.__('(HA)');
            }
            $extras[] = [
                'label' => $label,
                'state' => $entry['has_unknown'] ? 'partial' : 'candidate',
                'detail' => $entry['has_unknown']
                    ? __('Lower bound — one or more node pool sizes weren\'t in the DigitalOcean catalog.')
                    : __('Sum of node-pool droplets plus the cluster\'s control plane fee.'),
                'amount' => $entry['total'],
                'amount_period' => 'monthly',
            ];
        }

        return [
            'state' => 'available',
            'provider' => $form->type,
            'region' => null,
            'size' => count($perCluster) === 1
                ? $perCluster[0]['name']
                : __(':n clusters available', ['n' => count($perCluster)]),
            'price_monthly' => count($perCluster) === 1 ? $perCluster[0]['total'] : null,
            'price_hourly' => null,
            'formatted_price' => $formatted,
            'source' => 'provider_catalog',
            'detail' => count($perCluster) === 1
                ? __('Estimate for the only DOKS cluster in this account. The next step lets you confirm or pick a different one.')
                : __('Range across the DOKS clusters in this account. Pick a specific cluster on the next step to lock in the estimate.'),
            'extras' => $extras,
            'notes' => [
                __('Load balancers ($12/mo each), block storage, snapshots, and bandwidth overages are billed separately by usage.'),
            ],
        ];
    }

    /**
     * Shown when the user is in 'existing' mode but their DO account has zero
     * DOKS clusters yet. Rather than leaving the sidebar blank, we price a
     * sample 2-node starter cluster on the cheapest "real" droplet size (≥2GB
     * RAM — anything smaller is rarely useful for K8s nodes) and point them at
     * the Create New toggle to actually provision it.
     *
     * @param  array<string, float>  $priceBySlug
     * @param  array<string, mixed>  $catalog
     * @return array{state: string, provider: string, region: ?string, size: ?string, price_monthly: ?float, price_hourly: ?float, formatted_price: ?string, source: ?string, detail: string, extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>, notes: list<string>}
     */
    private function buildDoksStarterSamplePreview(ServerCreateForm $form, array $priceBySlug, array $catalog): array
    {
        $starter = $this->pickDoksStarterSize($catalog);

        if ($starter === null || ! isset($priceBySlug[$starter['value']])) {
            return [
                'state' => 'unavailable',
                'provider' => $form->type,
                'region' => null,
                'size' => null,
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => null,
                'detail' => __('No clusters in this account yet. Switch to "Create new" on the next step so dply can provision one for you.'),
                'extras' => [],
                'notes' => [__('DigitalOcean charges for the droplets behind each node pool, plus load balancers, block storage, and bandwidth usage.')],
            ];
        }

        $unitPrice = $priceBySlug[$starter['value']];
        $nodes = 2;
        $total = round($unitPrice * $nodes, 2);
        $formatted = '$'.number_format($total, $total < 10 ? 2 : 0).'/'.__('mo');

        return [
            'state' => 'available',
            'provider' => $form->type,
            'region' => null,
            'size' => __('Sample starter cluster'),
            'price_monthly' => $total,
            'price_hourly' => null,
            'formatted_price' => $formatted.' '.__('(sample)'),
            'source' => 'provider_catalog',
            'detail' => __('No clusters in this account yet. This is what a starter cluster would cost — switch to "Create new" on the next step to actually provision it.'),
            'extras' => [
                [
                    'label' => __('Default pool').' — '.$nodes.' × '.$starter['value'],
                    'state' => 'sample',
                    'detail' => sprintf('$%s × %d', number_format($unitPrice, $unitPrice < 10 ? 2 : 0), $nodes),
                    'amount' => $total,
                    'amount_period' => 'monthly',
                ],
                [
                    'label' => __('Control plane'),
                    'state' => 'sample',
                    'detail' => __('Free for standard (non-HA) DOKS clusters.'),
                    'amount' => 0.0,
                    'amount_period' => 'monthly',
                ],
            ],
            'notes' => [
                __('This is a sample, not a real cluster — nothing is provisioned until you complete the wizard with "Create new" selected.'),
                __('Load balancers ($12/mo each), block storage, and bandwidth are billed separately by usage.'),
            ],
        ];
    }

    /**
     * Picks the cheapest droplet size with at least 2GB of memory — small enough
     * to be a sane "starter" pool and large enough that K8s system pods fit. Falls
     * back to the cheapest priced size when nothing meets the RAM floor.
     *
     * @param  array<string, mixed>  $catalog
     * @return array{value: string, price_monthly: float}|null
     */
    private function pickDoksStarterSize(array $catalog): ?array
    {
        $candidates = [];
        foreach ($catalog['sizes'] ?? [] as $size) {
            if (! is_array($size)) {
                continue;
            }
            $slug = (string) ($size['value'] ?? '');
            $monthly = $size['price_monthly'] ?? null;
            if ($slug === '' || ! is_numeric($monthly)) {
                continue;
            }
            $memMb = (int) ($size['memory_mb'] ?? 0);
            $candidates[] = [
                'value' => $slug,
                'price_monthly' => (float) $monthly,
                'memory_mb' => $memMb,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $a['price_monthly'] <=> $b['price_monthly']);

        foreach ($candidates as $c) {
            if ($c['memory_mb'] >= 2048) {
                return ['value' => $c['value'], 'price_monthly' => $c['price_monthly']];
            }
        }

        return ['value' => $candidates[0]['value'], 'price_monthly' => $candidates[0]['price_monthly']];
    }

    /**
     * Cost preview for the "Create new cluster" path. We don't have a cluster yet
     * (dply will POST to DO on submit), so the math is straight from the form: the
     * proposed node-pool size × count, plus DO's HA fee when toggled. Same shape
     * as the existing-cluster preview so the sidebar blade renders uniformly.
     *
     * @param  array<string, float>  $priceBySlug
     * @return array{state: string, provider: string, region: ?string, size: ?string, price_monthly: ?float, price_hourly: ?float, formatted_price: ?string, source: ?string, detail: string, extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>, notes: list<string>}
     */
    private function buildDoksNewClusterCostPreview(ServerCreateForm $form, array $priceBySlug): array
    {
        $name = $form->do_kubernetes_new_name;
        $region = $form->do_kubernetes_new_region;
        $size = $form->do_kubernetes_new_node_size;
        $count = max(0, $form->do_kubernetes_new_node_count);
        $isHa = $form->do_kubernetes_new_ha;

        if ($size === '' || $count <= 0) {
            return [
                'state' => 'unavailable',
                'provider' => $form->type,
                'region' => $region !== '' ? $region : null,
                'size' => $name !== '' ? $name : __('new cluster'),
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => null,
                'detail' => __('Pick a node droplet size and count to estimate the monthly cost of the cluster dply will create.'),
                'extras' => [],
                'notes' => [__('You are about to provision a brand-new DOKS cluster. Charges begin once DigitalOcean reports the cluster as running.')],
            ];
        }

        $unitPrice = $priceBySlug[$size] ?? null;
        $extras = [];
        if ($unitPrice === null) {
            $extras[] = [
                'label' => __('Default pool').' — '.$count.' × '.$size,
                'state' => 'unknown',
                'detail' => __('Droplet price for :slug not found in the DigitalOcean catalog.', ['slug' => $size]),
                'amount' => null,
                'amount_period' => 'monthly',
            ];
            $nodeTotal = 0.0;
            $hasUnknown = true;
        } else {
            $nodeTotal = $unitPrice * $count;
            $extras[] = [
                'label' => __('Default pool').' — '.$count.' × '.$size,
                'state' => 'included',
                'detail' => sprintf('$%s × %d', number_format($unitPrice, $unitPrice < 10 ? 2 : 0), $count),
                'amount' => round($nodeTotal, 2),
                'amount_period' => 'monthly',
            ];
            $hasUnknown = false;
        }

        if ($isHa) {
            $extras[] = [
                'label' => __('HA control plane'),
                'state' => 'included',
                'detail' => __('DigitalOcean charges $40/mo for the highly-available control plane.'),
                'amount' => 40.0,
                'amount_period' => 'monthly',
            ];
        } else {
            $extras[] = [
                'label' => __('Control plane'),
                'state' => 'included',
                'detail' => __('Free for standard (non-HA) DOKS clusters.'),
                'amount' => 0.0,
                'amount_period' => 'monthly',
            ];
        }

        $total = round($nodeTotal + ($isHa ? 40.0 : 0.0), 2);
        $formatted = '$'.number_format($total, $total < 10 ? 2 : 0).'/'.__('mo');
        if ($hasUnknown) {
            $formatted .= ' '.__('(partial)');
        }

        return [
            'state' => 'available',
            'provider' => $form->type,
            'region' => $region !== '' ? $region : null,
            'size' => $name !== '' ? $name : __('new cluster'),
            'price_monthly' => $total,
            'price_hourly' => null,
            'formatted_price' => $formatted,
            'source' => 'provider_catalog',
            'detail' => $hasUnknown
                ? __('Estimate for the cluster dply will create. One or more sizes weren\'t in the catalog — total is a lower bound.')
                : __('Estimate for the cluster dply will create on submit. You will be charged by DigitalOcean once the cluster is running.'),
            'extras' => $extras,
            'notes' => [
                __('Load balancers ($12/mo each), block storage, snapshots, and bandwidth overages are billed separately by usage.'),
                __('Provisioning starts when you click "Create server" on the next step. Cancelling later requires deleting the cluster in DigitalOcean.'),
            ],
        ];
    }
}
