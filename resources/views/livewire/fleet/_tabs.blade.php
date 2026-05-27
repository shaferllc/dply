@php
    $tabs = [
        ['name' => 'fleet.health', 'label' => __('Health'), 'icon' => 'heroicon-o-heart'],
        ['name' => 'fleet.deploys', 'label' => __('Deploys'), 'icon' => 'heroicon-o-rocket-launch'],
        ['name' => 'fleet.domains', 'label' => __('Domains'), 'icon' => 'heroicon-o-globe-alt'],
        ['name' => 'fleet.env-search', 'label' => __('Env search'), 'icon' => 'heroicon-o-key'],
        ['name' => 'fleet.env-drift', 'label' => __('Env drift'), 'icon' => 'heroicon-o-arrows-right-left'],
    ];
@endphp
<nav
    class="mb-6 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-brand-ink/10 pb-px [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    aria-label="{{ __('Fleet sections') }}"
>
    @foreach ($tabs as $tab)
        @php($isActive = request()->routeIs($tab['name']))
        <a
            href="{{ route($tab['name']) }}"
            wire:navigate
            @class([
                'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-t-lg px-3 py-2.5 text-sm font-medium transition-colors -mb-px border-b-2',
                'border-brand-forest text-brand-ink' => $isActive,
                'border-transparent text-brand-moss hover:border-brand-ink/15 hover:text-brand-ink' => ! $isActive,
            ])
        >
            <x-dynamic-component :component="$tab['icon']" class="h-4 w-4 shrink-0 opacity-90" />
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
