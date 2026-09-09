@php
    $maintenancePreviewDescription = __('Visitor maintenance window, site impact, and downtime controls — preview what is shipping next.');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="maintenance"
    :title="__('Maintenance')"
    :description="$maintenancePreviewDescription"
    hide-hero
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-wrench"
            :title="__('Maintenance')"
            :note="$maintenancePreviewDescription"
            class="border-b border-brand-ink/10"
        />

        <div class="px-5 py-5 sm:px-6">
            <x-maintenance-preview-panel :server="$server" compact />
        </div>
    </section>
</x-server-workspace-layout>
