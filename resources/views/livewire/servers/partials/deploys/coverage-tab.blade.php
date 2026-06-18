@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'mist' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
    ];

    $statusTone = static function (string $status) use ($tonePalette): string {
        return match ($status) {
            'blocked' => $tonePalette['amber'],
            'allowed' => $tonePalette['emerald'],
            default => $tonePalette['mist'],
        };
    };

    $siteRows = $report['site_rows'] ?? [];
@endphp

<section class="mt-6 dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Coverage') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Site coverage') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Every site on this server inherits the same deploy window policy.') }}</p>
        </div>
    </div>

    @if ($siteRows === [])
        <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-7">{{ __('No sites on this server yet.') }}</div>
    @else
        <ul class="divide-y divide-brand-ink/8">
            @foreach ($siteRows as $row)
                <li wire:key="policy-site-{{ $row['id'] }}" class="flex items-start justify-between gap-3 px-6 py-3.5 sm:px-7">
                    <div class="min-w-0">
                        <p class="font-medium text-brand-ink">{{ $row['name'] }}</p>
                        <p class="mt-0.5 truncate font-mono text-xs text-brand-moss">{{ $row['primary_hostname'] }}</p>
                        @if ($row['detail'])
                            <p class="mt-1 text-xs text-amber-900">{{ $row['detail'] }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1.5">
                        <span @class(['inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1', $statusTone($row['status'])])>
                            {{ $row['status_label'] }}
                        </span>
                        <a href="{{ $row['show_url'] }}" wire:navigate class="text-[11px] font-semibold text-brand-moss hover:text-brand-ink">{{ __('Workspace') }}</a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
