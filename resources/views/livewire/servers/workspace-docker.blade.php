<x-server-workspace-layout
    :server="$server"
    active="docker"
    :title="__('Docker')"
    :description="__('Manage Docker Engine on this server — containers, images, volumes, networks, compose projects, and cleanup.')"
    hide-hero
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

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-800 ring-1 ring-sky-200">
                        <x-heroicon-o-square-3-stack-3d class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Docker') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Manage Docker Engine on this server — containers, images, volumes, networks, compose projects, and cleanup.') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        @if ($isDeployer)
            <div class="border-b border-amber-200/80 bg-amber-50/60 px-5 py-3.5 text-sm text-amber-900 sm:px-6">
                {{ __('Deployers have read-only access to this workspace.') }}
            </div>
        @elseif (! $opsReady)
            <div class="border-b border-amber-200/80 bg-amber-50/60 px-5 py-3.5 text-sm text-amber-900 sm:px-6">
                {{ __('Provisioning and SSH must be ready before Docker inventory and actions work.') }}
            </div>
        @else
            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                <x-server-workspace-tablist :aria-label="__('Docker workspace sections')" scroll class="!mb-0 border-0 bg-transparent p-0 shadow-none">
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
            </div>

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
        @endif
    </section>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.docker.modals')
    </x-slot>
</x-server-workspace-layout>
