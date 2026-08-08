@php
    $engine = $engine ?? 'mysql';
    $engineLabels = $engineLabels ?? ['mysql' => 'MySQL / MariaDB', 'postgres' => 'PostgreSQL'];
    $engineLabel = $engineLabels[$engine] ?? ucfirst($engine);
    $snapshot = is_array($drift_snapshot ?? null) ? ($drift_snapshot[$engine] ?? null) : null;
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }} overflow-hidden">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-arrow-path"
        :title="__(':engine drift', ['engine' => $engineLabel])"
        :note="__('Compare databases tracked in Dply with names visible to the database engine over SSH.')"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <button type="button" wire:click="runDriftAnalysis" wire:loading.attr="disabled" wire:target="runDriftAnalysis" class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50">
                <span wire:loading.remove wire:target="runDriftAnalysis">{{ __('Refresh drift') }}</span>
                <span wire:loading wire:target="runDriftAnalysis" class="inline-flex items-center gap-1">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Refreshing…') }}
                </span>
            </button>
        </x-slot:actions>
    </x-workspace-panel-head>
    <div class="px-4 py-3.5 sm:px-5">
    @if ($snapshot)
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Only in Dply') }}</p>
                <p class="mt-1 font-mono text-xs text-brand-ink">{{ implode(', ', $snapshot['only_in_dply'] ?? []) ?: '—' }}</p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Only on server') }}</p>
                <p class="mt-1 font-mono text-xs text-brand-ink">{{ implode(', ', $snapshot['only_on_server'] ?? []) ?: '—' }}</p>
            </div>
        </div>
    @else
        <x-empty-state
            borderless
            compact
            icon="heroicon-o-arrow-path"
            tone="sage"
            :title="__('Drift not checked yet')"
            :description="__('Click Refresh drift above to compare databases tracked in Dply with names visible on the server.')"
        />
    @endif
    </div>
</div>
