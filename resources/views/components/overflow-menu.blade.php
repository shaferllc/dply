@props([
    'align' => 'right',
    'label' => null,
])

{{-- Compact kebab "more actions" menu for crowded row action groups: keep the
     one or two primary actions inline next to this, and slot the rest in here so
     a row never shows more than a couple of buttons. Items are plain buttons /
     anchors styled with `dply-overflow-item`; the menu closes on any item click
     (the content wrapper's @click) and on outside click. --}}
<div class="relative" x-data="{ open: false }" {{ $attributes }}>
    <button
        type="button"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open"
        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/10 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
        title="{{ $label ?? __('More actions') }}"
        aria-label="{{ $label ?? __('More actions') }}"
    >
        <x-heroicon-o-ellipsis-horizontal class="h-4 w-4" aria-hidden="true" />
    </button>

    <div
        x-show="open"
        x-cloak
        x-on:click.outside="open = false"
        x-on:click="open = false"
        x-transition
        class="absolute {{ $align === 'left' ? 'left-0' : 'right-0' }} z-30 mt-1 w-48 overflow-hidden rounded-xl border border-brand-ink/10 bg-white py-1 text-left shadow-lg"
    >
        {{ $slot }}
    </div>
</div>
