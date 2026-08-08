@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'mist' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
        'sky' => 'bg-sky-50 text-sky-800 ring-sky-200',
    ];

    $overallTone = match ($overall) {
        'blocked' => $tonePalette['amber'],
        'allowed' => $tonePalette['emerald'],
        default => $tonePalette['mist'],
    };

    // The enforcement banner only rides the two deploy-relevant tabs: on History
    // it explains why recent deploys were skipped; on Deploy Windows it's the
    // live state of the rules being edited.
    $showBanner = in_array($tab, ['history', 'deploy-windows'], true);
@endphp

<x-server-workspace-layout
    :server="$server"
    active="deploys"
    :title="__('Deploys')"
    :description="__('Deployment history and deploy-window policy for every site on this server.')"
    hide-hero
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Page identity (replaces layout hero) --}}
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Deploys') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('History and deploy-window policy for every site on this server.') }}
                        </p>
                    </div>
                </div>
                @if ($showBanner)
                    <div @class(['inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ring-1', $overallTone])>
                        @switch($overall)
                            @case('blocked')
                                <x-heroicon-o-no-symbol class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Blocked now') }}
                                @break
                            @case('allowed')
                                <x-heroicon-o-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Allowed now') }}
                                @break
                            @default
                                <x-heroicon-o-pause-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Windows off') }}
                        @endswitch
                    </div>
                @endif
            </div>
        </div>

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Deploy sections')" scroll bare class="!mb-0">
                <x-server-workspace-tab id="dpl-tab-history" icon="heroicon-o-clock" :active="$tab === 'history'" wire:click="setTab('history')">
                    {{ __('History') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="dpl-tab-windows" icon="heroicon-o-calendar-days" :active="$tab === 'deploy-windows'" wire:click="setTab('deploy-windows')">
                    {{ __('Deploy windows') }}
                    @if ($ruleCount > 0)
                        <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-[10px] font-semibold leading-none tabular-nums text-brand-moss">{{ $ruleCount }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab id="dpl-tab-coverage" icon="heroicon-o-globe-alt" :active="$tab === 'coverage'" wire:click="setTab('coverage')">
                    {{ __('Coverage') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="dpl-tab-notifications" icon="heroicon-o-bell" :active="$tab === 'notifications'" wire:click="setTab('notifications')">
                    {{ __('Notifications') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        @if ($showBanner)
            <div @class(['flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-5 py-3 text-sm sm:px-6', $overallTone])>
                <div class="flex items-center gap-2.5 font-medium">
                    @switch($overall)
                        @case('blocked')
                            <x-heroicon-o-no-symbol class="h-5 w-5 shrink-0" aria-hidden="true" />
                            <span>{{ __('Deploys blocked now') }}</span>
                            @break
                        @case('allowed')
                            <x-heroicon-o-check-circle class="h-5 w-5 shrink-0" aria-hidden="true" />
                            <span>{{ __('Deploys allowed now') }}</span>
                            @break
                        @default
                            <x-heroicon-o-pause-circle class="h-5 w-5 shrink-0" aria-hidden="true" />
                            <span>{{ __('Deploy windows off') }}</span>
                    @endswitch
                </div>
                <div class="text-xs">
                    @if ($overall === 'disabled')
                        {{ __('Enforcement is off — deploys run any time.') }}
                    @elseif (! $currentAllowed && $nextAllowedAt)
                        {{ __('Allowed again :time', ['time' => $nextAllowedAt->timezone($policyTimezone)->format('D H:i T')]) }}
                    @else
                        {{ trans_choice(':count deny rule|:count deny rules', $ruleCount, ['count' => $ruleCount]) }}
                        · {{ __('Timezone :tz', ['tz' => $policyTimezone]) }}
                    @endif
                </div>
            </div>
        @endif

        {{-- Tab-switch skeleton inside the merged card --}}
        <div wire:loading.block wire:target="setTab" class="px-5 py-6 sm:px-6" aria-busy="true">
            <span class="sr-only">{{ __('Loading…') }}</span>
            <div class="space-y-3" aria-hidden="true">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="h-9 w-28 animate-pulse rounded-lg bg-brand-ink/10"></div>
                    <div class="h-9 w-20 animate-pulse rounded-lg bg-brand-ink/10"></div>
                </div>
                @foreach (range(1, 4) as $row)
                    <div class="flex items-start gap-3 border-t border-brand-ink/10 pt-3">
                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-brand-ink/15"></span>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div wire:loading.remove wire:target="setTab">
            @if ($tab === 'history')
                @include('livewire.servers.partials.deploys.history-tab')
            @elseif ($tab === 'deploy-windows')
                @include('livewire.servers.partials.deploys.windows-tab')
            @elseif ($tab === 'coverage')
                @include('livewire.servers.partials.deploys.coverage-tab')
            @elseif ($tab === 'notifications')
                @include('livewire.servers.partials.deploy-policy.notifications-tab')
            @endif
        </div>
    </section>

    {{-- Reusable inline channel-create modal (CreatesNotificationChannelInline trait). --}}
    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
