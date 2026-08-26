{{-- First tab stop on every page: jumps the keyboard past the workspace nav
     straight to <main id="main-content">. Hidden until focused, so it costs a
     sighted user nothing and saves a keyboard user from tabbing the entire
     nav on every single page load.

     `focus:` rather than `focus-visible:` on purpose — the link is only ever
     reachable by keyboard, and it has to become visible the moment it takes
     focus. z-[130] clears the tooltip (120), toasts (110) and modals (100),
     so it is never the thing that ends up underneath something else. --}}
<a
    href="#main-content"
    class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[130] focus:rounded-lg focus:bg-brand-ink focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-brand-cream focus:shadow-lg focus:outline-none focus:ring-2 focus:ring-brand-gold/50"
>
    {{ __('Skip to content') }}
</a>
