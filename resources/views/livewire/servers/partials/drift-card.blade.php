@php
    $engine = $engine ?? 'mysql';
    $engineLabels = $engineLabels ?? ['mysql' => 'MySQL / MariaDB', 'postgres' => 'PostgreSQL'];
    $engineLabel = $engineLabels[$engine] ?? ucfirst($engine);
    $snapshot = is_array($drift_snapshot ?? null) ? ($drift_snapshot[$engine] ?? null) : null;
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }} overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Drift') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine drift', ['engine' => $engineLabel]) }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Compare databases tracked in Dply with names visible to the database engine over SSH.') }}</p>
        </div>
        <button type="button" wire:click="runDriftAnalysis" wire:loading.attr="disabled" wire:target="runDriftAnalysis" class="ml-auto shrink-0 rounded-xl border border-brand-ink/15 bg-brand-sand/30 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">
            <span wire:loading.remove wire:target="runDriftAnalysis">{{ __('Refresh drift') }}</span>
            <span wire:loading wire:target="runDriftAnalysis">{{ __('Refreshing…') }}</span>
        </button>
    </div>
    <div class="px-6 py-6 sm:px-7">
    @if ($snapshot)
        <div class="grid gap-6 sm:grid-cols-2">
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
