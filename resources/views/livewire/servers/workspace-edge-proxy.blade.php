@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
    ];
@endphp

<x-server-workspace-layout
    :server="$server"
    active="edge-proxy"
    :title="__('Edge proxy')"
    :description="__('Optional L7 reverse proxy in front of your webserver. Caddy serves each site on a high port; the edge proxy routes hosts on :80.', ['port' => 80])"
    hide-hero
>
    {{-- Register the lazy CodeMirror loader on initial page render — see the
         note in workspace-webserver.blade.php (Livewire-morph-injected module
         scripts don't execute, so the inline config editor would mount empty). --}}
    @vite(['resources/js/file-browser-editor-lazy.js'])

    @include('livewire.servers.partials.workspace-flashes', ['command_output' => null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($manageRemoteTaskId)
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.servers.partials.webserver._banner')

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-arrow-path-rounded-square"
            :title="__('Edge proxy')"
            :note="__('Optional L7 reverse proxy in front of your webserver. Caddy serves each site on a high port; the edge proxy routes hosts on :80.', ['port' => 80])"
            class="border-b border-brand-ink/10"
        />

        @if ($isDeployer)
            <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-amber-200/80 bg-amber-50/60 px-4 py-2 text-[11px] text-amber-900 sm:px-5">
                <x-heroicon-m-eye class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span class="font-semibold">{{ __('Deployer role.') }}</span>
                {{ __('Deployers can view this page but cannot add, remove, or run edge proxy actions.') }}
            </p>
        @endif

        @if (! $opsReady)
            <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-amber-200/80 bg-amber-50/60 px-4 py-2 text-[11px] text-amber-900 sm:px-5">
                <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Provisioning and SSH must be ready before edge proxy actions can run.') }}
            </p>
        @endif

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Edge proxy workspace sections')" scroll bare class="!mb-0 w-full">
                <x-server-workspace-tab
                    id="ep-tab-overview"
                    :active="$workspace_tab === 'overview'"
                    wire:click="setWorkspaceTab('overview')"
                    icon="heroicon-o-bolt"
                >
                    {{ __('Overview') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="ep-tab-change"
                    :active="$workspace_tab === 'change'"
                    wire:click="setWorkspaceTab('change')"
                    icon="heroicon-o-arrow-up-tray"
                >
                    <span class="inline-flex items-center gap-2">
                        {{ __('Add / remove') }}
                        @if ($inflightEdgeProxy)
                            <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700">
                                <x-spinner variant="forest" />
                                {{ __('Working') }}
                            </span>
                        @endif
                    </span>
                </x-server-workspace-tab>
                <span class="mx-0.5 h-6 w-px shrink-0 self-center bg-brand-ink/10" aria-hidden="true"></span>
                @foreach ($engineTabCatalog as $key => $info)
                    @php
                        $info = $info + ['is_edge_proxy' => true];
                        $isActiveEngine = $key === $activeEdgeProxy;
                    @endphp
                    <x-server-workspace-tab
                        :id="'ep-tab-'.$key"
                        :active="$workspace_tab === $key"
                        wire:click="setWorkspaceTab('{{ $key }}')"
                        :icon="$info['icon']"
                    >
                        <span class="inline-flex items-center gap-2">
                            {{ $info['label'] }}
                            @if ($inflightEdgeProxy && $edgeProxyActionTarget === $key)
                                <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700">
                                    <x-spinner variant="forest" />
                                    {{ __('Working') }}
                                </span>
                            @elseif ($isActiveEngine)
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('Active') }}</span>
                            @elseif (! empty($info['coming_soon']))
                                <span class="inline-flex items-center rounded-full bg-brand-sand/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Soon') }}</span>
                            @endif
                        </span>
                    </x-server-workspace-tab>
                @endforeach
            </x-server-workspace-tablist>
        </div>

        {{-- Skeleton swap, not a dim-and-lock — same treatment as the Webserver
             workspace, and it shares that page's shapes (the engine tabs render
             the very same engine-panel partials). --}}
        @php
            $edgeSkeletonTabs = array_merge(['overview', 'change'], array_keys($engineTabCatalog));
        @endphp
        @foreach ($edgeSkeletonTabs as $skeletonTab)
            <div class="hidden" wire:loading.class.remove="hidden" wire:target="setWorkspaceTab('{{ $skeletonTab }}')" aria-busy="true" aria-live="polite">
                <span class="sr-only">{{ __('Loading section…') }}</span>
                @include('livewire.servers.partials.webserver._tab-skeleton', [
                    'tab' => in_array($skeletonTab, ['overview', 'change'], true) ? $skeletonTab : 'engine',
                ])
            </div>
        @endforeach

        <div class="relative" wire:loading.class="hidden" wire:target="setWorkspaceTab">
        @if ($workspace_tab === 'overview')
            <x-server-workspace-tab-panel id="ep-panel-overview" labelled-by="ep-tab-overview" panel-class="min-w-0">
                @include('livewire.servers.partials.edge-proxy.overview-tab')
            </x-server-workspace-tab-panel>
        @endif

        @if ($workspace_tab === 'change')
            <x-server-workspace-tab-panel id="ep-panel-change" labelled-by="ep-tab-change" panel-class="min-w-0">
                @include('livewire.servers.partials.edge-proxy.change-tab')
            </x-server-workspace-tab-panel>
        @endif

        @foreach ($engineTabCatalog as $key => $info)
            @if ($workspace_tab === $key)
                @php $info = $info + ['is_edge_proxy' => true]; @endphp
                <x-server-workspace-tab-panel :id="'ep-panel-'.$key" :labelled-by="'ep-tab-'.$key" panel-class="min-w-0">
                    @include('livewire.servers.partials.webserver.engine-panel', compact('key', 'info'))
                </x-server-workspace-tab-panel>
            @endif
        @endforeach

        </div>
    </section>

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
