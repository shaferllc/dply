<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Contention timeline') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent fairness breaks') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Deploys correlated with CPU spikes and dominant site load from the latest attribution scan.') }}</p>
        </div>
    </div>

    @if ($events === [])
        <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">
            {{ __('No contention events in the last seven days.') }}
        </div>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($events as $event)
                @php
                    $eventTone = match ($event['severity'] ?? 'info') {
                        'critical' => $tonePalette['rose'],
                        'warning' => $tonePalette['amber'],
                        default => $tonePalette['sky'],
                    };
                @endphp
                <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 sm:px-7">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 {{ $eventTone }}">
                            <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs text-brand-sage">{{ optional($event['occurred_at'] ?? null)->diffForHumans() ?? '—' }}</p>
                            <p class="text-sm font-semibold text-brand-ink">{{ $event['title'] }}</p>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ $event['message'] }}</p>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        @if ($event['site_href'] ?? null)
                            <a href="{{ $event['site_href'] }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                {{ $event['site_name'] }}
                            </a>
                        @endif
                        @if (($event['action_route'] ?? null) && ($event['action_label'] ?? null))
                            @php
                                $routeName = (string) $event['action_route'];
                                $params = is_array($event['action_params'] ?? null) ? $event['action_params'] : [];
                                $canFollow = $routeName !== 'sites.promote' || $promoteEnabled;
                            @endphp
                            @if ($canFollow && Route::has($routeName))
                                <a href="{{ route($routeName, $params) }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                    {{ $event['action_label'] }}
                                    <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                                </a>
                            @endif
                        @endif
                        @foreach ($event['secondary_actions'] ?? [] as $action)
                            @if (Route::has($action['route'] ?? ''))
                                <a href="{{ route($action['route'], $action['params'] ?? []) }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-medium text-brand-moss hover:bg-brand-sand/40">
                                    {{ $action['label'] ?? '' }}
                                </a>
                            @endif
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
