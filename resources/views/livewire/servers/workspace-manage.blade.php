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

    @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => 18])

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
            @case ('services')
                @include('livewire.servers.partials.manage.group-services', $manageShare)
                @break
            @case ('web')
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
