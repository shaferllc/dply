{{--
    Lazy-load skeleton for Files. Mirrors the merged page (hide-hero + single
    card with identity, browser chrome, and listing stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="files"
    :title="__('Files')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading files…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Files') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Read-only filesystem browser over SSH. View text or image previews and download files.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="space-y-2.5 border-b border-brand-ink/10 px-4 py-3 sm:px-5" aria-hidden="true">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div class="flex min-w-0 flex-1 items-center gap-1">
                    <span class="h-9 w-9 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
                    <span class="h-9 min-w-0 flex-1 animate-pulse rounded-lg bg-brand-ink/10"></span>
                </div>
                <div class="flex gap-2">
                    <span class="h-9 w-20 animate-pulse rounded-lg bg-brand-ink/10"></span>
                    <span class="h-9 w-28 animate-pulse rounded-lg bg-brand-ink/10"></span>
                </div>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div class="flex flex-1 flex-wrap gap-1.5">
                    @foreach (range(1, 3) as $chip)
                        <span class="h-7 w-16 animate-pulse rounded-md bg-brand-ink/10"></span>
                    @endforeach
                </div>
                <span class="h-9 w-full animate-pulse rounded-lg bg-brand-ink/10 sm:w-56"></span>
            </div>
        </div>

        <div class="overflow-hidden" aria-hidden="true">
            <div class="border-b border-brand-ink/10 bg-brand-sand/30 px-4 py-2.5">
                <div class="flex gap-8">
                    @foreach (range(1, 5) as $col)
                        <span class="h-2.5 w-14 animate-pulse rounded bg-brand-ink/10"></span>
                    @endforeach
                </div>
            </div>
            @foreach (range(1, 8) as $row)
                <div class="flex items-center gap-8 border-b border-brand-ink/10 px-4 py-3">
                    <span class="h-3 w-40 max-w-[40%] animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="h-3 w-12 animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="h-3 w-20 animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="ms-auto h-3 w-16 animate-pulse rounded bg-brand-ink/10"></span>
                </div>
            @endforeach
        </div>
    </section>
</x-server-workspace-layout>
