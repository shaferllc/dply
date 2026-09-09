@php
    $filesPreviewDescription = __('Read-only filesystem browser over SSH — preview what is shipping next.');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="files"
    :title="__('Files')"
    :description="$filesPreviewDescription"
    hide-hero
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-folder"
            :title="__('Files')"
            :note="$filesPreviewDescription"
            class="border-b border-brand-ink/10"
        />

        <div class="px-5 py-5 sm:px-6">
            <x-files-preview-panel :server="$server" compact />
        </div>
    </section>
</x-server-workspace-layout>
