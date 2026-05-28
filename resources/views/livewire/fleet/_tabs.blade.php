@php
    $tabs = [
        ['name' => 'fleet.health', 'label' => __('Health'), 'icon' => 'heroicon-o-heart'],
        ['name' => 'fleet.deploys', 'label' => __('Deploys'), 'icon' => 'heroicon-o-rocket-launch'],
        ['name' => 'fleet.domains', 'label' => __('Domains'), 'icon' => 'heroicon-o-globe-alt'],
        ['name' => 'fleet.env-search', 'label' => __('Env search'), 'icon' => 'heroicon-o-key'],
        ['name' => 'fleet.env-drift', 'label' => __('Env drift'), 'icon' => 'heroicon-o-arrows-right-left'],
        ['name' => 'fleet.intelligence', 'label' => __('Intelligence'), 'icon' => 'heroicon-o-light-bulb'],
        ['name' => 'fleet.blast-radius', 'label' => __('Blast radius'), 'icon' => 'heroicon-o-share'],
        ['name' => 'fleet.previews', 'label' => __('Previews'), 'icon' => 'heroicon-o-link'],
    ];
    if (ops_copilot_active()) {
        $tabs[] = ['name' => 'fleet.copilot', 'label' => __('Copilot'), 'icon' => 'heroicon-o-sparkles'];
    }
    // Activity is org-admin-gated, so only render the tab if the current
    // user can land on the page without a 403.
    $orgForTimeline = auth()->user()?->currentOrganization();
    $canSeeTimeline = $orgForTimeline !== null && $orgForTimeline->hasAdminAccess(auth()->user());
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
    @if ($canSeeTimeline)
        @php($isActive = request()->routeIs('organizations.activity'))
        <a
            href="{{ route('organizations.activity', $orgForTimeline) }}"
            wire:navigate
            @class([
                'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-t-lg px-3 py-2.5 text-sm font-medium transition-colors -mb-px border-b-2',
                'border-brand-forest text-brand-ink' => $isActive,
                'border-transparent text-brand-moss hover:border-brand-ink/15 hover:text-brand-ink' => ! $isActive,
            ])
        >
            <x-heroicon-o-clock class="h-4 w-4 shrink-0 opacity-90" />
            {{ __('Timeline') }}
        </a>
    @endif
</nav>
