@props([
    /** @var array<string, array{label:string, icon?:string}> */
    'tabs' => [],
    /** @var string Currently active tab slug. */
    'active' => '',
    /** @var string Route name to link to (must accept the section param plus everything in routeParams). */
    'routeName' => '',
    /** @var array<string, mixed> Extra params passed to route(). */
    'routeParams' => [],
    /** @var string aria-label for the nav element. */
    'ariaLabel' => '',
    /** @var array<int, string> Tab slugs that should render in danger styling (red). */
    'dangerSlugs' => ['danger'],
])

<nav
    class="mb-6 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-brand-ink/10 pb-px [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    @if ($ariaLabel) aria-label="{{ $ariaLabel }}" @endif
>
    @foreach ($tabs as $slug => $meta)
        @php
            $isActive = $active === $slug;
            $isDanger = in_array($slug, $dangerSlugs, true);
            $icon = $meta['icon'] ?? null;
            $href = route($routeName, array_merge($routeParams, ['section' => $slug]));
        @endphp
        <a
            href="{{ $href }}"
            wire:navigate
            @class([
                'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-t-lg px-3 py-2.5 text-sm font-medium transition-colors -mb-px border-b-2',
                'border-brand-forest text-brand-ink' => $isActive && ! $isDanger,
                'border-red-700 text-red-900' => $isActive && $isDanger,
                'border-transparent text-brand-moss hover:border-brand-ink/15 hover:text-brand-ink' => ! $isActive && ! $isDanger,
                'border-transparent text-red-800/90 hover:border-red-200 hover:text-red-950' => ! $isActive && $isDanger,
            ])
        >
            @if ($icon)
                <x-dynamic-component :component="'heroicon-o-' . $icon" class="h-4 w-4 shrink-0 opacity-90" />
            @endif
            {{ __($meta['label']) }}
        </a>
    @endforeach
</nav>
