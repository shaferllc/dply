@props([
    /** @var string|null Accessible name for the tab strip */
    'ariaLabel' => null,
])

<div
    {{ $attributes->class('mb-6 flex min-w-0 w-full flex-nowrap items-stretch gap-2 overflow-x-auto overscroll-x-contain scroll-smooth border-b border-brand-ink/10 [-webkit-overflow-scrolling:touch]') }}
    role="tablist"
    aria-label="{{ $ariaLabel ?? __('Workspace sections') }}"
>
    {{ $slot }}
</div>
