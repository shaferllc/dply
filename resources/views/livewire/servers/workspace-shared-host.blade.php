@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sky' => 'bg-sky-50 text-sky-800 ring-sky-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
    ];

    $overallTone = match ($report['overall'] ?? 'ok') {
        'critical' => $tonePalette['rose'],
        'warning' => $tonePalette['amber'],
        'info' => $tonePalette['sky'],
        default => $tonePalette['emerald'],
    };

    $attribution = $report['attribution'] ?? [];
    $sharedMap = $report['shared_map'] ?? [];
    $events = $report['contention_events'] ?? [];
    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
    $soloTenant = (bool) ($report['solo_tenant'] ?? true);
@endphp

<x-server-workspace-layout
    :server="$server"
    active="shared-host"
    :title="__('Shared Host Radar')"
    :description="__('See which sites share CPU, memory, Redis, and databases on this server — and catch noisy-neighbor contention before deploys fail.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('Multi-site VMs hide coupling until something breaks. Shared Host Radar attributes live process load to each site, maps shared stack dependencies from your bindings, and correlates deploys with CPU spikes.') }}</p>
    </x-explainer>

    @if ($soloTenant)
        <section class="dply-card overflow-hidden">
            <div class="px-6 py-8 text-center sm:px-7">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-server-stack class="h-6 w-6" aria-hidden="true" />
                </span>
                <h2 class="mt-4 text-base font-semibold text-brand-ink">{{ __('Solo tenant on this host') }}</h2>
                <p class="mx-auto mt-2 max-w-lg text-sm text-brand-moss">{{ __('Shared Host Radar activates when two or more sites run on the same server. Add another site or use Metrics for single-app capacity.') }}</p>
                <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="mt-5 inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    {{ __('Open Metrics') }}
                    <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                </a>
            </div>
        </section>
    @else
        <div class="space-y-6">
            <section class="dply-card overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1 {{ $overallTone }}">
                            <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Overall') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                                @switch($report['overall'] ?? 'ok')
                                    @case('critical') {{ __('Contention needs attention') }} @break
                                    @case('warning') {{ __('Review shared load') }} @break
                                    @case('info') {{ __('Shared resources mapped') }} @break
                                    @default {{ __('Balanced headroom') }}
                                @endswitch
                            </h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ trans_choice(':count site on this host|:count sites on this host', (int) ($report['site_count'] ?? 0), ['count' => (int) ($report['site_count'] ?? 0)]) }}
                                @if (($attribution['checked_at'] ?? null))
                                    · {{ __('Attribution :time', ['time' => $attribution['checked_at']->diffForHumans()]) }}
                                    @if ($attribution['stale'] ?? false)
                                        · <span class="font-medium text-amber-800">{{ __('stale') }}</span>
                                    @endif
                                @else
                                    · {{ __('Run attribution scan for live split') }}
                                @endif
                            </p>
                        </div>
                    </div>
                    @if ($opsReady && ! $isDeployer)
                        <button
                            type="button"
                            wire:click="refreshSharedHostAttribution"
                            wire:loading.attr="disabled"
                            wire:target="refreshSharedHostAttribution"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="refreshSharedHostAttribution" class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                                {{ __('Scan load') }}
                            </span>
                            <span wire:loading wire:target="refreshSharedHostAttribution" class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" aria-hidden="true" />
                                {{ __('Scanning…') }}
                            </span>
                        </button>
                    @endif
                </div>
            </section>

            @include('livewire.servers.partials.shared-host._fairness-advisor', [
                'advisor' => $advisor ?? [],
                'llmRun' => $llmRun ?? null,
                'llmNarrative' => $llmNarrative ?? null,
                'llmCanRun' => $llmCanRun ?? false,
            ])

            @include('livewire.servers.partials.shared-host._attribution', [
                'attribution' => $attribution,
                'tonePalette' => $tonePalette,
            ])

            @include('livewire.servers.partials.shared-host._shared-map', [
                'sharedMap' => $sharedMap,
            ])

            @include('livewire.servers.partials.shared-host._budgets', [
                'report' => $report,
                'server' => $server,
            ])

            @include('livewire.servers.partials.shared-host._contention', [
                'events' => $events,
                'promoteEnabled' => (bool) ($report['promote_enabled'] ?? false),
                'tonePalette' => $tonePalette,
            ])
        </div>
    @endif
</x-server-workspace-layout>
