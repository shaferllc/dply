{{--
    Lazy-load skeleton for Files. Mirrors the merged page (hide-hero + single
    card with identity, browser chrome, and listing stubs) — same compact head
    and control metrics as the real page so nothing jumps when it swaps in.
--}}
<x-server-workspace-layout
    :server="$server"
    active="files"
    :title="__('Files')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading files…') }}</span>

        {{-- Two chrome rows, matching the real page: the dense panel head, then
             the toolbar. Keep this head in step with workspace-files.blade.php —
             while the page carried an inline title cluster and this mirrored it,
             changing one left the other behind and the card jumped on hydrate. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-folder"
            :title="__('Files')"
            :note="__('Read-only filesystem browser over SSH. View text or image previews and download files.')"
            class="border-b border-brand-ink/10"
        />

        <div class="border-b border-brand-ink/10 px-4 py-1.5 sm:px-5" aria-hidden="true">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="h-7 w-7 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
                <span class="h-7 min-w-[10rem] flex-1 animate-pulse rounded-lg bg-brand-ink/10"></span>
                <span class="h-7 w-44 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
                <span class="h-7 w-full shrink-0 animate-pulse rounded-lg bg-brand-ink/10 sm:w-48"></span>
                <span class="h-7 w-20 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
                <span class="h-7 w-24 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
            </div>
        </div>

        <div class="overflow-hidden" aria-hidden="true">
            <div class="border-b border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5">
                <div class="flex gap-8">
                    @foreach (range(1, 5) as $col)
                        <span class="h-2.5 w-14 animate-pulse rounded bg-brand-ink/10"></span>
                    @endforeach
                </div>
            </div>
            @foreach (range(1, 8) as $row)
                <div class="flex items-center gap-8 border-b border-brand-ink/10 px-3 py-1.5">
                    <span class="h-3 w-40 max-w-[40%] animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="h-3 w-12 animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="h-3 w-20 animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="ms-auto h-3 w-16 animate-pulse rounded bg-brand-ink/10"></span>
                </div>
            @endforeach
        </div>
    </section>
</x-server-workspace-layout>
