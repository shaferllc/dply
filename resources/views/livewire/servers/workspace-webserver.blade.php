@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

<x-server-workspace-layout
    :server="$server"
    active="webserver"
    :title="__('Webserver')"
    :description="__('Pick which webserver runs on this box. Switching reprovisions all sites under the new daemon, then service-swaps to :80.')"
>
    {{-- Register the lazy CodeMirror loader on initial page render. The config
         editor partial is only included after a file is opened (a Livewire
         update), and a <script type=module> injected via Livewire morph never
         executes — so without this, window.dplyEnsureFileBrowserEditor is never
         defined and the editor mounts empty. --}}
    @vite(['resources/js/file-browser-editor-lazy.js'])

    @include('livewire.servers.partials.workspace-flashes', ['command_output' => null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($manageRemoteTaskId)
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @elseif ($caddyModulesBuildState['active'] ?? false)
        <div wire:poll.3s="refreshCaddyModulesBuildUi" class="hidden" aria-hidden="true"></div>
    @endif

    @if ($isDeployer)
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['amber'] }}">
                        <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Deployers can view this page but cannot run SSH actions or switch the webserver.') }}
                        </p>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if (! $opsReady)
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['amber'] }}">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Waiting on provisioning') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Provisioning and SSH must be ready before webserver actions or switching can run.') }}
                        </p>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @include('livewire.servers.partials.webserver._banner')

    <div class="min-w-0">
        <x-server-workspace-tablist :aria-label="__('Webserver workspace sections')" scroll class="w-full">
            <x-server-workspace-tab
                id="ws-tab-overview"
                :active="$workspace_tab === 'overview'"
                wire:click="setWorkspaceTab('overview')"
                icon="heroicon-o-bolt"
            >
                {{ __('Overview') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="ws-tab-change"
                :active="$workspace_tab === 'change'"
                wire:click="setWorkspaceTab('change')"
                icon="heroicon-o-arrow-path"
            >
                <span class="inline-flex items-center gap-2">
                    {{ __('Change') }}
                    @if ($inflightSwitch)
                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700">
                            <x-spinner variant="forest" />
                            {{ __('Working') }}
                        </span>
                    @endif
                </span>
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="ws-tab-health"
                :active="$workspace_tab === 'health'"
                wire:click="setWorkspaceTab('health')"
                icon="heroicon-o-shield-check"
            >
                {{ __('Health') }}
            </x-server-workspace-tab>
            <span class="mx-0.5 h-6 w-px shrink-0 self-center bg-brand-ink/10" aria-hidden="true"></span>
            @foreach ($engineTabCatalog as $key => $info)
                @php $isActiveEngine = $key === $activeWebserver; @endphp
                <x-server-workspace-tab
                    :id="'ws-tab-'.$key"
                    :active="$workspace_tab === $key"
                    wire:click="setWorkspaceTab('{{ $key }}')"
                    :icon="$info['icon']"
                >
                    <span class="inline-flex items-center gap-2">
                        {{ $info['label'] }}
                        @if ($inflightSwitch && $switchTargetWebserver === $key)
                            <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700">
                                <x-spinner variant="forest" />
                                {{ __('Working') }}
                            </span>
                        @elseif ($isActiveEngine)
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('Active') }}</span>
                        @elseif (! empty($info['coming_soon']))
                            <span class="inline-flex items-center rounded-full bg-brand-sand/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Soon') }}</span>
                        @elseif ($preflight->isBlocked($server, $key))
                            <span class="inline-flex items-center rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">{{ __('Unavailable') }}</span>
                        @endif
                    </span>
                </x-server-workspace-tab>
            @endforeach
            <span class="mx-0.5 h-6 w-px shrink-0 self-center bg-brand-ink/10" aria-hidden="true"></span>
            <x-server-workspace-tab
                id="ws-tab-advanced"
                :active="$workspace_tab === 'advanced'"
                wire:click="setWorkspaceTab('advanced')"
                icon="heroicon-o-wrench-screwdriver"
            >
                {{ __('Advanced') }}
            </x-server-workspace-tab>
        </x-server-workspace-tablist>
    </div>

    <x-workspace-tab-panel-loading>
    @if ($workspace_tab === 'overview')
        <x-server-workspace-tab-panel
            id="ws-panel-overview"
            labelled-by="ws-tab-overview"
            panel-class="space-y-6"
        >
            @include('livewire.servers.partials.webserver.overview-tab')
        </x-server-workspace-tab-panel>
    @endif

    @if ($workspace_tab === 'change')
        <x-server-workspace-tab-panel
            id="ws-panel-change"
            labelled-by="ws-tab-change"
            panel-class="space-y-6"
        >
            @include('livewire.servers.partials.webserver.change-tab')
        </x-server-workspace-tab-panel>
    @endif

    @if ($workspace_tab === 'health')
        <x-server-workspace-tab-panel
            id="ws-panel-health"
            labelled-by="ws-tab-health"
            panel-class="space-y-6"
        >
            @include('livewire.servers.partials.webserver.health-tab')
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

    </x-workspace-tab-panel-loading>

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
