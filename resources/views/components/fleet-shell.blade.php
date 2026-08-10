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
    /** Blade icon component for the sand identity header. */
    'icon' => 'heroicon-o-rectangle-stack',
])

@php
    $crumbs = [
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
    ];

    if ($section !== null) {
        $crumbs[] = ['label' => __('Fleet'), 'href' => route('fleet.index'), 'icon' => 'rectangle-stack'];
        $crumbs[] = ['label' => $section];
    } else {
        $crumbs[] = ['label' => __('Fleet'), 'icon' => 'rectangle-stack'];
    }
@endphp

<div class="contents">
    <x-workspace-nav surface="local" />

    <div class="dply-page-shell {{ $width }} py-8 sm:py-10">
        <x-breadcrumb-trail :items="$crumbs" wrapperClass="mb-5" />

        {{-- Merged chrome: one outer card — sand identity header, flush tabs, hairline body strips. --}}
        <section class="dply-card min-w-0 overflow-hidden p-0">
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
            </div>

            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                @include('livewire.fleet._tabs')
            </div>

            <div class="min-w-0">
                {{ $slot }}
            </div>

            @isset($footer)
                <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-4 sm:px-6">
                    {{ $footer }}
                </div>
            @endisset
        </section>
    </div>
</div>
