<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Overall') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                @switch($report['overall'])
                    @case('critical') {{ __('Action needed') }} @break
                    @case('warning') {{ __('Review updates') }} @break
                    @default {{ __('Up to date') }}
                @endswitch
            </h2>
            <p class="mt-1 text-sm text-brand-moss">
                @if ($report['inventory']['checked_at'])
                    {{ __('Last scan :time', ['time' => $report['inventory']['checked_at']->diffForHumans()]) }}
                    @if ($report['inventory']['stale'])
                        · <span class="font-medium text-amber-800">{{ __('stale') }}</span>
                    @endif
                @else
                    {{ __('No inventory scan on record yet.') }}
                @endif
                @if ($report['os']['pretty'])
                    · {{ $report['os']['pretty'] }}
                @endif
            </p>
        </div>
        @if ($opsReady && ! $isDeployer)
            <button
                type="button"
                wire:click="refreshServerInventoryDetails"
                wire:loading.attr="disabled"
                wire:target="refreshServerInventoryDetails"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                    {{ __('Refresh scan') }}
                </span>
                <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" aria-hidden="true" />
                    {{ __('Scanning…') }}
                </span>
            </button>
        @endif
    </div>

    @if ($report['alert_count'] > 0)
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($report['alerts'] as $alert)
                @php
                    $alertTone = match ($alert['severity']) {
                        'critical' => $tonePalette['rose'],
                        'warning' => $tonePalette['amber'],
                        default => $tonePalette['sage'],
                    };
                @endphp
                <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 sm:px-7">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 {{ $alertTone }}">
                            @if ($alert['severity'] === 'critical')
                                <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                            @else
                                <x-heroicon-o-information-circle class="h-4 w-4" aria-hidden="true" />
                            @endif
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-brand-ink">{{ $alert['title'] }}</p>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ $alert['message'] }}</p>
                        </div>
                    </div>
                    @if ($alert['href'] && $alert['link_label'])
                        <a href="{{ $alert['href'] }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                            {{ $alert['link_label'] }}
                            <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">
            {{ __('No patch or reboot alerts from the latest inventory scan.') }}
        </div>
    @endif
</section>

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-chart-bar-square class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Summary') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Patch snapshot') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Key counts from the latest inventory probe.') }}</p>
        </div>
    </div>

    <div class="grid gap-3 px-6 py-5 sm:grid-cols-2 sm:px-7 xl:grid-cols-4">
        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Upgradable') }}</p>
            <p class="mt-2 flex items-baseline gap-2">
                <span class="text-3xl font-semibold tabular-nums text-brand-ink">{{ $report['packages']['total'] ?? '—' }}</span>
                @if ($report['packages']['total'] !== null)
                    <span class="text-xs text-brand-moss">{{ __('packages') }}</span>
                @endif
            </p>
        </div>

        <div @class([
            'rounded-2xl border p-4 shadow-sm',
            'border-red-200/80 bg-red-50/40' => ($report['packages']['security'] ?? 0) > 0,
            'border-brand-ink/10 bg-white' => ($report['packages']['security'] ?? 0) === 0,
        ])>
            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Security') }}</p>
            <p class="mt-2 flex items-baseline gap-2">
                <span @class([
                    'text-3xl font-semibold tabular-nums',
                    'text-red-800' => ($report['packages']['security'] ?? 0) > 0,
                    'text-brand-ink' => ($report['packages']['security'] ?? 0) === 0,
                ])>{{ $report['packages']['security'] ?? 0 }}</span>
                <span class="text-xs text-brand-moss">{{ __('flagged') }}</span>
            </p>
        </div>

        <div @class([
            'rounded-2xl border p-4 shadow-sm',
            'border-amber-200/80 bg-amber-50/40' => $report['reboot']['required'] === true,
            'border-brand-ink/10 bg-white' => $report['reboot']['required'] !== true,
        ])>
            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Reboot pending') }}</p>
            <p class="mt-2 text-lg font-semibold text-brand-ink">
                @if ($report['reboot']['required'] === true)
                    <span class="text-amber-900">{{ __('Yes') }}</span>
                @elseif ($report['reboot']['required'] === false)
                    <span class="text-emerald-700">{{ __('No') }}</span>
                @else
                    <span class="text-brand-moss">—</span>
                @endif
            </p>
        </div>

        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Last apt update') }}</p>
            <p class="mt-2 text-sm font-semibold leading-snug text-brand-ink">
                @if ($report['inventory']['last_apt_update'])
                    {{ $report['inventory']['last_apt_update']->diffForHumans() }}
                @else
                    <span class="text-brand-moss">{{ __('Unknown') }}</span>
                @endif
            </p>
            @if ($report['inventory']['last_apt_update'])
                <p class="mt-1 text-[11px] text-brand-moss">
                    {{ $report['inventory']['last_apt_update']->timezone(config('app.timezone'))->format('Y-m-d H:i T') }}
                </p>
            @endif
        </div>
    </div>

    @if (($report['packages']['total'] ?? 0) > 0 || count($report['packages']['rows']) > 0)
        <div class="border-t border-brand-ink/10 px-6 py-4 sm:px-7">
            <button
                type="button"
                wire:click="setPatchesWorkspaceTab('packages')"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                {{ __('View package list') }}
                <x-heroicon-m-chevron-right class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
        </div>
    @endif
</section>
