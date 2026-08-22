@php
    $tabs = [
        ['name' => 'infrastructure.index', 'label' => __('Overview'), 'icon' => 'heroicon-o-squares-2x2'],
        ['name' => 'infrastructure.health', 'label' => __('Health'), 'icon' => 'heroicon-o-heart'],
        ['name' => 'infrastructure.deploys', 'label' => __('Deploys'), 'icon' => 'heroicon-o-rocket-launch'],
        ['name' => 'infrastructure.domains', 'label' => __('Domains'), 'icon' => 'heroicon-o-globe-alt'],
        ['name' => 'infrastructure.env-search', 'label' => __('Env search'), 'icon' => 'heroicon-o-key'],
        ['name' => 'infrastructure.env-drift', 'label' => __('Env drift'), 'icon' => 'heroicon-o-arrows-right-left'],
        ['name' => 'infrastructure.intelligence', 'label' => __('Intelligence'), 'icon' => 'heroicon-o-light-bulb'],
        ['name' => 'infrastructure.blast-radius', 'label' => __('Blast radius'), 'icon' => 'heroicon-o-share'],
        ['name' => 'infrastructure.previews', 'label' => __('Previews'), 'icon' => 'heroicon-o-link'],
    ];
    if (ops_copilot_active()) {
        $tabs[] = ['name' => 'infrastructure.copilot', 'label' => __('Copilot'), 'icon' => 'heroicon-o-sparkles'];
    }
    // Activity is org-admin-gated, so only render the tab if the current
    // user can land on the page without a 403.
    $orgForTimeline = auth()->user()?->currentOrganization();
    $canSeeTimeline = $orgForTimeline !== null && $orgForTimeline->hasAdminAccess(auth()->user());
@endphp
<x-server-workspace-tablist
    :aria-label="__('Infrastructure sections')"
    scroll
    bare class="!mb-0 w-full"
>
    @foreach ($tabs as $tab)
        <x-server-workspace-tab
            as="a"
            href="{{ route($tab['name']) }}"
            wire:navigate
            icon="{{ $tab['icon'] }}"
            :active="request()->routeIs($tab['name'])"
        >
            {{ $tab['label'] }}
        </x-server-workspace-tab>
    @endforeach
    @if ($canSeeTimeline)
        <x-server-workspace-tab
            as="a"
            href="{{ route('organizations.activity', $orgForTimeline) }}"
            wire:navigate
            icon="heroicon-o-clock"
            :active="request()->routeIs('organizations.activity')"
        >
            {{ __('Timeline') }}
        </x-server-workspace-tab>
    @endif
</x-server-workspace-tablist>
