{{--
    Lazy-load skeleton for Blueprint. Mirrors the merged page (hide-hero +
    single card with identity, snapshot form, and library stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="blueprint"
    :title="__('Blueprint')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading blueprint…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-document-duplicate class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Blueprint') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Save this server\'s reconciled stack as a golden blueprint for the next VM you provision.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="border-b border-brand-ink/10" aria-hidden="true">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
                <span class="h-10 w-10 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="h-2.5 w-28 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-3.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-2.5 w-72 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            </div>
            <div class="space-y-4 px-5 py-6 sm:px-6">
                <div class="space-y-2">
                    <div class="h-2.5 w-28 animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-10 w-full max-w-md animate-pulse rounded-lg bg-brand-ink/10"></div>
                </div>
                <div class="h-9 w-36 animate-pulse rounded-lg bg-brand-ink/10"></div>
            </div>
        </div>
    </section>
</x-server-workspace-layout>
