@php
    $items = [
        ['route' => 'sites.index', 'match' => 'sites.index', 'label' => __('Sites'), 'icon' => 'globe-alt'],
        ['route' => 'servers.index', 'match' => 'servers.index', 'label' => __('Servers'), 'icon' => 'server'],
        // Projects is deliberately absent: this row is labelled Compute and
        // Projects is a grouping container, not compute. It keeps its header
        // entry point under Apps. Don't re-add it here without renaming the
        // row. Cross-product ops views live under /infrastructure.
        // Backups moved to the Services row — it is a managed capability,
        // not compute (docs/adr/managed-services-tier.md, decision 1).
    ];
@endphp

{{--
    The Compute row: the machines your code runs on. Carries the same eyebrow
    label + tab-strip shape as <x-services-index-nav> so the two rows read as
    one navigation in two registers rather than two unrelated bars. The label
    widths are pinned equal (sm:w-20) so both tab strips start on the same
    column. Differentiation is carried by weight, not by shape: white ground
    against the services row's sand tint, larger type, larger icons, and an
    ink-toned label against the services row's muted moss.
--}}

<nav class="border-b border-brand-ink/10 bg-white" aria-label="{{ __('Workspace') }}">
    <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
        <span class="hidden shrink-0 text-[11px] font-semibold uppercase tracking-wider text-brand-ink/60 sm:inline sm:w-20">
            {{ __('Compute') }}
        </span>
        <div class="flex min-w-0 flex-1 gap-0.5 overflow-x-auto sm:gap-1" style="-webkit-overflow-scrolling: touch;">
            @foreach ($items as $item)
                @php
                    $routeExists = \Illuminate\Support\Facades\Route::has($item['route']);
                    $featureOk = empty($item['feature']) || feature($item['feature']);
                    $active = $routeExists && request()->routeIs($item['match']);
                @endphp
                @if ($routeExists && $featureOk)
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

        @if (request()->routeIs('servers.index'))
            @can('create', App\Models\Server::class)
                <a
                    href="{{ route('servers.create') }}"
                    wire:navigate
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Add server') }}
                </a>
            @endcan
        @endif
    </div>
</nav>
