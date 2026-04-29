@props([
    /** Primary heading (left side of the toolbar row). */
    'title',
    /** Optional muted line under the title (still in the left column). */
    'subtitle' => null,
])

<div {{ $attributes->class(['dply-card overflow-hidden']) }}>
    <div class="p-6 sm:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-semibold text-brand-ink">{{ $title }}</h2>
                @if (filled($subtitle))
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $subtitle }}</p>
                @endif
            </div>
            @if (isset($actions) || isset($search))
                <div class="flex w-full flex-col gap-3 sm:max-w-none lg:w-auto lg:max-w-[min(100%,42rem)] lg:flex-none lg:flex-row lg:items-center lg:justify-end lg:gap-3 lg:pt-0.5">
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
