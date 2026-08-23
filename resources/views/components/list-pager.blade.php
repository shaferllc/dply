@props([
    /** Page state, as returned by PaginatesSettingsLists::paginateSettingsList(). */
    'page' => 1,
    'pages' => 1,
    'total' => 0,
    /** Public int property on the component that holds the page number. */
    'property',
    /** Plural noun for the summary line, e.g. "tokens". */
    'label' => null,
])

{{-- Prev/next only: a settings list is a lookup surface, nobody navigates it by
     page number. Hidden entirely at one page so short lists stay quiet. --}}
@if ($pages > 1)
    <div {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2 sm:px-4']) }}>
        <p class="text-xs text-brand-moss">
            {{ $label
                ? __('Page :page of :pages · :total :label', ['page' => $page, 'pages' => $pages, 'total' => $total, 'label' => $label])
                : __('Page :page of :pages', ['page' => $page, 'pages' => $pages]) }}
        </p>
        <div class="flex items-center gap-1.5">
            <button
                type="button"
                wire:click="$set('{{ $property }}', {{ max(1, $page - 1) }})"
                @disabled($page <= 1)
                class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-white"
            >
                <x-heroicon-m-chevron-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Previous') }}
            </button>
            <button
                type="button"
                wire:click="$set('{{ $property }}', {{ min($pages, $page + 1) }})"
                @disabled($page >= $pages)
                class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-white"
            >
                {{ __('Next') }}
                <x-heroicon-m-chevron-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            </button>
        </div>
    </div>
@endif
