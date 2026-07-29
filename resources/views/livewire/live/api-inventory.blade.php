<div class="contents">
    <x-production-data-banner :connection="$connection" :writes-unlocked="$writesUnlocked">
        <x-slot:actions>
            @if ($apiReady)
                <button type="button" wire:click="refresh" class="rounded-lg bg-amber-950/10 px-3 py-1.5 text-sm font-semibold hover:bg-amber-950/15">
                    {{ __('Refresh') }}
                </button>
            @endif
            <button type="button" wire:click="disconnect" class="rounded-lg bg-amber-950/10 px-3 py-1.5 text-sm font-semibold hover:bg-amber-950/15">
                {{ __('Disconnect') }}
            </button>
        </x-slot:actions>
    </x-production-data-banner>
    <x-production-data-nav :connection="$connection" />

    @if ($resource === 'projects' && $apiReady)
        <x-projects-index-page
            :rows="$projectRows"
            :summary="$summary"
            :has-projects-in-scope="$hasProjectsInScope"
            :has-organization="true"
            :show-filters="false"
            :show-create-action="false"
            empty-state="production"
            :breadcrumbs="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Production'), 'href' => route('live.sites.index'), 'icon' => 'exclamation-triangle'],
                ['label' => __('Projects'), 'icon' => 'rectangle-group'],
            ]"
        >
            @if ($error)
                <x-slot:alert>
                    <x-alert tone="danger">{{ $error }}</x-alert>
                </x-slot:alert>
            @endif
        </x-projects-index-page>
    @elseif ($resource === 'edge' && $apiReady)
        <x-edge-index-page
            :rows="$edgeRows"
            :totals="$edgeTotals"
            :has-sites-in-scope="$hasEdgeSitesInScope"
            :edge-enabled="true"
            :show-filters="false"
            :show-create-action="false"
            :show-secondary-actions="false"
            empty-state="production"
            :breadcrumbs="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Production'), 'href' => route('live.sites.index'), 'icon' => 'exclamation-triangle'],
                ['label' => __('Edge'), 'icon' => 'globe-alt'],
            ]"
        >
            @if ($error)
                <x-slot:alert>
                    <x-alert tone="danger">{{ $error }}</x-alert>
                </x-slot:alert>
            @endif
        </x-edge-index-page>
    @elseif ($resource === 'cloud' && $apiReady)
        <x-cloud-index-page
            :rows="$cloudRows"
            :totals="$cloudTotals"
            :has-apps-in-scope="$hasCloudAppsInScope"
            :cloud-enabled="true"
            :api-ready="true"
            :show-filters="false"
            :show-create-action="false"
            :show-databases-action="false"
            empty-state="production"
            :breadcrumbs="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Production'), 'href' => route('live.sites.index'), 'icon' => 'exclamation-triangle'],
                ['label' => __('Cloud apps'), 'icon' => 'cloud'],
            ]"
        >
            @if ($error)
                <x-slot:alert>
                    <x-alert tone="danger">{{ $error }}</x-alert>
                </x-slot:alert>
            @endif
        </x-cloud-index-page>
    @elseif ($resource === 'serverless')
        <x-serverless-index-page
            :rows="$serverlessRows"
            :totals="$serverlessTotals"
            :has-functions-in-scope="$hasServerlessInScope"
            :serverless-enabled="true"
            :api-ready="$apiReady"
            :show-create-action="false"
            :show-secondary-actions="false"
            empty-state="production"
            :breadcrumbs="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Production'), 'href' => route('live.sites.index'), 'icon' => 'exclamation-triangle'],
                ['label' => __('Serverless'), 'icon' => 'bolt'],
            ]"
        >
            @if ($error)
                <x-slot:alert>
                    <x-alert tone="danger">{{ $error }}</x-alert>
                </x-slot:alert>
            @endif
        </x-serverless-index-page>
    @else
        <x-production-api-inventory
            :title="$title"
            :rows="$rows"
            :error="$error"
            :api-ready="$apiReady"
            :breadcrumbs="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Production'), 'href' => route('live.sites.index'), 'icon' => 'exclamation-triangle'],
                ['label' => $title, 'icon' => 'rectangle-group'],
            ]"
        />
    @endif
</div>
