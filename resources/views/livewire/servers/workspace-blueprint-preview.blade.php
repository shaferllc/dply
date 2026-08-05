@php
    $blueprintPreviewDescription = __('Save this server\'s reconciled stack as a golden blueprint for the next VM you provision — preview what is shipping next.');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="blueprint"
    :title="__('Blueprint')"
    :description="$blueprintPreviewDescription"
    hide-hero
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-document-duplicate"
            :title="__('Blueprint')"
            :note="$blueprintPreviewDescription"
            class="border-b border-brand-ink/10"
        />

        <div class="px-5 py-5 sm:px-6">
            <x-blueprint-preview-panel :server="$server" compact />
        </div>
    </section>
</x-server-workspace-layout>
