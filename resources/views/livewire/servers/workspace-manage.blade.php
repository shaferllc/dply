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
    @include('livewire.servers.partials.workspace-flashes', ['command_output' => $remote_output ?? null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer class="mb-4" tone="warn">
        <p>{{ __('The Manage workspace covers server-level operations that don\'t fit other tabs: web server (nginx/caddy/apache) controls, system updates, the auto-update schedule, and dangerous actions (reboot, disable swap, etc.).') }}</p>
        <p>{{ __('Most actions run via SSH and are queued — the page stays responsive while they run. Dangerous actions all confirm first; every one writes to the server\'s audit log.') }}</p>
    </x-explainer>

    <div class="space-y-6">
        <x-server-tab-strip
            :tabs="config('server_manage.workspace_tabs', [])"
            :active="$section"
            route-name="servers.manage"
            :route-params="['server' => $server]"
            :aria-label="__('Manage categories')"
        />

        @if ($isDeployer)
            <div class="rounded-2xl border border-amber-200/80 bg-amber-50/90 px-5 py-4 text-sm text-amber-950">
                {{ __('Deployers can view this page but cannot run SSH actions or change manage settings.') }}
            </div>
        @endif

        @if (! $opsReady)
            <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
                {{ __('Provisioning and SSH must be ready before previews and service actions work.') }}
            </div>
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
                @php
                    // Surface in-flight webserver_switch runs at the top of the
                    // web tab. The static banner partial self-polls every 4s
                    // while the run is in_flight, so a queued/running row turns
                    // into completed/failed without a page refresh.
                    // The banner surfaces BOTH webserver_switch and edge_proxy
                    // kinds — they're mutually exclusive in practice (the UI
                    // gates dispatch on both), and treating them as one banner
                    // keeps the UX coherent. Picks the most recent non-dismissed
                    // row across both kinds.
                    $webserverSwitchRun = \App\Models\ConsoleAction::query()
                        ->where('subject_type', $server->getMorphClass())
                        ->where('subject_id', $server->id)
                        ->whereIn('kind', ['webserver_switch', 'edge_proxy'])
                        ->whereNull('dismissed_at')
                        ->orderByDesc('created_at')
                        ->first();
                @endphp
                @include('livewire.partials.console-action-banner-static', [
                    'run' => $webserverSwitchRun,
                    'kindLabels' => (array) config('console_actions.kinds', []),
                ])
                @include('livewire.servers.partials.manage.group-web', $manageShare)
                @break
            @case ('data')
                @include('livewire.servers.partials.manage.group-data', $manageShare)
                @break
            @case ('updates')
                @include('livewire.servers.partials.manage.group-updates', $manageShare)
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
    </x-slot>
</x-server-workspace-layout>
