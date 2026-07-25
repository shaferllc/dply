<div>
    <x-sites-index-page
        :rows="$rows"
        :summary="$summary"
        :has-sites-in-scope="$hasSitesInScope"
        :status-options="$statusOptions"
        :sort-options="$sortOptions"
        :breadcrumbs="array_values(array_filter([
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            multi_surface_active()
                ? ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group']
                : null,
            ['label' => __('Sites'), 'icon' => 'globe-alt'],
        ]))"
        :servers-index-url="route('servers.index')"
        empty-state="local"
    />
</div>
