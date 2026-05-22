@php
    $tabs = [
        ['name' => 'fleet.health', 'label' => __('Health'), 'icon' => 'heroicon-o-heart'],
        ['name' => 'fleet.deploys', 'label' => __('Deploys'), 'icon' => 'heroicon-o-rocket-launch'],
        ['name' => 'fleet.domains', 'label' => __('Domains'), 'icon' => 'heroicon-o-globe-alt'],
        ['name' => 'fleet.env-search', 'label' => __('Env search'), 'icon' => 'heroicon-o-key'],
    ];
@endphp
<nav class="mb-6 flex flex-wrap gap-2 border-b border-slate-200 pb-3" aria-label="{{ __('Fleet sections') }}">
    @foreach ($tabs as $tab)
        @php($isActive = request()->routeIs($tab['name']))
        <a href="{{ route($tab['name']) }}" wire:navigate @class([
            'inline-flex items-center gap-2 rounded-xl px-3 py-1.5 text-sm font-medium transition',
            'bg-slate-900 text-white' => $isActive,
            'border border-slate-200 text-slate-700 hover:bg-slate-50' => ! $isActive,
        ])>
            <x-dynamic-component :component="$tab['icon']" class="h-4 w-4" />
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
