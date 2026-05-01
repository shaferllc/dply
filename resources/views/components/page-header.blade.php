@props([
    'eyebrow' => null,
    'title',
    'description' => null,
    'flush' => false,
    'compact' => false,
    /** When true, title block stays left and docs + actions align right on large screens (dashboard-style). */
    'toolbar' => false,
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
    <div @class([
        'flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-8' => $toolbar,
    ])>
        <div @class([
            'max-w-3xl' => ! $toolbar,
            'min-w-0 w-full max-w-3xl flex-1' => $toolbar,
        ])>
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
            <div @class([
                'mt-4 flex flex-wrap items-center gap-2' => ! $toolbar,
                'flex flex-wrap items-center gap-2 lg:shrink-0 lg:justify-end' => $toolbar,
            ])>
                @if ($docRoute)
                    <x-outline-link
                        href="{{ $docSlug !== null ? route($docRoute, ['slug' => $docSlug]) : route($docRoute) }}"
                        wire:navigate
                    >
                        <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ $docLinkLabel }}
                    </x-outline-link>
                @endif
                @isset($actions)
                    {{ $actions }}
                @endisset
            </div>
        @endif
    </div>
</header>
