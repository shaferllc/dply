{{-- Nested inside the merged Health card — dense head, no second outer card. --}}
<div>
    <x-workspace-panel-head
        dense
        icon="heroicon-o-rectangle-stack"
        :tone="($report['releases']['sites_over_keep'] ?? 0) > 0 ? 'amber' : null"
        :title="__('Atomic releases')"
        :count="($report['releases']['atomic_site_count'] ?? 0) > 0 ? $report['releases']['atomic_site_count'] : null"
        :note="__('Stored release folders vs each site\'s keep setting.')"
        class="border-b border-brand-ink/10"
    >
        @if (($report['releases']['sites_over_keep'] ?? 0) > 0)
            <x-slot:actions>
                <span class="inline-flex h-6 shrink-0 items-center whitespace-nowrap rounded-full bg-amber-100 px-2 text-xs font-semibold text-amber-900">
                    {{ trans_choice(':count over keep|:count over keep', (int) $report['releases']['sites_over_keep'], ['count' => (int) $report['releases']['sites_over_keep']]) }}
                </span>
            </x-slot:actions>
        @endif
    </x-workspace-panel-head>

    @if (($report['releases']['atomic_site_count'] ?? 0) === 0)
        <x-empty-state
            borderless
            compact
            icon="heroicon-o-rectangle-stack"
            :title="__('No atomic deploy sites')"
            :description="__('Sites using atomic releases will show their stored-vs-kept folder counts here.')"
        />
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($report['releases']['rows'] as $row)
                <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-1.5 text-xs sm:px-5">
                    <span class="min-w-0 truncate font-semibold text-brand-ink">{{ $row['site_name'] }}</span>
                    <span @class([
                        'ml-auto shrink-0 font-mono font-semibold tabular-nums',
                        'text-amber-800' => $row['stored'] > $row['keep'],
                        'text-brand-moss' => $row['stored'] <= $row['keep'],
                    ])>{{ $row['stored'] }} / {{ $row['keep'] }} {{ __('kept') }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
