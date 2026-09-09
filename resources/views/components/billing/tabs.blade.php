@props([
    'organization',
    'active' => 'bill',
])

{{-- Billing tab strip, shared by the bill page and the analytics page.

     These are two Livewire components on two routes, not one component with a
     $tab property: the pages answer different questions and neither needs the
     other's state, so navigation is the cheaper join. wire:navigate keeps the
     switch feeling like a tab rather than a page load. --}}
@php
    $tabs = [
        [
            'key' => 'bill',
            'label' => __('Bill & plan'),
            'href' => route('billing.show', $organization),
            'icon' => 'heroicon-o-receipt-percent',
        ],
        [
            'key' => 'analytics',
            'label' => __('Trends'),
            'href' => route('billing.analytics', $organization),
            'icon' => 'heroicon-o-presentation-chart-line',
        ],
    ];
@endphp

<nav class="flex flex-wrap items-center gap-1" aria-label="{{ __('Billing sections') }}">
    @foreach ($tabs as $tab)
        <a
            href="{{ $tab['href'] }}"
            wire:navigate
            @if ($tab['key'] === $active) aria-current="page" @endif
            @class([
                'inline-flex h-7 items-center gap-1.5 rounded-lg px-3 text-xs font-semibold transition-colors',
                'bg-brand-ink text-brand-cream shadow-sm' => $tab['key'] === $active,
                'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $tab['key'] !== $active,
            ])
        >
            <x-dynamic-component :component="$tab['icon']" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
