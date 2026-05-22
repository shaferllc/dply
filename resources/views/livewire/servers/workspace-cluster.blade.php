@php
    $card = 'dply-card overflow-hidden';
    $clusterName = (string) ($kubernetes['cluster_name'] ?? '');
    $region = (string) ($kubernetes['region'] ?? '');
    $namespace = (string) ($kubernetes['namespace'] ?? '');
    $version = (string) ($snapshot['version'] ?? '');
    $ha = (bool) ($snapshot['ha'] ?? false);
    $lastError = (string) ($kubernetes['last_error'] ?? '');
    $lastPolledAt = (string) ($kubernetes['last_polled_at'] ?? '');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="cluster"
    :title="__('Cluster')"
    :description="__('Managed Kubernetes cluster status, node pools, kubeconfig, and workloads.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    {{-- Container-launch progress banner (shown after the user kicks off a
         container site against this cluster — same banner as on the overview
         for Docker hosts). Self-polls until the launch finishes. --}}
    @include('livewire.servers.partials._container-launch-progress')

    {{-- Auto-refresh the whole page while provisioning so the milestone strip
         and node-pool table reflect what the background poller wrote. Stops
         the moment status flips to READY or ERROR — no need to keep polling. --}}
    <div @if ($phase === 'provisioning') wire:poll.5s @endif class="space-y-6">

        @if ($phase === 'error')
            {{-- Error card replaces the milestone strip. Retry kicks a fresh
                 poll for the "cluster came online late" case; Open in console
                 lets the user diagnose anything dply can't show. --}}
            <section class="overflow-hidden rounded-2xl border border-rose-200 bg-rose-50 p-6 shadow-sm">
                <div class="flex items-start gap-3">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-6 w-6 shrink-0 text-rose-600" />
                    <div class="flex-1">
                        <h2 class="text-lg font-semibold text-rose-900">{{ __('Cluster provisioning failed') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-rose-800">
                            {{ $lastError !== '' ? $lastError : __('DigitalOcean reported an error during cluster setup.') }}
                        </p>
                        <div class="mt-4 flex flex-wrap gap-3">
                            <button
                                type="button"
                                wire:click="retryPolling"
                                wire:loading.attr="disabled"
                                wire:target="retryPolling"
                                class="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg bg-rose-600 px-3 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60"
                            >
                                <x-heroicon-o-arrow-path wire:loading.remove wire:target="retryPolling" class="h-4 w-4" />
                                <x-spinner wire:loading wire:target="retryPolling" variant="white" size="sm" />
                                {{ __('Retry polling') }}
                            </button>
                            <a href="https://cloud.digitalocean.com/kubernetes/clusters" target="_blank" rel="noopener" class="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 text-xs font-semibold text-rose-800 transition-colors hover:bg-rose-100">
                                {{ __('Open in DigitalOcean') }}
                                <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" />
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        @elseif ($phase === 'provisioning')
            {{-- 3-step milestone strip. Each milestone maps to a real signal:
                 Created (server row exists), Nodes (X of Y running per the
                 snapshot's node_pools[].nodes[].status), Ready (status flips). --}}
            @php
                $createdDone = true;
                $nodesActive = $totalNodes > 0 && $readyNodes < $totalNodes;
                $nodesDone = $totalNodes > 0 && $readyNodes === $totalNodes;
                $readyDone = false;
            @endphp
            <section class="overflow-hidden rounded-2xl border border-sky-200 bg-sky-50 p-6 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <span class="inline-flex items-center gap-2 rounded-full border border-sky-300 bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700">
                            <span class="h-2 w-2 animate-pulse rounded-full bg-sky-500"></span>
                            {{ __('Provisioning') }}
                        </span>
                        <h2 class="mt-3 text-2xl font-semibold tracking-tight text-brand-ink">
                            {{ __('DigitalOcean is bringing your cluster online') }}
                        </h2>
                        <p class="mt-1 text-sm text-sky-900">
                            {{ __('Node pool VMs typically take 5–10 minutes. This page updates automatically.') }}
                        </p>
                    </div>
                </div>

                <ol class="mt-6 grid gap-3 sm:grid-cols-3">
                    {{-- Step 1: Created --}}
                    <li class="rounded-xl border border-sky-200 bg-white px-4 py-3">
                        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-emerald-700">
                            <x-heroicon-m-check-circle class="h-4 w-4" />
                            {{ __('Created') }}
                        </div>
                        <p class="mt-1 text-sm text-brand-ink">{{ __('Cluster created in DigitalOcean.') }}</p>
                    </li>
                    {{-- Step 2: Nodes coming online --}}
                    <li @class([
                        'rounded-xl border px-4 py-3',
                        'border-sky-300 bg-sky-100' => $nodesActive,
                        'border-emerald-300 bg-emerald-50' => $nodesDone,
                        'border-sky-200 bg-white' => ! $nodesActive && ! $nodesDone,
                    ])>
                        <div @class([
                            'flex items-center gap-2 text-xs font-semibold uppercase tracking-wide',
                            'text-sky-800' => $nodesActive,
                            'text-emerald-700' => $nodesDone,
                            'text-brand-mist' => ! $nodesActive && ! $nodesDone,
                        ])>
                            @if ($nodesDone)
                                <x-heroicon-m-check-circle class="h-4 w-4" />
                            @elseif ($nodesActive)
                                <span class="h-2 w-2 animate-pulse rounded-full bg-sky-500"></span>
                            @else
                                <x-heroicon-m-clock class="h-4 w-4" />
                            @endif
                            {{ __('Nodes coming online') }}
                        </div>
                        <p class="mt-1 text-sm text-brand-ink">
                            {{ $totalNodes > 0
                                ? __(':ready of :total ready', ['ready' => $readyNodes, 'total' => $totalNodes])
                                : __('Waiting for DigitalOcean to schedule node pool VMs…') }}
                        </p>
                    </li>
                    {{-- Step 3: Ready --}}
                    <li class="rounded-xl border border-sky-200 bg-white px-4 py-3 opacity-60">
                        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-brand-mist">
                            <x-heroicon-m-clock class="h-4 w-4" />
                            {{ __('Ready') }}
                        </div>
                        <p class="mt-1 text-sm text-brand-ink">{{ __('Cluster is reachable and accepting workloads.') }}</p>
                    </li>
                </ol>
            </section>
        @else
            {{-- READY: cluster info card --}}
            <section class="{{ $card }} p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <span class="inline-flex items-center gap-2 rounded-full border border-emerald-300 bg-emerald-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            {{ __('Running') }}
                        </span>
                        <h2 class="mt-3 text-2xl font-semibold tracking-tight text-brand-ink">{{ $clusterName }}</h2>
                        <p class="mt-1 font-mono text-xs text-brand-mist">{{ $kubernetes['cluster_id'] ?? '—' }}</p>
                    </div>
                    <button
                        type="button"
                        wire:click="refreshClusterStatus"
                        wire:loading.attr="disabled"
                        wire:target="refreshClusterStatus"
                        title="{{ __('Re-fetch cluster state from DigitalOcean') }}"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 text-xs font-semibold text-brand-moss transition-colors hover:border-brand-sage hover:text-brand-sage disabled:cursor-wait disabled:opacity-60"
                    >
                        <x-heroicon-o-arrow-path wire:loading.remove wire:target="refreshClusterStatus" class="h-4 w-4" />
                        <x-spinner wire:loading wire:target="refreshClusterStatus" size="sm" />
                        {{ __('Refresh') }}
                    </button>
                </div>
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Region') }}</dt>
                        <dd class="mt-1 font-mono text-brand-ink">{{ $region !== '' ? $region : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Kubernetes version') }}</dt>
                        <dd class="mt-1 font-mono text-brand-ink">{{ $version !== '' ? $version : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Control plane') }}</dt>
                        <dd class="mt-1 text-brand-ink">{{ $ha ? __('Highly available') : __('Standard') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Default namespace') }}</dt>
                        <dd class="mt-1 font-mono text-brand-ink">{{ $namespace !== '' ? $namespace : 'default' }}</dd>
                    </div>
                </dl>
            </section>
        @endif

        {{-- Node-pool table: same component for all three phases. Empty rows
             during very-early provisioning (before the first snapshot lands)
             render as a single "waiting for data" line. --}}
        <section class="{{ $card }} p-6">
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="text-lg font-semibold text-brand-ink">{{ __('Node pools') }}</h3>
                @if ($lastPolledAt !== '')
                    <p class="text-xs text-brand-mist">{{ __('Updated :time', ['time' => \Carbon\Carbon::parse($lastPolledAt)->diffForHumans()]) }}</p>
                @endif
            </div>

            @if ($nodePools === [])
                <p class="mt-4 text-sm text-brand-mist">{{ __('Waiting for the first status snapshot…') }}</p>
            @else
                <div class="mt-4 overflow-hidden rounded-xl border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                        <thead class="bg-brand-cream/40 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">
                            <tr>
                                <th class="px-4 py-2 text-left">{{ __('Pool') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Droplet size') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Nodes') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Per-node status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10 bg-white">
                            @foreach ($nodePools as $pool)
                                @php
                                    $poolName = (string) ($pool['name'] ?? '—');
                                    $size = (string) ($pool['size'] ?? '—');
                                    $poolNodes = is_array($pool['nodes'] ?? null) ? $pool['nodes'] : [];
                                    $count = (int) ($pool['count'] ?? count($poolNodes));
                                @endphp
                                <tr>
                                    <td class="px-4 py-3 font-medium text-brand-ink">{{ $poolName }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-brand-moss">{{ $size }}</td>
                                    <td class="px-4 py-3 text-brand-ink">{{ $count }}</td>
                                    <td class="px-4 py-3">
                                        @if ($poolNodes === [])
                                            <span class="text-xs text-brand-mist">—</span>
                                        @else
                                            <div class="flex flex-wrap gap-1.5">
                                                @foreach ($poolNodes as $node)
                                                    @php
                                                        $state = (string) ($node['status']['state'] ?? 'unknown');
                                                        $badgeClass = match ($state) {
                                                            'running' => 'border-emerald-300 bg-emerald-50 text-emerald-700',
                                                            'provisioning' => 'border-sky-300 bg-sky-50 text-sky-700',
                                                            default => 'border-amber-300 bg-amber-50 text-amber-700',
                                                        };
                                                    @endphp
                                                    <span class="inline-flex items-center rounded-full border {{ $badgeClass }} px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide">{{ $state }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if ($phase === 'ready')
            {{-- Kubeconfig panel --}}
            <section class="{{ $card }} p-6">
                <h3 class="text-lg font-semibold text-brand-ink">{{ __('Kubeconfig') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Authenticates kubectl against this cluster. Treat it like a password — anyone with this file can manage workloads.') }}</p>

                @if ($hasKubeconfig)
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <a href="{{ route('servers.cluster.kubeconfig', $server) }}" class="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl bg-brand-ink px-4 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest">
                            <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
                            {{ __('Download kubeconfig') }}
                        </a>
                        <code class="rounded-lg bg-brand-cream/60 px-3 py-2 font-mono text-xs text-brand-ink">kubectl --kubeconfig=&lt;downloaded-file&gt; get nodes</code>
                    </div>
                @else
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        {{ __('Kubeconfig not fetched yet — the next poll will retrieve it.') }}
                    </div>
                @endif
            </section>

            {{-- Workloads (sites) --}}
            <section class="{{ $card }} p-6">
                <h3 class="text-lg font-semibold text-brand-ink">{{ __('Workloads') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Container sites deployed to this cluster.') }}</p>

                @if ($sites->isEmpty())
                    <div class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 p-6 text-center text-sm text-brand-moss">
                        <p>{{ __('No workloads yet.') }}</p>
                        <a href="{{ route('sites.create', $server) }}" wire:navigate class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-brand-sage px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-forest">
                            {{ __('Add a container site') }}
                            <x-heroicon-m-arrow-right class="h-3.5 w-3.5" />
                        </a>
                    </div>
                @else
                    <ul class="mt-4 divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                        @foreach ($sites as $site)
                            <li class="flex items-center justify-between gap-3 px-4 py-3">
                                <a href="{{ route('sites.overview', ['server' => $server, 'site' => $site]) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">{{ $site->name }}</a>
                                <span class="font-mono text-xs text-brand-mist">{{ $site->status ?? '—' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        {{-- Footer actions: Open in DO + Delete. Available in all phases so the
             user can escape whenever — especially during provisioning hang. --}}
        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 pt-6">
            <a href="https://cloud.digitalocean.com/kubernetes/clusters" target="_blank" rel="noopener" class="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-4 text-sm font-semibold text-brand-moss transition-colors hover:border-brand-sage hover:text-brand-sage">
                <x-heroicon-m-arrow-top-right-on-square class="h-4 w-4" />
                {{ __('Open in DigitalOcean') }}
            </a>
            <button
                type="button"
                wire:click="openDeleteClusterModal"
                class="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl border border-rose-200 bg-white px-4 text-sm font-semibold text-rose-700 transition-colors hover:bg-rose-50"
            >
                <x-heroicon-o-trash class="h-4 w-4" />
                {{ $provisionedByDply ? __('Delete cluster') : __('Remove from dply') }}
            </button>
        </footer>
    </div>

    {{-- Adaptive delete modal: red destructive flavor for dply-provisioned
         clusters (the DO cluster gets deleted too), amber deregister flavor
         for registered-existing clusters (DO cluster stays). --}}
    @if ($showDeleteClusterModal)
        <div class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeDeleteClusterModal"></div>
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-md overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-2xl">
                    @if ($provisionedByDply)
                        <div class="bg-rose-50 px-6 py-5">
                            <div class="flex items-start gap-3">
                                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-6 w-6 shrink-0 text-rose-600" />
                                <div>
                                    <h3 class="text-lg font-semibold text-rose-900">{{ __('Delete this cluster?') }}</h3>
                                    <p class="mt-1 text-sm text-rose-800">
                                        {{ __('This destroys the DigitalOcean cluster, every workload running on it, and any data on persistent volumes. Cannot be undone.') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="px-6 py-5">
                            <label for="deleteConfirmName" class="block text-sm font-medium text-brand-ink">{{ __('Type the cluster name to confirm:') }} <span class="font-mono text-rose-700">{{ $clusterName }}</span></label>
                            <input
                                id="deleteConfirmName"
                                type="text"
                                wire:model.live="deleteConfirmName"
                                class="mt-2 block w-full rounded-xl border-brand-ink/15 font-mono text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500"
                                autocomplete="off"
                            />
                            @error('deleteConfirmName')
                                <p class="mt-2 text-xs text-rose-700">{{ $message }}</p>
                            @enderror
                        </div>
                    @else
                        <div class="bg-amber-50 px-6 py-5">
                            <div class="flex items-start gap-3">
                                <x-heroicon-o-information-circle class="mt-0.5 h-6 w-6 shrink-0 text-amber-600" />
                                <div>
                                    <h3 class="text-lg font-semibold text-amber-900">{{ __('Remove this cluster from dply?') }}</h3>
                                    <p class="mt-1 text-sm text-amber-800">
                                        {{ __('The DigitalOcean cluster stays running in your account. This only removes the dply registration; any workloads dply was managing become unmanaged but are not deleted.') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="flex items-center justify-end gap-2 border-t border-brand-ink/10 bg-white px-6 py-4">
                        <button type="button" wire:click="closeDeleteClusterModal" class="inline-flex h-9 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 text-xs font-semibold text-brand-ink hover:border-brand-sage">
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="button"
                            wire:click="deleteCluster"
                            wire:loading.attr="disabled"
                            wire:target="deleteCluster"
                            @class([
                                'inline-flex h-9 items-center justify-center gap-1.5 rounded-lg px-3 text-xs font-semibold text-white shadow-sm disabled:cursor-wait disabled:opacity-60',
                                'bg-rose-600 hover:bg-rose-700' => $provisionedByDply,
                                'bg-amber-600 hover:bg-amber-700' => ! $provisionedByDply,
                            ])
                        >
                            <x-spinner wire:loading wire:target="deleteCluster" variant="white" size="sm" />
                            {{ $provisionedByDply ? __('Delete cluster permanently') : __('Remove from dply') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-server-workspace-layout>
