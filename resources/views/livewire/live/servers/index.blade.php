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
        :show-fleet-ops="false"
        :show-mutations="false"
        :show-hero-actions="true"
        :breadcrumbs="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Servers'), 'icon' => 'server-stack'],
        ]"
    >
        <x-slot:actions>
            @can('create', App\Models\Server::class)
                <a
                    href="{{ route('servers.create') }}"
                    wire:navigate
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest"
                    title="{{ __('Creates a server in this local workspace — production stays read-only.') }}"
                >
                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Add server') }}
                </a>
            @endcan
        </x-slot:actions>

        <x-slot:alert>
            @if ($error)
                <x-alert tone="danger">{{ $error }}</x-alert>
            @endif
            @if ($legacyApi ?? false)
                <div class="rounded-2xl border border-amber-200 bg-amber-50/80 px-5 py-4">
                    <p class="text-sm font-semibold text-amber-950">{{ __('Production API is missing fleet-card fields') }}</p>
                    <p class="mt-1 text-sm leading-relaxed text-amber-900/90">
                        {{ __('Connected host :host still returns the legacy servers list (name/status/IP only). Deploy this app’s enriched GET /api/v1/servers to that control plane, then hit Refresh — metrics, sites, services, insights, and group labels come from that payload.', ['host' => $connection->hostLabel()]) }}
                    </p>
                </div>
            @endif
        </x-slot:alert>

        <x-slot:empty>
            <section class="rounded-[2rem] border-2 border-brand-sage/35 bg-brand-cream shadow-lg shadow-brand-ink/10 ring-1 ring-brand-ink/[0.07]">
                <div class="px-6 py-12 text-center sm:px-10 sm:py-14">
                    <p class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('No servers in production') }}</p>
                    <p class="mt-3 text-base text-brand-moss">{{ __('This production organization has no servers yet. Manage them on the remote workspace.') }}</p>
                </div>
            </section>
        </x-slot:empty>
    </x-servers-index-page>
</div>
