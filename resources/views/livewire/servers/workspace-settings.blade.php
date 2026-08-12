<x-server-workspace-layout
    :server="$server"
    active="settings"
    :title="__('Settings')"
    :description="__('Navigate through the tabs to manage different settings categories. Unsaved edits surface a save bar at the bottom of the page.')"
    hide-hero
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    {{-- The card owns the category strip and all section state, so switching
         category updates only that component and leaves this shell alone.

         lazy="on-load" matters: plain #[Lazy] hydrates via x-intersect, i.e.
         when the element scrolls into view. Nested here that never fired even
         with the card sitting fully inside the viewport, so the skeleton stayed
         up forever. on-load swaps the trigger to x-init and it hydrates
         immediately, which is what we want for the primary content of the page
         anyway — there's nothing to defer until scroll. --}}
    <livewire:servers.settings-card :server="$server" lazy="on-load" />
</x-server-workspace-layout>
