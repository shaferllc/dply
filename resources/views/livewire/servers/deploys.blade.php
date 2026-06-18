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
    :pageHeaderToolbar="true"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-server-workspace-tablist :aria-label="__('Deploy sections')">
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

    @if ($showBanner)
        {{-- Live enforcement state — present on History + Deploy Windows. --}}
        <div @class(['flex flex-wrap items-center justify-between gap-3 rounded-2xl px-5 py-3.5 text-sm ring-1', $overallTone])>
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

    {{-- Skeleton placeholder shown while the incoming tab loads. --}}
    <div class="mt-6" wire:loading.block wire:target="setTab">
        @include('livewire.servers.partials._skeleton-cards')
    </div>

    <div wire:loading.remove wire:target="setTab">
        @if ($tab === 'history')
            @include('livewire.servers.partials.deploys.history-tab')
        @elseif ($tab === 'deploy-windows')
            @include('livewire.servers.partials.deploys.windows-tab')
        @elseif ($tab === 'coverage')
            @include('livewire.servers.partials.deploys.coverage-tab')
        @elseif ($tab === 'notifications')
            <div class="mt-6">
                @include('livewire.servers.partials.deploy-policy.notifications-tab')
            </div>
        @endif
    </div>

    {{-- Reusable inline channel-create modal (CreatesNotificationChannelInline trait). --}}
    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
