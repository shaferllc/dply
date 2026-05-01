@props([
    /** Primary heading (left side of the toolbar row). */
    'title',
    /** Optional muted line under the title (still in the left column). */
    'subtitle' => null,
    /** When true, use a compact header: title + subtitle left, actions/search right from sm (top-aligned), stacked on xs. */
    'stackToolbar' => false,
])

<div {{ $attributes->class(['dply-card overflow-hidden']) }}>
    <div class="p-6 sm:p-8">
        <div @class([
            'flex flex-col gap-4',
            'sm:flex-row sm:items-start sm:justify-between sm:gap-4' => $stackToolbar,
            'lg:flex-row lg:items-start lg:justify-between' => ! $stackToolbar,
        ])>
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-semibold text-brand-ink">{{ $title }}</h2>
                @if (filled($subtitle))
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $subtitle }}</p>
                @endif
            </div>
            @if (isset($actions) || isset($search))
                <div @class([
                    'flex w-full shrink-0 flex-col gap-3 pt-0.5 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center sm:justify-end sm:gap-3' => $stackToolbar,
                    'flex w-full flex-col gap-3 sm:max-w-none lg:w-auto lg:max-w-[min(100%,42rem)] lg:flex-none lg:flex-row lg:items-center lg:justify-end lg:gap-3 lg:pt-0.5' => ! $stackToolbar,
                ])>
                    @isset($actions)
                        <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                            {{ $actions }}
                        </div>
                    @endisset
                    @isset($search)
                        <div class="w-full shrink-0 sm:max-w-xs">
                            {{ $search }}
                        </div>
                    @endisset
                </div>
            @endif
        </div>

        <div class="mt-6 min-w-0">
            {{ $slot }}
        </div>
    </div>
</div>
