@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $manageShare = [
        'card' => $card,
        'server' => $server,
        'opsReady' => $opsReady,
        'isDeployer' => $isDeployer,
        'btnPrimary' => $btnPrimary,
        'configPreviews' => $configPreviews,
        'serviceActions' => $serviceActions,
        'dangerousActions' => $dangerousActions,
        'autoUpdateIntervals' => $autoUpdateIntervals,
        'recentActions' => $recentActions ?? collect(),
    ];

    // Workspace-scoped console-actions banner. Surfaces the in-flight + most-recent
    // run for any kind Manage dispatches — manage_action (Quick actions / service
    // controls / config previews / Tools installs / mise runtime ops),
    // webserver_switch (Web tab cascade), edge_proxy (add/remove traefik|haproxy),
    // and inventory_probe (Refresh probe). The banner partial self-polls while
    // in-flight and replaces the legacy "Command output" panel.
    $manageConsoleRun = \App\Models\ConsoleAction::query()
        ->where('subject_type', $server->getMorphClass())
        ->where('subject_id', $server->id)
        ->whereIn('kind', ['manage_action', 'webserver_switch', 'edge_proxy', 'inventory_probe', 'clone_server'])
        ->whereNull('dismissed_at')
        ->orderByDesc('created_at')
        ->first();
@endphp

<x-server-workspace-layout
    :server="$server"
    active="manage"
    :title="__('Manage')"
    :description="__('Live state and actions for the server stack. Each tab is scoped to one subsystem.')"
>
    @if ($manageRemoteTaskId)
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @php
        $tonePalette = [
            'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
            'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
            'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        ];
    @endphp

    <x-explainer tone="warn">
        <p>{{ __('The Manage workspace covers server-level operations that don\'t fit other tabs: runtime tools, configuration previews, and dangerous actions (reboot, disable swap, etc.). OS package updates live on Patches when that workspace is enabled.') }}</p>
        <p>{{ __('Most actions run via SSH and are queued — the page stays responsive while they run. Dangerous actions all confirm first; every one writes to the server\'s audit log.') }}</p>
    </x-explainer>

    <div class="space-y-6">
        @include('livewire.partials.console-action-banner-static', [
            'run' => $manageConsoleRun,
            'kindLabels' => (array) config('console_actions.kinds', []),
        ])

        <x-server-tab-strip
            :tabs="$manageTabs"
            :active="$section"
            route-name="servers.manage"
            :route-params="['server' => $server]"
            :aria-label="__('Manage categories')"
        />

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
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Deployers can view this page but cannot run SSH actions or change manage settings.') }}</p>
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
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before previews and service actions work.') }}</p>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        @switch ($section)
            @case ('overview')
                @include('livewire.servers.partials.manage.group-overview', $manageShare)
                @break
            {{-- 'services' section removed — see config/server_manage.php
                 :: workspace_tabs. WorkspaceManage::mount() now redirects
                 stale ?section=services URLs to the standalone Services
                 page so deep links don't 404. --}}
            @case ('web')
                {{-- Banner for webserver_switch + edge_proxy is hoisted to the top
                     of the Manage workspace so it surfaces from every tab, not
                     just /manage/web. See $manageConsoleRun above. --}}
                @include('livewire.servers.partials.manage.group-web', $manageShare)
                @break
            @case ('updates')
                @include('livewire.servers.partials.manage.group-updates', $manageShare)
                @break
            @case ('tools')
                @include('livewire.servers.partials.manage.group-tools', $manageShare)
                @break
            @case ('configuration')
                @include('livewire.servers.partials.manage.group-configuration', $manageShare)
                @break
            @case ('danger')
                @include('livewire.servers.partials.manage.group-danger', $manageShare)
                @break
        @endswitch
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])

        {{-- Clone server modal. Triggered from Configuration → Clone server. --}}
        @if ($clone_open)
            <x-modal
                name="clone-server-modal"
                maxWidth="2xl"
                overlayClass="bg-brand-ink/30"
                panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
                focusable
            >
                <form wire:submit.prevent="confirmCloneServer" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-document-duplicate class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Clone') }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Clone :name?', ['name' => $server->name]) }}</h2>
                            <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Region and size mirror the source. To change them, edit on the provider after the clone completes — or resize via the standard server settings.') }}</p>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                        <div>
                            <label for="clone-name" class="block text-sm font-medium text-brand-ink">{{ __('New server name') }}</label>
                            <input
                                id="clone-name"
                                type="text"
                                wire:model="clone_name"
                                required
                                minlength="2"
                                maxlength="120"
                                class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2.5 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                            />
                            @error('clone_name')
                                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        </div>

                        <dl class="grid grid-cols-2 gap-2">
                            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Region') }}</dt>
                                <dd class="mt-0.5 truncate font-mono text-sm font-semibold text-brand-ink">{{ $server->region ?: '—' }}</dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Size') }}</dt>
                                <dd class="mt-0.5 truncate font-mono text-sm font-semibold text-brand-ink">{{ $server->size ?: '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                        <x-secondary-button type="button" wire:click="cancelCloneServer">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-document-duplicate class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Start clone') }}
                        </button>
                    </div>
                </form>
            </x-modal>
        @endif
    </x-slot>
</x-server-workspace-layout>
