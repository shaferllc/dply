@php
    /** @var array<string, string> $stats */
    $stats = $stats ?? [];
    $engineLabel = $engineLabel ?? null;
    $titleText = $engineLabel
        ? __(':engine — live stats', ['engine' => $engineLabel])
        : __('Live stats');
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }}">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Stats') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $titleText }}</h2>
            @if (empty($stats))
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Stats unavailable — the engine isn\'t reachable yet, or the CLI tool isn\'t installed on the server.') }}</p>
            @else
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Pulled live from the server. Reload the page to refresh.') }}</p>
            @endif
        </div>
    </div>
    @if (! empty($stats))
        <div class="px-6 py-6 sm:px-7">
            <dl class="grid gap-4 sm:grid-cols-2 md:grid-cols-4">
                @foreach ($stats as $label => $value)
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ $label }}</dt>
                        <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif
</div>
