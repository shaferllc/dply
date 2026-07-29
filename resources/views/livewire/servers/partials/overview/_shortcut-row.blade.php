{{-- One row in the server-overview shortcut lists (attention / info).
     Severity tints the leading icon badge and the row background; the
     dynamic icon lets every caller reuse the same markup. --}}
@props([
    'icon',
    'label',
    'headline',
    'href',
    'cta' => 'Open',
    'ctaIcon' => 'heroicon-m-arrow-up-right',
    'severity' => null,
])
@php
    $iconTone = match ($severity) {
        'critical' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'warning' => 'bg-amber-100 text-amber-700 ring-amber-200',
        default => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
    };
@endphp
<a href="{{ $href }}" wire:navigate @class([
    'group flex items-center gap-3 px-6 py-3.5 transition hover:bg-brand-sand/30 sm:px-7',
    'bg-rose-50/40' => $severity === 'critical',
    'bg-amber-50/40' => $severity === 'warning',
])>
    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 {{ $iconTone }}">
        <x-dynamic-component :component="$icon" class="h-5 w-5" aria-hidden="true" />
    </span>
    <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-semibold text-brand-ink">{{ $headline }}</p>
        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ $label }}</p>
    </div>
    <span class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap text-xs font-semibold text-brand-ink/70 transition group-hover:text-brand-ink">
        {{ $cta }}
        <x-dynamic-component :component="$ctaIcon" class="h-4 w-4 shrink-0" aria-hidden="true" />
    </span>
</a>
