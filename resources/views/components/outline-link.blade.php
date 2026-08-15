@props(['size' => 'default'])

@php
    /*
     | `xxs` is the shell-header action size: the compact pill used beside a
     | page title (Billing & plan, Invoices, Add channel). Fixed height rather
     | than vertical padding so a row of them lines up regardless of whether
     | each has an icon.
     |
     | Note the label stays at text-xs (12px), NOT text-xxs (8px), even though
     | the size is named xxs — the token names the control, not the type. Button
     | labels are readable UI copy and the 12px floor applies; text-xxs is for
     | true micro badges.
     */
    // gap and font-weight live in the size arms, not the shared base: leaving
    // them in both emits two competing utilities on the same element and lets
    // stylesheet order decide, which is not a decision anyone made.
    $sizeClasses = match ($size) {
        'xxs' => 'h-6 gap-1 rounded-md px-2 text-xs font-semibold',
        'sm' => 'gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium',
        default => 'gap-1.5 rounded-xl px-3 py-2 text-sm font-medium',
    };
@endphp

<a {{ $attributes->merge(['class' => 'inline-flex items-center border border-brand-ink/15 bg-white text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 '.$sizeClasses]) }}>
    {{ $slot }}
</a>
