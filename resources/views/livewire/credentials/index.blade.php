<div>
    @if (! empty($useOrgShell) && $organization)
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <x-organization-shell
                :organization="$organization"
                section="providers"
                :title="__('Credentials')"
                :description="__('Every secret this organization hands to a third party: API tokens for the clouds, registrars and CDNs you use, and the buckets and remotes your backups ship to. All encrypted at rest, and validated against the provider when we can.')"
                icon="heroicon-o-key"
                :breadcrumb="[
                    ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                    ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                    ['label' => __('Credentials'), 'icon' => 'key'],
                ]"
            >
                <x-slot:actions>
                    <x-outline-link href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}" wire:navigate>
                        <x-heroicon-o-user-group class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Roles & limits') }}
                    </x-outline-link>
                    @if ($credentials->isNotEmpty())
                        <button
                            type="button"
                            x-on:click="$dispatch('open-add-provider-credential-modal')"
                            class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Connect a provider') }}
                        </button>
                    @endif
                </x-slot:actions>

                @include('livewire.credentials.partials.index-content')
            </x-organization-shell>
        </div>
    @else
        @include('livewire.credentials.partials.index-content')
    @endif

    {{-- One shared "Add a credential" modal for the entire page. Each
         provider card dispatches `open-add-provider-credential-modal`
         with its provider id; the modal listens window-wide. --}}
    <livewire:credentials.add-provider-credential-modal />

    {{-- Storage destinations are a different shape from provider tokens (named,
         many per provider, no OAuth), so they get their own modal rather than
         being forced through the provider-credential one. It is the shared
         two-mode dialog: "connect existing" records keys for a bucket you
         already have, "create new bucket" provisions one on the provider using
         a connected cloud token. Same dialog the server workspace opens. --}}
    @include('livewire.servers.partials.backups._add-destination-modal')

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
