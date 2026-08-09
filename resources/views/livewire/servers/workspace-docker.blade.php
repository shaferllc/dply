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
        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-square-3-stack-3d"
            :title="__('Docker')"
            :note="__('Manage Docker Engine on this server — containers, images, volumes, networks, compose projects, and cleanup.')"
            class="border-b border-brand-ink/10"
        />

        @if ($isDeployer)
            <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-amber-200/80 bg-amber-50/60 px-4 py-2 text-xs text-amber-900 sm:px-5">
                <x-heroicon-m-eye class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span class="font-semibold">{{ __('Deployer role.') }}</span>
                {{ __('Deployers have read-only access to this workspace.') }}
            </p>
        @elseif (! $opsReady)
            <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-amber-200/80 bg-amber-50/60 px-4 py-2 text-xs text-amber-900 sm:px-5">
                <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Provisioning and SSH must be ready before Docker inventory and actions work.') }}
            </p>
        @else
            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                <x-server-workspace-tablist :aria-label="__('Docker workspace sections')" scroll bare class="!mb-0">
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

            {{-- Tab-switch skeletons. This page had no loading treatment at all,
                 so the outgoing tab sat there until the response landed. One
                 wrapper per tab, each targeting the call WITH its argument —
                 only the tab actually being opened paints. --}}
            @foreach (['overview', 'containers', 'images', 'volumes', 'networks', 'compose', 'maintenance'] as $skeletonTab)
                <div class="hidden" wire:loading.class.remove="hidden" wire:target="setWorkspaceTab('{{ $skeletonTab }}')" aria-busy="true" aria-live="polite">
                    <span class="sr-only">{{ __('Loading section…') }}</span>
                    @include('livewire.servers.partials.docker._tab-skeleton', ['tab' => $skeletonTab])
                </div>
            @endforeach

            <div wire:loading.class="hidden" wire:target="setWorkspaceTab">
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
            </div>
        @endif
    </section>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.docker.modals')
    </x-slot>
</x-server-workspace-layout>
