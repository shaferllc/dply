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
        ->whereIn('kind', ['manage_action', 'webserver_switch', 'edge_proxy', 'inventory_probe'])
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

    <x-explainer class="mb-4" tone="warn">
        <p>{{ __('The Manage workspace covers server-level operations that don\'t fit other tabs: web server (nginx/caddy/apache) controls, system updates, the auto-update schedule, and dangerous actions (reboot, disable swap, etc.).') }}</p>
        <p>{{ __('Most actions run via SSH and are queued — the page stays responsive while they run. Dangerous actions all confirm first; every one writes to the server\'s audit log.') }}</p>
    </x-explainer>

    <div class="space-y-6">
        @include('livewire.partials.console-action-banner-static', [
            'run' => $manageConsoleRun,
            'kindLabels' => (array) config('console_actions.kinds', []),
        ])

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
    </x-slot>
</x-server-workspace-layout>
