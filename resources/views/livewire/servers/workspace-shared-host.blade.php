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

    <x-slot:explainer>
        <p>{{ __('Multi-site VMs hide coupling until something breaks. Shared Host Radar attributes live process load to each site, maps shared stack dependencies from your bindings, and correlates deploys with CPU spikes.') }}</p>
    </x-slot:explainer>

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
        @php
            $breachCount = count($report['budget_breaches'] ?? []);
            $eventCount = count($events);
        @endphp

        {{-- Overall status + scan stays above the tabs: it frames every section. --}}
        <section class="dply-card mb-6 overflow-hidden">
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

        <x-server-workspace-tablist :aria-label="__('Shared host sections')">
            <x-server-workspace-tab id="sh-tab-radar" icon="heroicon-o-signal" :active="$shared_host_tab === 'radar'" wire:click="setSharedHostTab('radar')">
                {{ __('Radar') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab id="sh-tab-budgets" icon="heroicon-o-bell-alert" :active="$shared_host_tab === 'budgets'" wire:click="setSharedHostTab('budgets')">
                {{ __('Budgets') }}
                @if ($breachCount > 0)
                    <span class="inline-flex shrink-0 items-center rounded-full bg-amber-200/90 px-1.5 py-0.5 text-[10px] font-semibold leading-none tabular-nums text-amber-900">{{ $breachCount }}</span>
                @endif
            </x-server-workspace-tab>
            <x-server-workspace-tab id="sh-tab-contention" icon="heroicon-o-clock" :active="$shared_host_tab === 'contention'" wire:click="setSharedHostTab('contention')">
                {{ __('Contention') }}
                @if ($eventCount > 0)
                    <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-[10px] font-semibold leading-none tabular-nums text-brand-moss">{{ $eventCount }}</span>
                @endif
            </x-server-workspace-tab>
            <x-server-workspace-tab id="sh-tab-notifications" icon="heroicon-o-bell" :active="$shared_host_tab === 'notifications'" wire:click="setSharedHostTab('notifications')">
                {{ __('Notifications') }}
            </x-server-workspace-tab>
        </x-server-workspace-tablist>

        {{-- Skeleton placeholder shown while the incoming tab loads. --}}
        <div wire:loading.block wire:target="setSharedHostTab">
            @include('livewire.servers.partials._skeleton-cards')
        </div>

        <div class="space-y-6" wire:loading.remove wire:target="setSharedHostTab">
            @if ($shared_host_tab === 'radar')
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
            @elseif ($shared_host_tab === 'budgets')
                @include('livewire.servers.partials.shared-host._budgets', [
                    'report' => $report,
                    'server' => $server,
                ])
            @elseif ($shared_host_tab === 'contention')
                @include('livewire.servers.partials.shared-host._contention', [
                    'events' => $events,
                    'promoteEnabled' => (bool) ($report['promote_enabled'] ?? false),
                    'tonePalette' => $tonePalette,
                ])
            @elseif ($shared_host_tab === 'notifications')
                @include('livewire.servers.partials.shared-host._tab-notifications', [
                    'notifChannels' => $notifChannels,
                    'notifSubscriptions' => $notifSubscriptions,
                    'notifEventLabels' => $notifEventLabels,
                ])
            @endif
        </div>

        {{-- Reusable inline channel-create modal (CreatesNotificationChannelInline trait),
             opened from the "Create a channel" button on the Notifications tab. --}}
        @include('livewire.partials.create-notification-channel-modal')
    @endif
</x-server-workspace-layout>
