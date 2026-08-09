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
    $blockedCount = count(array_filter($siteRows, static fn (array $row): bool => ($row['status'] ?? null) === 'blocked'));
@endphp

{{-- Nested inside the merged Deploys card — dense head over hairline rows. Each
     site was a three-line stack (name / hostname / detail) with a stacked pill
     and link beside it; it's one line now. --}}
<div>
    <x-workspace-panel-head
        dense
        icon="heroicon-o-globe-alt"
        :tone="$blockedCount > 0 ? 'amber' : null"
        :title="__('Site coverage')"
        :count="$siteRows !== [] ? count($siteRows) : null"
        :note="__('Every site on this server inherits the same deploy window policy.')"
        class="border-b border-brand-ink/10"
    />

    @if ($siteRows === [])
        <x-empty-state
            borderless
            compact
            icon="heroicon-o-globe-alt"
            :title="__('No sites on this server yet')"
            :description="__('Sites added here inherit the deploy window policy automatically.')"
        />
    @else
        <ul class="divide-y divide-brand-ink/8">
            @foreach ($siteRows as $row)
                <li wire:key="policy-site-{{ $row['id'] }}" class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-2 sm:px-5">
                    <p class="shrink-0 text-xs font-semibold text-brand-ink">{{ $row['name'] }}</p>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                    <p class="min-w-0 flex-1 truncate font-mono text-xs text-brand-mist" title="{{ $row['primary_hostname'] }}">{{ $row['primary_hostname'] }}</p>
                    @if ($row['detail'])
                        <p class="min-w-0 shrink truncate text-xs text-amber-900" title="{{ $row['detail'] }}">{{ $row['detail'] }}</p>
                    @endif
                    <span @class(['ml-auto inline-flex shrink-0 items-center rounded-full px-1.5 py-0.5 text-2xs font-semibold ring-1', $statusTone($row['status'])])>
                        {{ $row['status_label'] }}
                    </span>
                    <a href="{{ $row['show_url'] }}" wire:navigate class="shrink-0 text-xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('Workspace') }}</a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
