@props([
    /** Page heading. */
    'title',
    /** Optional supporting description under the heading. */
    'description' => null,
    /**
     * Current section label for the breadcrumb trail. When null the page is
     * treated as the Fleet ops landing page (no deeper crumb).
     */
    'section' => null,
    /** Constrain the body width; matches the app's wide page shell by default. */
    'width' => 'max-w-7xl',
])

@php
    $crumbs = [
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
    ];

    if ($section !== null) {
        $crumbs[] = ['label' => __('Fleet ops'), 'href' => route('fleet.index'), 'icon' => 'rectangle-stack'];
        $crumbs[] = ['label' => $section];
    } else {
        $crumbs[] = ['label' => __('Fleet ops'), 'icon' => 'rectangle-stack'];
    }
@endphp

<div class="dply-page-shell {{ $width }} py-8 sm:py-10">
    <x-breadcrumb-trail :items="$crumbs" wrapperClass="mb-5" />

    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="max-w-2xl">
            <h1 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ $title }}</h1>
            @if ($description)
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ $description }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </header>

    @include('livewire.fleet._tabs')

    {{ $slot }}
</div>
