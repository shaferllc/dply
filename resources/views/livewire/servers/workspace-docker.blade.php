<x-server-workspace-layout
    :server="$server"
    active="docker"
    :title="__('Docker')"
    :description="__('Manage Docker Engine on this server — containers, images, volumes, networks, compose projects, and cleanup.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($manageRemoteTaskId)
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif

    @if ($dockerConsoleRun)
        @include('livewire.partials.console-action-banner-static', [
            'run' => $dockerConsoleRun,
            'kindLabels' => [],
        ])
    @endif

    <x-explainer>
        <p>{{ __('Inspect and manage Docker Engine on this server over SSH — containers, images, volumes, networks, compose projects, and cleanup. Overview counts come from the inventory probe; other tabs load live data when you open them.') }}</p>
    </x-explainer>

    @if ($opsReady && ! $isDeployer)
        <x-server-workspace-tablist :aria-label="__('Docker workspace sections')" class="mb-6">
            <x-server-workspace-tab
                id="docker-tab-overview"
                :active="$workspace_tab === 'overview'"
                wire:click="setWorkspaceTab('overview')"
                icon="heroicon-o-square-3-stack-3d"
            >
                {{ __('Overview') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="docker-tab-containers"
                :active="$workspace_tab === 'containers'"
                wire:click="setWorkspaceTab('containers')"
                icon="heroicon-o-cube"
            >
                {{ __('Containers') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="docker-tab-images"
                :active="$workspace_tab === 'images'"
                wire:click="setWorkspaceTab('images')"
                icon="heroicon-o-photo"
            >
                {{ __('Images') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="docker-tab-volumes"
                :active="$workspace_tab === 'volumes'"
                wire:click="setWorkspaceTab('volumes')"
                icon="heroicon-o-circle-stack"
            >
                {{ __('Volumes') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="docker-tab-networks"
                :active="$workspace_tab === 'networks'"
                wire:click="setWorkspaceTab('networks')"
                icon="heroicon-o-globe-alt"
            >
                {{ __('Networks') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="docker-tab-compose"
                :active="$workspace_tab === 'compose'"
                wire:click="setWorkspaceTab('compose')"
                icon="heroicon-o-document-duplicate"
            >
                {{ __('Compose') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="docker-tab-maintenance"
                :active="$workspace_tab === 'maintenance'"
                wire:click="setWorkspaceTab('maintenance')"
                icon="heroicon-o-trash"
            >
                {{ __('Maintenance') }}
            </x-server-workspace-tab>
        </x-server-workspace-tablist>

        @if ($workspace_tab === 'overview')
            @include('livewire.servers.partials.docker.overview-tab')
        @endif

        @if ($workspace_tab === 'containers')
            @include('livewire.servers.partials.docker.containers-tab')
        @endif

        @if ($workspace_tab === 'images')
            @include('livewire.servers.partials.docker.images-tab')
        @endif

        @if ($workspace_tab === 'volumes')
            @include('livewire.servers.partials.docker.volumes-tab')
        @endif

        @if ($workspace_tab === 'networks')
            @include('livewire.servers.partials.docker.networks-tab')
        @endif

        @if ($workspace_tab === 'compose')
            @include('livewire.servers.partials.docker.compose-tab')
        @endif

        @if ($workspace_tab === 'maintenance')
            @include('livewire.servers.partials.docker.maintenance-tab')
        @endif
    @elseif ($isDeployer)
        <p class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 px-5 py-4 text-sm text-brand-moss">
            {{ __('Deployers have read-only access to this workspace.') }}
        </p>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.docker.modals')
    </x-slot>
</x-server-workspace-layout>
