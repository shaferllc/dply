@php
    $engine = $engine ?? 'mysql';
    $engineLabels = $engineLabels ?? ['mysql' => 'MySQL / MariaDB', 'postgres' => 'PostgreSQL'];
    $engineLabel = $engineLabels[$engine] ?? ucfirst($engine);
    $snapshot = is_array($drift_snapshot ?? null) ? ($drift_snapshot[$engine] ?? null) : null;
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }} p-6 sm:p-8">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-base font-semibold text-brand-ink">{{ __(':engine drift', ['engine' => $engineLabel]) }}</h2>
            <p class="mt-2 text-sm text-brand-moss">{{ __('Compare databases tracked in Dply with names visible to the database engine over SSH.') }}</p>
        </div>
        <button type="button" wire:click="runDriftAnalysis" wire:loading.attr="disabled" wire:target="runDriftAnalysis" class="rounded-xl border border-brand-ink/15 bg-brand-sand/30 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">
            <span wire:loading.remove wire:target="runDriftAnalysis">{{ __('Refresh drift') }}</span>
            <span wire:loading wire:target="runDriftAnalysis">{{ __('Refreshing…') }}</span>
        </button>
    </div>
    @if ($snapshot)
        <div class="mt-6 grid gap-6 sm:grid-cols-2">
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
        <p class="mt-3 text-sm text-brand-moss">{{ __('Click Refresh drift to see what differs between Dply and the server.') }}</p>
    @endif
</div>
