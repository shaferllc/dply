<div class="grid gap-6 lg:grid-cols-2">
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploys') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Failed deploys') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Last :days days', ['days' => $report['deployments']['lookback_days'] ?? 7]) }}</p>
            </div>
        </div>
        @if (($report['deployments']['failed_count'] ?? 0) === 0)
            <p class="px-6 py-5 text-sm text-brand-moss sm:px-7">{{ __('No failed deploys in the lookback window.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($report['deployments']['recent'] as $failure)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 text-sm sm:px-7">
                        <span class="font-semibold text-brand-ink">{{ $failure['site_name'] }}</span>
                        <a href="{{ $failure['href'] }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ $failure['at']?->diffForHumans() }}</a>
                    </li>
                @endforeach
            </ul>
            <div class="border-t border-brand-ink/10 px-6 py-3 sm:px-7">
                <a href="{{ route('servers.deploys', $server) }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Open deploy history') }}</a>
            </div>
        @endif
    </section>

    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('TLS') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Certificates') }}</h3>
            </div>
        </div>
        @if (count($report['certificates']['items']) === 0)
            <p class="px-6 py-5 text-sm text-brand-moss sm:px-7">{{ __('No expiring or failed certificates in the warning window.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($report['certificates']['items'] as $cert)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 text-sm sm:px-7">
                        <div>
                            <p class="font-semibold text-brand-ink">{{ $cert['site_name'] }}</p>
                            <p class="text-xs text-brand-moss">{{ $cert['domain'] ?: $cert['status'] }}</p>
                        </div>
                        @if ($cert['href'])
                            <a href="{{ $cert['href'] }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">
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

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-cpu-chip class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Workers') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Workers') }}</h3>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Supervisor programs marked inactive.') }}</p>
        </div>
    </div>
    @if (($report['daemons']['inactive_count'] ?? 0) === 0)
        <p class="px-6 py-5 text-sm text-brand-moss sm:px-7">{{ __('All :count configured programs are active.', ['count' => $report['daemons']['total'] ?? 0]) }}</p>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($report['daemons']['inactive'] as $daemon)
                <li class="px-6 py-3 text-sm sm:px-7">
                    <span class="font-mono font-semibold text-brand-ink">{{ $daemon['slug'] }}</span>
                    @if ($daemon['site_name'])
                        <span class="text-brand-moss"> · {{ $daemon['site_name'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
        <div class="border-t border-brand-ink/10 px-6 py-3 sm:px-7">
            <a href="{{ route('servers.daemons', $server) }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Open workers') }}</a>
        </div>
    @endif
</section>
