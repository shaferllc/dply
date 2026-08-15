<div class="contents">
    <x-production-data-banner :connection="$connection" :writes-unlocked="$writesUnlocked">
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

    <x-servers-index-page
        :grouped-rows="$groupedRows"
        :summary="$summary"
        :has-servers-in-scope="$hasServersInScope"
        :status-options="$statusOptions"
        :sort-options="$sortOptions"
        :tag-options="$tagOptions"
        :view-mode="$viewMode"
        :status-filter="$statusFilter"
        :sort="$sort"
        :tag-filter="$tagFilter"
        :show-ops-links="false"
        :show-deploy-actions="true"
        :show-mutations="false"
        :show-hero-actions="false"
        :eyebrow="__('Production')"
        :sites-index-url="route('live.sites.index')"
        empty-state="production"
        :breadcrumbs="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Production'), 'href' => route('live.servers.index'), 'icon' => 'exclamation-triangle'],
            ['label' => __('Servers'), 'icon' => 'server-stack'],
        ]"
    >
        <x-slot:alert>
            @if ($error)
                <x-alert tone="danger">{{ $error }}</x-alert>
            @endif
            @if ($legacyApi ?? false)
                <div class="rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-3">
                    <p class="text-sm font-semibold text-amber-950">{{ __('Production API is missing fleet-card fields') }}</p>
                    <p class="mt-1 text-sm leading-relaxed text-amber-900/90">
                        {{ __('Connected host :host still returns the legacy servers list (name/status/IP only). Deploy this app’s enriched GET /api/v1/servers to that control plane, then hit Refresh — metrics, sites, services, insights, and group labels come from that payload.', ['host' => $connection->hostLabel()]) }}
                    </p>
                </div>
            @endif
        </x-slot:alert>

        <x-slot:empty>
            <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="servers-empty-heading">
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                    <x-heroicon-o-server-stack class="h-6 w-6" aria-hidden="true" />
                </span>
                <h2 id="servers-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">
                    {{ __('No servers in production') }}
                </h2>
                <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                    {{ __('This production organization has no servers yet. Manage them on the remote workspace.') }}
                </p>
            </div>
        </x-slot:empty>
    </x-servers-index-page>

    @include('components.production-write-confirm-modal')
</div>
