<div class="contents">
    <x-production-data-banner :connection="$connection" :writes-unlocked="app(\App\Services\ProductionData\ProductionDataMirror::class)->writesUnlocked()">
        <x-slot:actions>
            <button type="button" wire:click="refresh" class="rounded-lg bg-amber-950/10 px-3 py-1.5 text-sm font-semibold hover:bg-amber-950/15">
                {{ __('Refresh') }}
            </button>
            <button type="button" wire:click="disconnect" class="rounded-lg bg-amber-950/10 px-3 py-1.5 text-sm font-semibold hover:bg-amber-950/15">
                {{ __('Disconnect') }}
            </button>
        </x-slot:actions>
    </x-production-data-banner>
    <x-production-data-nav :connection="$connection" />

    <x-sites-index-page
        :rows="$rows"
        :summary="$summary"
        :has-sites-in-scope="$hasSitesInScope"
        :status-options="$statusOptions"
        :sort-options="$sortOptions"
        :breadcrumbs="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Production'), 'href' => route('live.sites.index'), 'icon' => 'exclamation-triangle'],
            ['label' => __('Sites'), 'icon' => 'globe-alt'],
        ]"
        :servers-index-url="route('live.servers.index')"
        empty-state="production"
    >
        @if ($error)
            <x-slot:alert>
                <x-alert tone="danger">{{ $error }}</x-alert>
            </x-slot:alert>
        @endif
    </x-sites-index-page>
</div>
