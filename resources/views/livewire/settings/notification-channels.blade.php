<div>
    @if (! empty($useOrgShell))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <x-organization-shell :organization="$organization" :section="$orgShellSection ?? 'notifications'">
                @include('livewire.settings.partials.notification-channels-content')
            </x-organization-shell>
        </div>
    @else
        @include('livewire.settings.partials.notification-channels-content')
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
