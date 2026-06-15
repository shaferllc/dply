<x-server-workspace-layout
    :server="$server"
    active="ssh"
    :title="__('SSH keys')"
    :description="__('Authorize keys, preview drift, audit changes, and sync authorized_keys.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($opsReady)
        <div class="space-y-6">
            @include('livewire.servers.partials.ssh-keys._banner')

            <x-server-workspace-tablist :aria-label="__('SSH keys workspace')">
                <x-server-workspace-tab id="ssh-tab-keys" :active="$ssh_workspace_tab === 'keys'" wire:click="setSshWorkspaceTab('keys')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-key class="h-4 w-4" aria-hidden="true" />
                        {{ __('Keys') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="ssh-tab-preview" :active="$ssh_workspace_tab === 'preview'" wire:click="setSshWorkspaceTab('preview')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-arrows-right-left class="h-4 w-4" aria-hidden="true" />
                        {{ __('Drift') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="ssh-tab-advanced" :active="$ssh_workspace_tab === 'advanced'" wire:click="setSshWorkspaceTab('advanced')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-adjustments-horizontal class="h-4 w-4" aria-hidden="true" />
                        {{ __('Advanced') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="ssh-tab-activity" :active="$ssh_workspace_tab === 'activity'" wire:click="setSshWorkspaceTab('activity')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-clock class="h-4 w-4" aria-hidden="true" />
                        {{ __('Activity') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="ssh-tab-notifications" :active="$ssh_workspace_tab === 'notifications'" wire:click="setSshWorkspaceTab('notifications')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-bell class="h-4 w-4" aria-hidden="true" />
                        {{ __('Notifications') }}
                    </span>
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setSshWorkspaceTab">

            @if ($ssh_workspace_tab === 'keys')
                <x-server-workspace-tab-panel
                    id="ssh-panel-keys"
                    labelled-by="ssh-tab-keys"
                    panel-class="space-y-6"
                >
                    @include('livewire.servers.partials.ssh-keys.keys-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($ssh_workspace_tab === 'preview')
                <x-server-workspace-tab-panel
                    id="ssh-panel-preview"
                    labelled-by="ssh-tab-preview"
                >
                    @include('livewire.servers.partials.ssh-keys.preview-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($ssh_workspace_tab === 'advanced')
                <x-server-workspace-tab-panel
                    id="ssh-panel-advanced"
                    labelled-by="ssh-tab-advanced"
                >
                    @include('livewire.servers.partials.ssh-keys.advanced-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($ssh_workspace_tab === 'activity')
                <x-server-workspace-tab-panel
                    id="ssh-panel-activity"
                    labelled-by="ssh-tab-activity"
                >
                    @include('livewire.servers.partials.ssh-keys.activity-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($ssh_workspace_tab === 'notifications')
                <x-server-workspace-tab-panel
                    id="ssh-panel-notifications"
                    labelled-by="ssh-tab-notifications"
                >
                    @include('livewire.servers.partials.ssh-keys.notifications-tab')
                </x-server-workspace-tab-panel>
            @endif

            </div>
        </div>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @endif

    <x-slot name="modals">
        <livewire:profile.personal-ssh-key-modal source="servers.workspace-ssh-keys" />
        {{-- Reusable inline channel-create modal (CreatesNotificationChannelInline trait),
             shared with the Notifications tab so an operator can add a channel without
             leaving the page; the new channel is auto-selected on success. --}}
        @include('livewire.partials.create-notification-channel-modal')
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
