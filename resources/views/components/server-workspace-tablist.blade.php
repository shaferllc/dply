@props([
    /** @var string|null Accessible name for the tab strip */
    'ariaLabel' => null,
    /** When true, keep tabs on a single line and scroll horizontally instead of wrapping. */
    'scroll' => false,
    /**
     * Drop the strip's own card chrome (border + white fill + padding + shadow)
     * for strips that sit inside a padded row on a card that already provides
     * it. Call sites used to pass `border-0 bg-transparent p-0 shadow-none`,
     * but $attributes->class() concatenates instead of merging — Tailwind emits
     * `p-1` after `p-0` and `border` after `border-0`, so the defaults won and
     * the strip rendered as a nested white box with doubled padding.
     */
    'bare' => false,
])

<nav
    {{ $attributes->class([
        'items-center gap-1',
        'mb-6 rounded-xl border border-brand-ink/10 bg-white p-1 shadow-sm' => ! $bare,
        'inline-flex max-w-full flex-wrap' => ! $scroll,
        'dply-tablist-scroll flex w-full min-w-0 max-w-full flex-nowrap overflow-x-auto overscroll-x-contain [&>*]:shrink-0' => $scroll,
    ]) }}
    role="tablist"
    aria-label="{{ $ariaLabel ?? __('Workspace sections') }}"
>
    {{ $slot }}
</nav>
