@props([
    /** local|production — same chrome; only route targets differ. */
    'surface' => 'local',
])

{{--
    The Services row: managed capabilities your apps lean on, as opposed to the
    compute they run on. Deliberately subordinate to <x-compute-index-nav> —
    smaller type, no bottom border of its own, muted until active — because two
    equal-weight tab bars read as two competing navigations rather than a
    primary and a secondary.

    Grouping only. These three do not share a billing shape: Backups does not
    bill at all, Realtime is flat per-app-per-tier, Queue is per-namespace-per-
    tier and free when it serves Serverless. See
    docs/adr/managed-services-tier.md, decision 2.
--}}

@php
    // Accepted for symmetry with <x-compute-index-nav> and the wrapper, but the
    // targets do not vary: managed services have no production mirror, so
    // <x-production-data-nav> renders the compute row alone and never reaches
    // this component.
    $surface = $surface === 'production' ? 'production' : 'local';

    $items = [
        ['route' => 'backups.databases', 'match' => 'backups.*', 'label' => __('Backups'), 'icon' => 'archive-box', 'feature' => 'workspace.backups'],
        ['route' => 'realtime.index', 'match' => 'realtime.*', 'label' => __('Realtime'), 'icon' => 'signal'],
        ['route' => 'queues.index', 'match' => 'queues.*', 'label' => __('Queues'), 'icon' => 'queue-list', 'feature' => 'surface.queue'],
    ];
@endphp

<nav class="border-b border-brand-ink/10 bg-brand-sand/20" aria-label="{{ __('Services') }}">
    <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
        <span class="hidden shrink-0 text-[11px] font-semibold uppercase tracking-wider text-brand-moss/70 sm:inline">
            {{ __('Services') }}
        </span>
        <div class="flex min-w-0 flex-1 gap-0.5 overflow-x-auto sm:gap-1" style="-webkit-overflow-scrolling: touch;">
            @foreach ($items as $item)
                @php
                    // Same two guards as the compute row: a Queues entry can sit
                    // in this array before its pages exist and simply not render.
                    $routeExists = \Illuminate\Support\Facades\Route::has($item['route']);
                    $featureOk = empty($item['feature']) || feature($item['feature']);
                    $active = $routeExists && request()->routeIs($item['match']);
                @endphp
                @if ($routeExists && $featureOk)
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        @class([
                            'group inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 px-2 py-1.5 text-xs font-medium leading-5 transition duration-150 ease-in-out sm:px-2.5',
                            'border-brand-ink text-brand-ink' => $active,
                            'border-transparent text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink' => ! $active,
                        ])
                    >
                        <x-dynamic-component
                            :component="'heroicon-o-'.$item['icon']"
                            @class([
                                'h-3.5 w-3.5 shrink-0',
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
    </div>
</nav>
