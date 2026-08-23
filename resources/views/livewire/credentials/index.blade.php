<div>
    @if (! empty($useOrgShell) && $organization)
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <x-organization-shell
                dense
                :organization="$organization"
                section="providers"
                :title="__('Credentials')"
                :description="__('Every secret this organization hands to a third party. Encrypted at rest, re-checked against the provider where we can.')"
                icon="heroicon-o-key"
                :breadcrumb="[
                    ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                    ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                    ['label' => __('Credentials'), 'icon' => 'key'],
                ]"
            >
                {{-- Two ways in, because a token and a bucket are created by two
                     different forms. The provider catalog lives inside the first
                     modal's picker — it used to be duplicated as 26 cards on
                     this page while your own credentials had no list at all. --}}
                <x-slot:actions>
                    <button
                        type="button"
                        wire:click="openStorageModal"
                        class="inline-flex h-6 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-cloud-arrow-up class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Add storage') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="$dispatch('open-add-provider-credential-modal')"
                        class="inline-flex h-6 items-center gap-1 rounded-lg bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Connect a provider') }}
                    </button>
                </x-slot:actions>

                @include('livewire.credentials.partials.index-content')

                {{-- The reassurance used to be written three times over: the page
                     description, a collapsible explainer, and this footer. Once. --}}
                <x-slot:footer>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-brand-moss">
                        <span class="inline-flex items-center gap-1.5">
                            <x-heroicon-m-lock-closed class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ __('Encrypted before disk and never shown again — rotate at the provider to replace one. Only owners and admins can change these.') }}
                        </span>
                    </div>
                </x-slot:footer>
            </x-organization-shell>
        </div>
    @else
        <div class="space-y-4">
            <div class="flex flex-wrap items-start gap-3">
                <div class="min-w-0 flex-1">
                    <h2 class="text-xl font-semibold tracking-tight text-brand-ink">{{ __('Credentials') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Every secret you hand to a third party. Encrypted at rest, re-checked against the provider where we can.') }}
                    </p>
                </div>
                <button
                    type="button"
                    x-on:click="$dispatch('open-add-provider-credential-modal')"
                    class="inline-flex h-7 shrink-0 items-center gap-1 rounded-lg bg-brand-ink px-3 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Connect a provider') }}
                </button>
            </div>

            @include('livewire.credentials.partials.index-content')
        </div>
    @endif

    {{-- One shared "Add a credential" modal for the entire page. Its provider
         <select> is the catalog; a row's Manage button dispatches
         `open-add-provider-credential-modal` with that provider id. --}}
    <livewire:credentials.add-provider-credential-modal />

    {{-- Storage destinations are a different shape from provider tokens (named,
         many per provider, no OAuth), so they keep their own two-mode dialog:
         "connect existing" records keys for a bucket you already have, "create
         new bucket" provisions one using a connected cloud token. --}}
    @include('livewire.servers.partials.backups._add-destination-modal')

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
