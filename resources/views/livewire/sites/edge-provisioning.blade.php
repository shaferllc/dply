<div>
    @if ($site->server_id)
        <div
            id="dply-site-provisioning-context"
            data-server-id="{{ $site->server_id }}"
            data-site-id="{{ $site->id }}"
            data-subscribe="1"
            class="hidden"
            aria-hidden="true"
        ></div>
    @endif

    <div class="dply-page-shell pt-6">
        <x-breadcrumb-trail
            :items="$siteHeaderBreadcrumbs"
            doc-contextual
        />
    </div>

    <div class="dply-page-shell pt-4">
        <x-page-header
            :title="__('Edge deployment')"
            :description="__('Track the git build and Edge CDN publish until this site goes live.')"
            :show-documentation="false"
            toolbar
            compact
            flush
        >
            <x-slot name="leading">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                    <x-heroicon-o-rocket-launch class="h-7 w-7 text-brand-ink" aria-hidden="true" />
                </span>
            </x-slot>
            <x-slot name="actions">
                <x-outline-link :href="route('edge.index')" wire:navigate>
                    <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('All Edge sites') }}
                </x-outline-link>
            </x-slot>
        </x-page-header>
    </div>

    <div class="pb-12 pt-2">
        <div class="dply-page-shell space-y-6">
            @include('livewire.sites.partials.show.edge-provisioning-journey')
        </div>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
