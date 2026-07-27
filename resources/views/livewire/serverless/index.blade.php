<div class="contents">
    <x-compute-index-nav surface="local" />

    <x-serverless-index-page
        :rows="$rows"
        :totals="$totals"
        :has-functions-in-scope="$hasFunctionsInScope"
        :serverless-enabled="true"
        :api-ready="true"
        :show-create-action="true"
        :show-secondary-actions="true"
        empty-state="local"
        :breadcrumbs="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Serverless'), 'icon' => 'bolt'],
        ]"
    />
</div>
