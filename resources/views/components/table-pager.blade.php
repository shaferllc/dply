@props([
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator */
    'paginator',
    /** Page-name the host component paginates with (`->paginate(…, 'tickPage')`). */
    'pageName' => 'page',
    /** Row noun for the count, e.g. "ticks". */
    'noun' => null,
])

{{-- Compact pager for dense workspace tables: one line, range + prev/next.
     The full Tailwind paginator is a block of numbered links — too much
     furniture inside a card that is itself one row of a settings page. --}}
@if ($paginator->hasPages())
    <div {{ $attributes->class(['flex flex-wrap items-center justify-between gap-2 text-2xs text-brand-moss']) }}>
        <span>
            {{ __(':first–:last of :total', [
                'first' => $paginator->firstItem(),
                'last' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ]) }}
            @if ($noun)
                <span class="text-brand-mist">{{ $noun }}</span>
            @endif
        </span>

        <div class="flex items-center gap-1">
            <button
                type="button"
                wire:click="previousPage('{{ $pageName }}')"
                @disabled($paginator->onFirstPage())
                class="inline-flex items-center rounded-md border border-brand-ink/15 bg-white p-1 text-brand-ink hover:bg-brand-sand/50 disabled:cursor-default disabled:opacity-40"
                aria-label="{{ __('Previous page') }}"
            >
                <x-heroicon-m-chevron-left class="h-3.5 w-3.5" aria-hidden="true" />
            </button>

            <span class="px-1 font-mono">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

            <button
                type="button"
                wire:click="nextPage('{{ $pageName }}')"
                @disabled(! $paginator->hasMorePages())
                class="inline-flex items-center rounded-md border border-brand-ink/15 bg-white p-1 text-brand-ink hover:bg-brand-sand/50 disabled:cursor-default disabled:opacity-40"
                aria-label="{{ __('Next page') }}"
            >
                <x-heroicon-m-chevron-right class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
        </div>
    </div>
@endif
