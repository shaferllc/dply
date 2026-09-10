@props([
    /** @var string Value shown and copied. */
    'value' => '',
    /** @var string|null Small label above the value. */
    'label' => null,
    /** @var bool Render on a white chip rather than the sand fill. */
    'plain' => false,
])

{{--
    One credential the operator has to get into an SFTP client by hand.

    select-all makes a click select the whole value (a host or a 24-char
    password is exactly what you do not want to select by dragging), and the
    button is the reliable path. Mirrors the copy affordance in
    <x-cli-snippet>; extracted because the FTP panel needs it per field.
--}}
<div x-data="{ copied: false }" {{ $attributes->class(['min-w-0']) }}>
    @if ($label)
        <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ $label }}</p>
    @endif
    <div class="mt-1 flex min-w-0 items-center gap-1">
        <code class="min-w-0 flex-1 select-all truncate rounded-md px-2 py-1 font-mono text-xs text-brand-ink ring-1 ring-inset ring-brand-ink/10 {{ $plain ? 'bg-white' : 'bg-brand-sand/70' }}" title="{{ $value }}">{{ $value }}</code>
        <button
            type="button"
            class="inline-flex shrink-0 items-center justify-center rounded p-1 text-brand-mist hover:bg-brand-sand hover:text-brand-ink"
            title="{{ __('Copy') }}"
            aria-label="{{ __('Copy :label', ['label' => $label ?? __('value')]) }}"
            @click="navigator.clipboard.writeText(@js($value)); copied = true; setTimeout(() => copied = false, 1500)"
        >
            <x-heroicon-o-clipboard class="h-3.5 w-3.5" x-show="!copied" />
            <x-heroicon-o-check class="h-3.5 w-3.5 text-emerald-700" x-show="copied" x-cloak />
        </button>
    </div>
</div>
