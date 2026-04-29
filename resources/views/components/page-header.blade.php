@props([
    'eyebrow' => null,
    'title',
    'description' => null,
    'flush' => false,
    'compact' => false,
    /** Named route for contextual docs (e.g. docs.index, docs.markdown). */
    'docRoute' => null,
    /** When docRoute is docs.markdown, pass the slug (e.g. source-control). */
    'docSlug' => null,
    'docLabel' => null,
])

@php
    $docLinkLabel = $docLabel ?? __('Documentation');
@endphp

<header {{ $attributes->class([
    'dply-page-header' => ! $flush,
    'mb-8' => ! $compact,
]) }}>
    <div class="max-w-3xl">
        @isset($leading)
            <div class="flex items-start gap-3">
                {{ $leading }}
                <div class="min-w-0 flex-1">
                    <h1 class="text-2xl font-semibold text-brand-ink">{{ $title }}</h1>

                    @if ($description)
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-brand-moss">{{ $description }}</p>
                    @endif
                </div>
            </div>
        @else
            @if ($eyebrow)
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ $eyebrow }}</p>
            @endif

            <h1 @class([
                'text-2xl font-semibold text-brand-ink',
                'mt-2' => filled($eyebrow),
            ])>{{ $title }}</h1>

            @if ($description)
                <p class="mt-2 max-w-3xl text-sm leading-6 text-brand-moss">{{ $description }}</p>
            @endif
        @endisset
    </div>

    @if ($docRoute || isset($actions))
        <div class="mt-4 flex flex-wrap items-center gap-2">
            @if ($docRoute)
                <a
                    href="{{ $docSlug !== null ? route($docRoute, ['slug' => $docSlug]) : route($docRoute) }}"
                    wire:navigate
                    class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ $docLinkLabel }}
                </a>
            @endif
            @isset($actions)
                {{ $actions }}
            @endisset
        </div>
    @endif
</header>
