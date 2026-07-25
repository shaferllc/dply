@props([
    'connection' => null,
])

@php
    $items = [
        ['route' => 'live.sites.index', 'match' => 'live.sites.*', 'label' => __('Sites'), 'icon' => 'globe-alt'],
        ['route' => 'live.servers.index', 'match' => 'live.servers.*', 'label' => __('Servers'), 'icon' => 'server'],
        ['route' => 'live.projects.index', 'match' => 'live.projects.*', 'label' => __('Projects'), 'icon' => 'folder'],
        ['route' => 'live.edge.index', 'match' => 'live.edge.*', 'label' => __('Edge'), 'icon' => 'bolt'],
        ['route' => 'live.cloud.index', 'match' => 'live.cloud.*', 'label' => __('Cloud'), 'icon' => 'cloud'],
        ['route' => 'live.serverless.index', 'match' => 'live.serverless.*', 'label' => __('Serverless'), 'icon' => 'cpu-chip'],
    ];
@endphp

<nav class="border-b border-brand-ink/10 bg-brand-cream" aria-label="{{ __('Production') }}">
    <div class="mx-auto flex max-w-7xl gap-0.5 overflow-x-auto px-4 sm:gap-1 sm:px-6 lg:px-8" style="-webkit-overflow-scrolling: touch;">
        @foreach ($items as $item)
            @php $active = request()->routeIs($item['match']); @endphp
            <a
                href="{{ route($item['route']) }}"
                wire:navigate
                @class([
                    'group inline-flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-2.5 py-2.5 text-sm font-medium leading-5 transition duration-150 ease-in-out sm:px-3',
                    'border-brand-ink text-brand-ink' => $active,
                    'border-transparent text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink' => ! $active,
                ])
            >
                <x-dynamic-component
                    :component="'heroicon-o-'.$item['icon']"
                    @class([
                        'h-4 w-4 shrink-0',
                        'text-brand-ink' => $active,
                        'text-brand-moss group-hover:text-brand-ink' => ! $active,
                    ])
                    aria-hidden="true"
                />
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</nav>
