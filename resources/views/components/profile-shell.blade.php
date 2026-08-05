@props([
    'title',
    'description' => null,
    /** Blade icon component for the sand identity header. */
    'icon' => 'heroicon-o-user-circle',
])

{{-- Content chrome only — settings layout already owns the sidebar/nav.
     Same merged composition as organization-shell / fleet-shell body. --}}
<section {{ $attributes->merge(['class' => 'dply-card min-w-0 overflow-hidden p-0']) }}>
    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-dynamic-component :component="$icon" class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold tracking-tight text-brand-ink">{{ $title }}</h1>
                    @if ($description)
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $description }}</p>
                    @endif
                </div>
            </div>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>

        @isset($stats)
            <div class="mt-5">
                {{ $stats }}
            </div>
        @endisset
    </div>

    @isset($tabs)
        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            {{ $tabs }}
        </div>
    @endisset

    <div class="min-w-0">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-4 sm:px-6">
            {{ $footer }}
        </div>
    @endisset
</section>
