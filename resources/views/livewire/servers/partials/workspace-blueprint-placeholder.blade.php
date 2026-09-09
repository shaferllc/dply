{{--
    Lazy-load skeleton for Blueprint. Mirrors the merged page (hide-hero + a
    single card whose chrome, capture row and library table are each one line) —
    same compact metrics as the real page, so nothing jumps when it swaps in.
    Same rationale as workspace-files-placeholder.
--}}
<x-server-workspace-layout
    :server="$server"
    active="blueprint"
    :title="__('Blueprint')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading blueprint…') }}</span>

        {{-- Two dense panel heads and a form body — the same three bands the
             real page renders, at the same metrics, so nothing resizes. --}}
        @foreach (range(1, 2) as $head)
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4" aria-hidden="true">
                <span class="h-4 w-4 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
                <span class="h-3.5 w-32 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
                <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                <span class="h-2.5 w-64 max-w-[45%] animate-pulse rounded bg-brand-ink/10"></span>
            </div>
        @endforeach

        <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6" aria-hidden="true">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1 basis-64 space-y-2">
                    <span class="block h-2.5 w-28 animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="block h-10 w-full max-w-md animate-pulse rounded-lg bg-brand-ink/10"></span>
                </div>
                <span class="h-9 w-32 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
            </div>
            <span class="mt-2 block h-2.5 w-72 max-w-full animate-pulse rounded bg-brand-ink/10"></span>
        </div>
    </section>

</x-server-workspace-layout>
