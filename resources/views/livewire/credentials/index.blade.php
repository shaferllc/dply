<div>
    @if (! empty($useOrgShell) && $organization)
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <x-organization-shell :organization="$organization" section="providers">
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

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
