{{-- Nested inside the merged Health card — dense heads over hairline rows, no
     nested dply-cards. Three icon-badge + eyebrow + title stacks cost ~250px
     before the first failure. --}}
<div>
    <div class="grid lg:grid-cols-2 lg:divide-x lg:divide-brand-ink/10">
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-exclamation-triangle"
                :tone="($report['deployments']['failed_count'] ?? 0) > 0 ? 'amber' : null"
                :title="__('Failed deploys')"
                :count="($report['deployments']['failed_count'] ?? 0) > 0 ? $report['deployments']['failed_count'] : null"
                :note="__('Last :days days', ['days' => $report['deployments']['lookback_days'] ?? 7])"
                class="border-b border-brand-ink/10"
            >
                @if (($report['deployments']['failed_count'] ?? 0) > 0)
                    <x-slot:actions>
                        <a
                            href="{{ route('servers.deploys', $server) }}"
                            wire:navigate
                            class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            {{ __('Deploy history') }}
                            <x-heroicon-m-arrow-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                        </a>
                    </x-slot:actions>
                @endif
            </x-workspace-panel-head>

            @if (($report['deployments']['failed_count'] ?? 0) === 0)
                <x-empty-state
                    borderless
                    compact
                    tone="sage"
                    icon="heroicon-o-check-circle"
                    :title="__('No failed deploys')"
                    :description="__('Nothing failed in the last :days days.', ['days' => $report['deployments']['lookback_days'] ?? 7])"
                />
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($report['deployments']['recent'] as $failure)
                        <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-1.5 text-xs sm:px-5">
                            <span class="min-w-0 truncate font-semibold text-brand-ink">{{ $failure['site_name'] }}</span>
                            <a href="{{ $failure['href'] }}" wire:navigate class="ml-auto shrink-0 text-xs font-semibold text-brand-forest hover:underline">{{ $failure['at']?->diffForHumans() }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-shield-check"
                :tone="count($report['certificates']['items']) > 0 ? 'amber' : null"
                :title="__('Certificates')"
                :count="count($report['certificates']['items']) > 0 ? count($report['certificates']['items']) : null"
                :note="__('Expiring or failed TLS in the warning window.')"
                class="border-b border-brand-ink/10"
            />

            @if (count($report['certificates']['items']) === 0)
                <x-empty-state
                    borderless
                    compact
                    tone="sage"
                    icon="heroicon-o-shield-check"
                    :title="__('Certificates healthy')"
                    :description="__('No expiring or failed certificates in the warning window.')"
                />
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($report['certificates']['items'] as $cert)
                        <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-1.5 text-xs sm:px-5">
                            <span class="shrink-0 font-semibold text-brand-ink">{{ $cert['site_name'] }}</span>
                            <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 truncate text-xs text-brand-moss">{{ $cert['domain'] ?: $cert['status'] }}</span>
                            @if ($cert['href'])
                                <a href="{{ $cert['href'] }}" wire:navigate class="ml-auto shrink-0 text-xs font-semibold text-brand-forest hover:underline">
                                    @if ($cert['days_left'] !== null)
                                        {{ trans_choice(':days day|:days days', max(0, $cert['days_left']), ['days' => max(0, $cert['days_left'])]) }}
                                    @else
                                        {{ __('Open') }}
                                    @endif
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section>
        <x-workspace-panel-head
            dense
            icon="heroicon-o-cpu-chip"
            :tone="($report['daemons']['inactive_count'] ?? 0) > 0 ? 'amber' : null"
            :title="__('Workers')"
            :count="($report['daemons']['inactive_count'] ?? 0) > 0 ? $report['daemons']['inactive_count'] : null"
            :note="__('Supervisor programs marked inactive.')"
            class="border-b border-brand-ink/10"
        >
            @if (($report['daemons']['inactive_count'] ?? 0) > 0)
                <x-slot:actions>
                    <a
                        href="{{ route('servers.workers', $server) }}"
                        wire:navigate
                        class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        {{ __('Open workers') }}
                        <x-heroicon-m-arrow-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                    </a>
                </x-slot:actions>
            @endif
        </x-workspace-panel-head>

        @if (($report['daemons']['inactive_count'] ?? 0) === 0)
            <x-empty-state
                borderless
                compact
                tone="sage"
                icon="heroicon-o-check-circle"
                :title="__('All workers active')"
                :description="__('All :count configured programs are active.', ['count' => $report['daemons']['total'] ?? 0])"
            />
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($report['daemons']['inactive'] as $daemon)
                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-1.5 text-xs sm:px-5">
                        <span class="min-w-0 truncate font-mono font-semibold text-brand-ink">{{ $daemon['slug'] }}</span>
                        @if ($daemon['site_name'])
                            <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                            <span class="min-w-0 truncate text-xs text-brand-moss">{{ $daemon['site_name'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
