@php
    /** @var array<string, string> $stats */
    $stats = $stats ?? [];
    $engineLabel = $engineLabel ?? null;
    $titleText = $engineLabel
        ? __(':engine — live stats', ['engine' => $engineLabel])
        : __('Live stats');
@endphp
<div class="{{ $card ?? 'border-b border-brand-ink/10' }}">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-chart-bar"
        :title="$titleText"
        :count="! empty($stats) ? (string) count($stats) : null"
        :note="empty($stats)
            ? __('Stats unavailable — the engine isn\'t reachable yet, or the CLI tool isn\'t installed on the server.')
            : __('Pulled live from the server. Reload the page to refresh.')"
        class="border-b border-brand-ink/10"
    />
    @if (! empty($stats))
        <div class="px-4 py-3.5 sm:px-5">
            <dl class="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                @foreach ($stats as $label => $value)
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $label }}</dt>
                        <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif
</div>
