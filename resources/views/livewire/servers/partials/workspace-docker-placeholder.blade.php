{{--
    Lazy-load skeleton for Docker. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and overview stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="docker"
    :title="__('Docker')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading Docker…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-800 ring-1 ring-sky-200">
                    <x-heroicon-o-square-3-stack-3d class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Docker') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Manage Docker Engine on this server — containers, images, volumes, networks, compose projects, and cleanup.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Overview'), __('Containers'), __('Images'), __('Volumes'), __('Networks'), __('Compose'), __('Maintenance')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10" aria-hidden="true">
            <div class="flex items-start justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
                <div class="flex items-start gap-3">
                    <span class="h-10 w-10 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                    <div class="space-y-2">
                        <div class="h-3.5 w-32 animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </div>
                <span class="h-6 w-20 animate-pulse rounded-full bg-brand-ink/10"></span>
            </div>
            <dl class="grid gap-px bg-brand-ink/10 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (range(1, 4) as $cell)
                    <div class="bg-white px-5 py-4">
                        <div class="h-2.5 w-16 animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="mt-2 h-5 w-12 animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                @endforeach
            </dl>
        </div>

        <div class="grid gap-2 px-5 py-5 sm:grid-cols-2 sm:px-6 lg:grid-cols-3" aria-hidden="true">
            @foreach (range(1, 6) as $tile)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <div class="space-y-2">
                        <div class="h-3.5 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</x-server-workspace-layout>
