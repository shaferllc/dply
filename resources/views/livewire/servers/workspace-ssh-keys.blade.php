<x-server-workspace-layout
    :server="$server"
    active="ssh"
    :title="__('SSH keys')"
    :description="__('Authorize keys, preview drift, audit changes, and sync authorized_keys.')"
    hide-hero
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-key"
            :title="__('SSH keys')"
            :note="__('Authorize keys, preview drift, audit changes, and sync authorized_keys.')"
            class="border-b border-brand-ink/10"
        />

        @if ($opsReady)
            @if ($bannerKind !== null)
                <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                    @include('livewire.servers.partials.ssh-keys._banner')
                </div>
            @endif

            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                <x-server-workspace-tablist :aria-label="__('SSH keys workspace')" scroll bare class="!mb-0 w-full">
                    <x-server-workspace-tab id="ssh-tab-keys" icon="heroicon-o-key" :active="$ssh_workspace_tab === 'keys'" wire:click="setSshWorkspaceTab('keys')">
                        {{ __('Keys') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab id="ssh-tab-preview" icon="heroicon-o-arrows-right-left" :active="$ssh_workspace_tab === 'preview'" wire:click="setSshWorkspaceTab('preview')">
                        {{ __('Drift') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab id="ssh-tab-advanced" icon="heroicon-o-adjustments-horizontal" :active="$ssh_workspace_tab === 'advanced'" wire:click="setSshWorkspaceTab('advanced')">
                        {{ __('Advanced') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab id="ssh-tab-activity" icon="heroicon-o-clock" :active="$ssh_workspace_tab === 'activity'" wire:click="setSshWorkspaceTab('activity')">
                        {{ __('Activity') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab id="ssh-tab-notifications" icon="heroicon-o-bell" :active="$ssh_workspace_tab === 'notifications'" wire:click="setSshWorkspaceTab('notifications')">
                        {{ __('Notifications') }}
                    </x-server-workspace-tab>
                </x-server-workspace-tablist>
            </div>

            {{-- Tab-switch skeleton. The wrapper is stable and the key sits on the
                 inner div, so a morph can't leave the previous tab's subtree
                 orphaned as a visible sibling. wire:loading.block, not bare
                 wire:loading, or the skeleton shrink-wraps to inline-block. --}}
            <div wire:loading.block wire:target="setSshWorkspaceTab" aria-busy="true" aria-live="polite">
                <span class="sr-only">{{ __('Loading section…') }}</span>
                <div wire:key="ssh-skeleton-{{ $ssh_workspace_tab }}">
                    @include('livewire.servers.partials.ssh-keys._tab-skeleton', ['tab' => $ssh_workspace_tab])
                </div>
            </div>

            <div class="relative" wire:loading.remove wire:target="setSshWorkspaceTab">
                @if ($ssh_workspace_tab === 'keys')
                    <x-server-workspace-tab-panel
                        id="ssh-panel-keys"
                        labelled-by="ssh-tab-keys"
                        panel-class="min-w-0"
                    >
                        @include('livewire.servers.partials.ssh-keys.keys-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($ssh_workspace_tab === 'preview')
                    <x-server-workspace-tab-panel
                        id="ssh-panel-preview"
                        labelled-by="ssh-tab-preview"
                        panel-class="min-w-0"
                    >
                        @include('livewire.servers.partials.ssh-keys.preview-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($ssh_workspace_tab === 'advanced')
                    <x-server-workspace-tab-panel
                        id="ssh-panel-advanced"
                        labelled-by="ssh-tab-advanced"
                        panel-class="min-w-0"
                    >
                        @include('livewire.servers.partials.ssh-keys.advanced-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($ssh_workspace_tab === 'activity')
                    <x-server-workspace-tab-panel
                        id="ssh-panel-activity"
                        labelled-by="ssh-tab-activity"
                        panel-class="min-w-0"
                    >
                        @include('livewire.servers.partials.ssh-keys.activity-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($ssh_workspace_tab === 'notifications')
                    <x-server-workspace-tab-panel
                        id="ssh-panel-notifications"
                        labelled-by="ssh-tab-notifications"
                        panel-class="min-w-0"
                    >
                        @include('livewire.servers.partials.ssh-keys.notifications-tab')
                    </x-server-workspace-tab-panel>
                @endif
            </div>
        @else
            <div class="px-5 py-6 sm:px-6">
                @include('livewire.servers.partials.workspace-ops-not-ready')
            </div>
        @endif
    </section>

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
