@php
    /** @var array<string, string> $stats */
    $stats = $stats ?? [];
    $engineLabel = $engineLabel ?? null;
    $titleText = $engineLabel
        ? __(':engine — live stats', ['engine' => $engineLabel])
        : __('Live stats');
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }} p-6 sm:p-8">
    <h2 class="text-base font-semibold text-brand-ink">{{ $titleText }}</h2>
    @if (empty($stats))
        <p class="mt-2 text-sm text-brand-moss">{{ __('Stats unavailable — the engine isn\'t reachable yet, or the CLI tool isn\'t installed on the server.') }}</p>
    @else
        <p class="mt-2 text-sm text-brand-moss">{{ __('Pulled live from the server. Reload the page to refresh.') }}</p>
        <dl class="mt-6 grid gap-4 sm:grid-cols-2 md:grid-cols-4">
            @foreach ($stats as $label => $value)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ $label }}</dt>
                    <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>
