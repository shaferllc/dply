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
        :eyebrow="__('Production')"
        :breadcrumbs="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Production'), 'href' => route('live.sites.index'), 'icon' => 'exclamation-triangle'],
            ['label' => __('Servers'), 'icon' => 'server-stack'],
        ]"
    >
        @if ($error)
            <x-slot:alert>
                <x-alert tone="danger">{{ $error }}</x-alert>
            </x-slot:alert>
        @endif

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
