<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <div class="flex min-w-0 items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Releases') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Atomic releases') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Stored release folders vs each site\'s keep setting.') }}</p>
            </div>
        </div>
        @if (($report['releases']['sites_over_keep'] ?? 0) > 0)
            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-semibold text-amber-900">
                {{ trans_choice(':count over keep|:count over keep', (int) $report['releases']['sites_over_keep'], ['count' => (int) $report['releases']['sites_over_keep']]) }}
            </span>
        @endif
    </div>
    @if (($report['releases']['atomic_site_count'] ?? 0) === 0)
        <x-empty-state
            borderless
            icon="heroicon-o-rectangle-stack"
            :title="__('No atomic deploy sites')"
            :description="__('Sites using atomic releases will show their stored-vs-kept folder counts here.')"
        />
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($report['releases']['rows'] as $row)
                <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 text-sm sm:px-7">
                    <span class="font-semibold text-brand-ink">{{ $row['site_name'] }}</span>
                    <span @class([
                        'tabular-nums font-semibold',
                        'text-amber-800' => $row['stored'] > $row['keep'],
                        'text-brand-moss' => $row['stored'] <= $row['keep'],
                    ])>{{ $row['stored'] }} / {{ $row['keep'] }} {{ __('kept') }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
