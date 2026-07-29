{{--
    Lazy-load skeleton for Access graph. Mirrors the merged page (hide-hero +
    single card with identity and section stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="ssh-access"
    :title="__('Access graph')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading access graph…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-finger-print class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Access graph') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Who had SSH access on this server over time — your keys, temporary sessions, and when Dply accessed the server to run jobs.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="border-b border-brand-ink/10" aria-hidden="true">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
                <span class="h-10 w-10 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="h-3.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-2.5 w-72 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3 px-5 py-8 sm:px-6">
                @foreach (range(1, 3) as $col)
                    <div class="space-y-3">
                        @foreach (range(1, 3) as $row)
                            <div class="h-14 animate-pulse rounded-xl bg-brand-ink/10"></div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        <div class="border-b border-brand-ink/10" aria-hidden="true">
            <div class="flex items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
                <div class="flex items-start gap-3">
                    <span class="h-10 w-10 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </div>
                <div class="flex gap-1.5">
                    @foreach (range(1, 3) as $pill)
                        <span class="h-7 w-14 animate-pulse rounded-full bg-brand-ink/10"></span>
                    @endforeach
                </div>
            </div>
            <div class="space-y-3 px-5 py-5 sm:px-6">
                <div class="h-40 w-full animate-pulse rounded-xl bg-brand-ink/10"></div>
                <div class="h-3 w-32 animate-pulse rounded bg-brand-ink/10"></div>
            </div>
        </div>

        <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex items-start gap-3">
                <span class="h-10 w-10 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="h-3.5 w-44 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-2.5 w-64 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            </div>
        </div>
    </section>
</x-server-workspace-layout>
