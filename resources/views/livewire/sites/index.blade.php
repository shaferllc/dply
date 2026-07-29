<div class="contents">
    <x-compute-index-nav surface="local" />

    <x-sites-index-page
        :rows="$rows"
        :summary="$summary"
        :has-sites-in-scope="$hasSitesInScope"
        :status-options="$statusOptions"
        :sort-options="$sortOptions"
        :status-filter="$statusFilter"
        :sort="$sort"
        :breadcrumbs="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Sites'), 'icon' => 'globe-alt'],
        ]"
        :servers-index-url="route('servers.index')"
        empty-state="local"
    />
</div>
