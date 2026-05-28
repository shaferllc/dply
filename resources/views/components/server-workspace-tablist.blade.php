@props([
    /** @var string|null Accessible name for the tab strip */
    'ariaLabel' => null,
])

<nav
    {{ $attributes->class('mb-6 inline-flex max-w-full flex-wrap items-center gap-1.5 rounded-xl border border-brand-ink/10 bg-white p-1.5 shadow-sm') }}
    role="tablist"
    aria-label="{{ $ariaLabel ?? __('Workspace sections') }}"
>
    {{ $slot }}
</nav>
