<x-server-workspace-layout
    :server="$server"
    active="firewall"
    :title="__('Firewall')"
    :description="__('Manage basic UFW access on the host with rules, presets, templates, apply, status, and recent history.')"
    hide-hero
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Dense panel head, matching Settings / Logs / Files. This was a tall
             stack — 10x10 icon badge, 18px title, max-w-2xl paragraph, py-5 band
             — spending roughly three times the height to say the same thing. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-shield-check"
            :title="__('Firewall')"
            :note="__('Manage basic UFW access on the host with rules, presets, templates, apply, status, and recent history.')"
            class="border-b border-brand-ink/10"
        />

        @if ($opsReady)
            <div @class([
                'border-b border-brand-ink/10 px-5 py-3 sm:px-6' => $applyShowBanner || ! empty($panel_event_lines),
            ])>
                @include('livewire.servers.partials.firewall._banner')
            </div>

            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                <x-server-workspace-tablist :aria-label="__('Firewall workspace sections')" scroll bare class="!mb-0 w-full">
                    <x-server-workspace-tab
                        id="firewall-tab-rules"
                        icon="heroicon-o-shield-check"
                        :active="$firewall_workspace_tab === 'rules'"
                        wire:click="setFirewallWorkspaceTab('rules')"
                    >
                        {{ __('Rules') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab
                        id="firewall-tab-templates"
                        icon="heroicon-o-document-duplicate"
                        :active="$firewall_workspace_tab === 'templates'"
                        wire:click="setFirewallWorkspaceTab('templates')"
                    >
                        {{ __('Templates') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab
                        id="firewall-tab-activity"
                        icon="heroicon-o-clock"
                        :active="$firewall_workspace_tab === 'activity'"
                        wire:click="setFirewallWorkspaceTab('activity')"
                    >
                        {{ __('Activity') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab
                        id="firewall-tab-notifications"
                        icon="heroicon-o-bell"
                        :active="$firewall_workspace_tab === 'notifications'"
                        wire:click="setFirewallWorkspaceTab('notifications')"
                    >
                        {{ __('Notifications') }}
                    </x-server-workspace-tab>
                </x-server-workspace-tablist>
            </div>

            {{-- Skeleton while a tab switch is in flight, then the panel. Dimming
                 the old panel was the only feedback before, which read as a
                 frozen page on a slow round trip.

                 Shape learned the hard way on Settings: the OUTER wrappers are
                 stable and carry the loading directives, the tab key lives on an
                 INNER div. The key stops Livewire's morph throwing when it
                 patches one tab's DOM into another's (different shapes entirely),
                 and keeping it inside a stable wrapper means any subtree the
                 morph leaves behind is inside the hidden element rather than
                 orphaned as a visible sibling. wire:loading.block, not bare
                 wire:loading, or the skeleton shrink-wraps to inline-block. --}}
            <div wire:loading.block wire:target="setFirewallWorkspaceTab" aria-busy="true" aria-live="polite">
                <span class="sr-only">{{ __('Loading section…') }}</span>
                <div wire:key="firewall-skeleton-{{ $firewall_workspace_tab }}">
                    @include('livewire.servers.partials.firewall._tab-skeleton', ['tab' => $firewall_workspace_tab])
                </div>
            </div>

            <div class="relative" wire:loading.remove wire:target="setFirewallWorkspaceTab">
                @if ($firewall_workspace_tab === 'rules')
                    <x-server-workspace-tab-panel
                        id="firewall-panel-rules"
                        labelled-by="firewall-tab-rules"
                        panel-class="min-w-0"
                    >
                        @include('livewire.servers.partials.firewall.rules-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($firewall_workspace_tab === 'templates')
                    <x-server-workspace-tab-panel
                        id="firewall-panel-templates"
                        labelled-by="firewall-tab-templates"
                        panel-class="min-w-0"
                    >
                        @include('livewire.servers.partials.firewall.templates-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($firewall_workspace_tab === 'activity')
                    <x-server-workspace-tab-panel
                        id="firewall-panel-activity"
                        labelled-by="firewall-tab-activity"
                        panel-class="min-w-0"
                    >
                        @include('livewire.servers.partials.firewall.activity-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($firewall_workspace_tab === 'notifications')
                    <x-server-workspace-tab-panel
                        id="firewall-panel-notifications"
                        labelled-by="firewall-tab-notifications"
                        panel-class="min-w-0"
                    >
                        @include('livewire.servers.partials.firewall.notifications-tab')
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
        @include('livewire.partials.confirm-action-modal')
        {{-- Reusable inline channel-create modal (CreatesNotificationChannelInline trait),
             shared with the Notifications tab so an operator can add a channel without
             leaving the page; the new channel is auto-selected on success. --}}
        @include('livewire.partials.create-notification-channel-modal')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
        @include('livewire.servers.partials.firewall._modals')

        {{-- Apply preview: shows the operator the exact `ufw <fragment>` commands the apply job
             will run, in order, before they confirm. Mirrors ServerFirewallProvisioner::apply()
             so the operator never reads "what we said we'd do" different from "what we ran". --}}
        @if ($apply_preview_open)
            <div
                class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
                role="dialog"
                aria-modal="true"
                aria-labelledby="firewall-apply-preview-title"
                x-data
                x-on:keydown.escape.window="$wire.closeApplyPreview()"
            >
                <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeApplyPreview"></div>
                <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
                    <div class="my-auto flex w-full max-w-2xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                        <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                            <x-icon-badge>
                                <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Apply preview') }}</p>
                                <h2 id="firewall-apply-preview-title" class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Review the ufw commands') }}</h2>
                                <p class="mt-1 text-sm leading-6 text-brand-moss">
                                    {{ __('The apply job will run these commands against :host in this order. The SSH safety rule (allow :port/tcp) is always re-added — confirm and we\'ll queue the job.', [
                                        'host' => $server->getSshConnectionString(),
                                        'port' => (int) ($server->ssh_port ?: 22),
                                    ]) }}
                                </p>
                            </div>
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto bg-brand-ink/95 px-6 py-4 font-mono text-xs leading-relaxed text-emerald-100">
                            @foreach ($apply_preview_lines as $line)
                                <div class="whitespace-pre-wrap break-all">$ {{ $line }}</div>
                            @endforeach
                        </div>

                        @if ($sshNotCovered)
                            <div class="border-t border-amber-200 bg-amber-50/70 px-6 py-4 text-sm text-amber-900">
                                <div class="flex items-start gap-2.5">
                                    <x-heroicon-m-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <p class="font-semibold">{{ __('No explicit SSH allow rule in the panel') }}</p>
                                        <p class="mt-1 text-xs leading-relaxed">
                                            {{ __('The safety rail above will keep SSH reachable for THIS apply, but you should still add an explicit rule before tightening UFW defaults. Tick the box to acknowledge and continue.') }}
                                        </p>
                                        <label class="mt-2 flex items-start gap-2 text-xs">
                                            <input type="checkbox" wire:model.live="firewall_ack_ssh_risk" class="mt-0.5 rounded border-amber-300 text-amber-700 focus:ring-amber-500" />
                                            <span>{{ __('I understand SSH may be unreachable after this apply.') }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                            <p class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-mist">
                                <x-heroicon-m-command-line class="h-4 w-4 shrink-0" aria-hidden="true" />
                                <span class="font-mono tabular-nums text-brand-moss">{{ count($apply_preview_lines) }}</span>
                                {{ trans_choice('command|commands', count($apply_preview_lines)) }}
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-secondary-button type="button" wire:click="closeApplyPreview">{{ __('Cancel') }}</x-secondary-button>
                                <button
                                    type="button"
                                    wire:click="applyFirewall(true)"
                                    wire:loading.attr="disabled"
                                    wire:target="applyFirewall"
                                    @disabled($sshNotCovered && ! $firewall_ack_ssh_risk)
                                    class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="applyFirewall" class="inline-flex items-center gap-2">
                                        <x-heroicon-o-shield-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Run apply') }}
                                    </span>
                                    <span wire:loading wire:target="applyFirewall" class="inline-flex items-center gap-2 whitespace-nowrap">
                                        <x-spinner variant="cream" size="sm" />
                                        {{ __('Queueing…') }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-slot>
</x-server-workspace-layout>
