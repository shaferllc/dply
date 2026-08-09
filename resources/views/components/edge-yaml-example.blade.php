@props([
    /** @var string Filename shown in the caption (e.g. dply.yaml). */
    'file' => 'dply.yaml',
    /** @var string|null Optional short hint above the snippet. */
    'hint' => null,
])

@php
    $code = trim((string) $slot);
@endphp

<div {{ $attributes->class(['space-y-1.5']) }} x-data="{ copied: false }">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">
            {{ __('Example :file', ['file' => $file]) }}
        </p>
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-2 py-1 text-xs font-medium text-brand-moss hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900"
            @click="navigator.clipboard.writeText(@js($code)); copied = true; setTimeout(() => copied = false, 2000)"
        >
            <x-heroicon-o-clipboard class="h-3.5 w-3.5" aria-hidden="true" />
            <span x-show="!copied">{{ __('Copy') }}</span>
            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
        </button>
    </div>
    @if ($hint)
        <p class="text-xs text-brand-moss">{{ $hint }}</p>
    @endif
    <pre class="overflow-x-auto rounded-lg bg-brand-ink/95 px-3 py-2.5 font-mono text-xs leading-relaxed text-brand-sand"><code>{{ $code }}</code></pre>
</div>
