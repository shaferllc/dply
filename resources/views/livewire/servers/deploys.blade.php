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
        {{-- Dense head, matching the rest of the workspace. The window state
             pill rides the actions slot. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-rocket-launch"
            :title="__('Deploys')"
            :note="__('History and deploy-window policy for every site on this server.')"
            class="border-b border-brand-ink/10"
        >
            @if ($showBanner)
                <x-slot:actions>
                    <span @class(['inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 text-xs font-semibold ring-1', $overallTone])>
                        @switch($overall)
                            @case('blocked')
                                <x-heroicon-m-no-symbol class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Blocked now') }}
                                @break
                            @case('allowed')
                                <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Allowed now') }}
                                @break
                            @default
                                <x-heroicon-m-pause-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Windows off') }}
                        @endswitch
                    </span>
                </x-slot:actions>
            @endif
        </x-workspace-panel-head>

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Deploy sections')" scroll bare class="!mb-0">
                <x-server-workspace-tab id="dpl-tab-history" icon="heroicon-o-clock" :active="$tab === 'history'" wire:click="setTab('history')">
                    {{ __('History') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="dpl-tab-windows" icon="heroicon-o-calendar-days" :active="$tab === 'deploy-windows'" wire:click="setTab('deploy-windows')">
                    {{ __('Deploy windows') }}
                    @if ($ruleCount > 0)
                        <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-2xs font-semibold leading-none tabular-nums text-brand-moss">{{ $ruleCount }}</span>
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
            <div @class(['flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 px-4 py-2 text-xs sm:px-5', $overallTone])>
                <div class="flex items-center gap-1.5 font-semibold">
                    @switch($overall)
                        @case('blocked')
                            <x-heroicon-m-no-symbol class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <span>{{ __('Deploys blocked now') }}</span>
                            @break
                        @case('allowed')
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <span>{{ __('Deploys allowed now') }}</span>
                            @break
                        @default
                            <x-heroicon-m-pause-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
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

        {{-- One skeleton per tab, sized to what actually arrives: History leads
             with its filter row, Deploy windows with the figure strip and the
             policy form, Coverage with site rows, Notifications with the routed
             channels plus the add form. One shared stub, reshaped per tab. --}}
        @php
            $bar = 'animate-pulse rounded bg-brand-ink/10';
            $deployTabSkeletons = [
                'history' => ['stats' => 0, 'rows' => 5, 'filters' => true],
                'deploy-windows' => ['stats' => 4, 'rows' => 2, 'form' => true],
                'coverage' => ['stats' => 0, 'rows' => 5],
                'notifications' => ['stats' => 0, 'rows' => 2, 'form' => true],
            ];
        @endphp
        @foreach ($deployTabSkeletons as $skeletonTab => $shape)
            <div class="hidden" wire:loading.class.remove="hidden" wire:target="setTab('{{ $skeletonTab }}')" aria-busy="true" aria-live="polite">
                <span class="sr-only">{{ __('Loading section…') }}</span>
                @if ($shape['filters'] ?? false)
                    <div class="flex flex-wrap items-end justify-between gap-2 border-b border-brand-ink/10 px-4 py-2.5 sm:px-5" aria-hidden="true">
                        <div class="space-y-1.5">
                            <div class="h-2.5 w-32 {{ $bar }}"></div>
                            <div class="h-2 w-20 {{ $bar }}"></div>
                        </div>
                        <div class="h-8 w-28 rounded-lg {{ $bar }}"></div>
                    </div>
                @else
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4" aria-hidden="true">
                        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                        <span class="h-3.5 w-28 shrink-0 {{ $bar }}"></span>
                        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                        <span class="h-6 w-24 shrink-0 rounded-md {{ $bar }}"></span>
                    </div>
                @endif
                @if ($shape['stats'] > 0)
                    <div class="grid grid-cols-2 border-b border-brand-ink/10 sm:grid-cols-4" aria-hidden="true">
                        @foreach (range(1, $shape['stats']) as $cell)
                            <div class="space-y-1.5 px-4 py-2 sm:px-5">
                                <div class="h-2 w-14 {{ $bar }}"></div>
                                <div class="h-3 w-10 {{ $bar }}"></div>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div class="divide-y divide-brand-ink/10" aria-hidden="true">
                    @foreach (range(1, $shape['rows']) as $row)
                        <div class="flex items-center gap-2 px-4 py-2.5 sm:px-5">
                            <span class="h-2.5 w-40 max-w-full shrink-0 {{ $bar }}"></span>
                            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                            <span class="h-4 w-16 shrink-0 rounded-full {{ $bar }}"></span>
                        </div>
                    @endforeach
                </div>
                @if ($shape['form'] ?? false)
                    <div class="grid gap-3 border-t border-brand-ink/10 px-4 py-3.5 sm:grid-cols-2 sm:px-5" aria-hidden="true">
                        @foreach (range(1, 2) as $field)
                            <div class="space-y-1.5">
                                <div class="h-2.5 w-16 {{ $bar }}"></div>
                                <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        <div wire:loading.class="hidden" wire:target="setTab">
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
