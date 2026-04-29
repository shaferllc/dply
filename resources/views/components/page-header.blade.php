@props([
    'eyebrow' => null,
    'title',
    'description' => null,
    'flush' => false,
])

<header @class([
    'dply-page-header' => ! $flush,
    'mb-8',
])>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="max-w-3xl">
            @if ($eyebrow)
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ $eyebrow }}</p>
            @endif

            <h1 class="mt-2 text-2xl font-semibold text-brand-ink">{{ $title }}</h1>

            @if ($description)
                <p class="mt-2 max-w-3xl text-sm leading-6 text-brand-moss">{{ $description }}</p>
            @endif
        </div>

        @if (isset($actions))
            <div class="flex flex-wrap gap-2">
                {{ $actions }}
            </div>
        @endif
    </div>
</header>
