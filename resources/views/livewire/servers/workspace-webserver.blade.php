<x-server-workspace-layout
    :server="$server"
    active="webserver"
    :title="__('Webserver')"
    :description="__('Pick which webserver runs on this box. Switching reprovisions all sites under the new daemon, then service-swaps to :80.')"
>
    @include('livewire.servers.partials.workspace-flashes', ['command_output' => null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($manageRemoteTaskId)
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif

    @if ($isDeployer)
        <div class="mb-4 rounded-2xl border border-amber-200/80 bg-amber-50/90 px-5 py-4 text-sm text-amber-950">
            {{ __('Deployers can view this page but cannot run SSH actions or switch the webserver.') }}
        </div>
    @endif

    @if (! $opsReady)
        <div class="mb-4 rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before webserver actions or switching can run.') }}
        </div>
    @endif

    @include('livewire.servers.partials.webserver._banner')

    <x-server-workspace-tablist :aria-label="__('Webserver workspace sections')">
        <x-server-workspace-tab
            id="ws-tab-overview"
            :active="$workspace_tab === 'overview'"
            wire:click="setWorkspaceTab('overview')"
            icon="heroicon-o-bolt"
        >
            {{ __('Overview') }}
        </x-server-workspace-tab>
        @foreach ($engineTabCatalog as $key => $info)
            @php
                $isEdgeProxyTab = ! empty($info['is_edge_proxy']);
                $isActiveEngine = $isEdgeProxyTab
                    ? $key === $activeEdgeProxy
                    : $key === $activeWebserver;
            @endphp
            <x-server-workspace-tab
                :id="'ws-tab-'.$key"
                :active="$workspace_tab === $key"
                wire:click="setWorkspaceTab('{{ $key }}')"
                :icon="$info['icon']"
            >
                {{ $info['label'] }}
                @if ($isActiveEngine)
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">{{ $isEdgeProxyTab ? __('Edge') : __('Active') }}</span>
                @elseif (! $isEdgeProxyTab && $preflight->isBlocked($server, $key))
                    <span class="inline-flex items-center rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">{{ __('Unavailable') }}</span>
                @endif
            </x-server-workspace-tab>
        @endforeach
        <x-server-workspace-tab
            id="ws-tab-advanced"
            :active="$workspace_tab === 'advanced'"
            wire:click="setWorkspaceTab('advanced')"
            icon="heroicon-o-wrench-screwdriver"
        >
            {{ __('Advanced') }}
        </x-server-workspace-tab>
    </x-server-workspace-tablist>

    <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setWorkspaceTab">

    @if ($workspace_tab === 'overview')
        <x-server-workspace-tab-panel
            id="ws-panel-overview"
            labelled-by="ws-tab-overview"
            panel-class="space-y-6"
        >
            @include('livewire.servers.partials.webserver.overview-tab')
        </x-server-workspace-tab-panel>
    @endif

    @foreach ($engineTabCatalog as $key => $info)
        @if ($workspace_tab === $key)
            <x-server-workspace-tab-panel
                :id="'ws-panel-'.$key"
                :labelled-by="'ws-tab-'.$key"
                panel-class="space-y-6"
            >
                @include('livewire.servers.partials.webserver.engine-panel', compact('key', 'info'))
            </x-server-workspace-tab-panel>
        @endif
    @endforeach

    @if ($workspace_tab === 'advanced')
        <x-server-workspace-tab-panel
            id="ws-panel-advanced"
            labelled-by="ws-tab-advanced"
            panel-class="space-y-6"
        >
            @include('livewire.servers.partials.webserver.advanced-tab')
        </x-server-workspace-tab-panel>
    @endif

    </div>

    @include('livewire.servers.partials.webserver.switch-modal')

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
