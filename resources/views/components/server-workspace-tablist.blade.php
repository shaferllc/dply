@props([
    /** @var string|null Accessible name for the tab strip */
    'ariaLabel' => null,
    /** When true, keep tabs on a single line and scroll horizontally instead of wrapping. */
    'scroll' => false,
])

<nav
    {{ $attributes->class([
        'mb-6 items-center gap-1.5 rounded-xl border border-brand-ink/10 bg-white p-1.5 shadow-sm',
        'inline-flex max-w-full flex-wrap' => ! $scroll,
        'flex w-full min-w-0 max-w-full flex-nowrap overflow-x-auto overscroll-x-contain [scrollbar-width:thin] [&>*]:shrink-0' => $scroll,
    ]) }}
    role="tablist"
    aria-label="{{ $ariaLabel ?? __('Workspace sections') }}"
>
    {{ $slot }}
</nav>
