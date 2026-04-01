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

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
