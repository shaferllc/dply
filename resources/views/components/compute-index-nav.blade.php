@props([
    /** local|production — same chrome; only route targets differ. */
    'surface' => 'local',
])

@php
    $surface = $surface === 'production' ? 'production' : 'local';
    $items = $surface === 'production'
        ? [
            ['route' => 'live.sites.index', 'match' => 'live.sites.*', 'label' => __('Sites'), 'icon' => 'globe-alt'],
            ['route' => 'live.servers.index', 'match' => 'live.servers.*', 'label' => __('Servers'), 'icon' => 'server'],
            ['route' => 'live.projects.index', 'match' => 'live.projects.*', 'label' => __('Projects'), 'icon' => 'folder'],
            ['route' => 'live.edge.index', 'match' => 'live.edge.*', 'label' => __('Edge'), 'icon' => 'bolt'],
            ['route' => 'live.cloud.index', 'match' => 'live.cloud.*', 'label' => __('Cloud'), 'icon' => 'cloud'],
            ['route' => 'live.serverless.index', 'match' => 'live.serverless.*', 'label' => __('Serverless'), 'icon' => 'cpu-chip'],
        ]
        : [
            ['route' => 'sites.index', 'match' => 'sites.index', 'label' => __('Sites'), 'icon' => 'globe-alt'],
            ['route' => 'servers.index', 'match' => 'servers.index', 'label' => __('Servers'), 'icon' => 'server'],
            ['route' => 'projects.index', 'match' => 'projects.*', 'label' => __('Projects'), 'icon' => 'folder'],
            ['route' => 'edge.index', 'match' => 'edge.*', 'label' => __('Edge'), 'icon' => 'bolt'],
            ['route' => 'cloud.index', 'match' => 'cloud.*', 'label' => __('Cloud'), 'icon' => 'cloud'],
            ['route' => 'serverless.index', 'match' => 'serverless.*', 'label' => __('Serverless'), 'icon' => 'cpu-chip'],
        ];
@endphp

<nav class="border-b border-brand-ink/10 bg-white" aria-label="{{ $surface === 'production' ? __('Production') : __('Workspace') }}">
    <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
        <div class="flex min-w-0 flex-1 gap-0.5 overflow-x-auto sm:gap-1" style="-webkit-overflow-scrolling: touch;">
            @foreach ($items as $item)
                @php
                    $routeExists = \Illuminate\Support\Facades\Route::has($item['route']);
                    $active = $routeExists && request()->routeIs($item['match']);
                @endphp
                @if ($routeExists)
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
                @endif
            @endforeach
        </div>

        @php
            $onServersIndex = $surface === 'production'
                ? request()->routeIs('live.servers.index')
                : request()->routeIs('servers.index');
        @endphp
        @if ($onServersIndex)
            @can('create', App\Models\Server::class)
                <a
                    href="{{ route('servers.create') }}"
                    wire:navigate
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                    @if ($surface === 'production')
                        title="{{ __('Creates a server in this local workspace — production stays read-only.') }}"
                    @endif
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Add server') }}
                </a>
            @endcan
        @endif
    </div>
</nav>
