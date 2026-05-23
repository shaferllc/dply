<x-server-workspace-layout
    :server="$server"
    active="firewall"
    :title="__('Firewall')"
    :description="__('Manage basic UFW access on the host with rules, presets, templates, apply, status, and recent history.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer class="mb-4" tone="warn">
        <p>{{ __('This workspace manages UFW (the Ubuntu firewall) on the server. Dply tracks rules in its own database; "Apply" pushes every enabled panel rule to the host with ufw allow/deny and turns UFW on. Apply is additive — host rules that aren\'t in the panel are left untouched. To get rid of a host rule, use "Import from host" to pull it into the panel, then click Remove (or toggle it off) — Dply will run the matching ufw delete inline.') }}</p>
        <p>{{ __('Presets are quick-to-apply rule bundles for common app shapes (HTTP only, HTTP+SSH from-anywhere, etc.). Templates are reusable rule sets you save and apply across servers.') }}</p>
        <p>{{ __('Locking yourself out is a real risk. Apply always re-adds an allow rule for the server\'s SSH port as a safety rail, but you should still keep an explicit SSH allow in the panel — the workspace warns if you\'re about to apply a rule set that doesn\'t include one.') }}</p>
    </x-explainer>

    @if ($opsReady)
        <div class="space-y-6">
            @include('livewire.servers.partials.firewall._banner')

            <x-server-workspace-tablist :aria-label="__('Firewall workspace sections')">
                <x-server-workspace-tab id="firewall-tab-rules" :active="$firewall_workspace_tab === 'rules'" wire:click="setFirewallWorkspaceTab('rules')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-shield-check class="h-4 w-4" aria-hidden="true" />
                        {{ __('Rules') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="firewall-tab-templates" :active="$firewall_workspace_tab === 'templates'" wire:click="setFirewallWorkspaceTab('templates')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-document-duplicate class="h-4 w-4" aria-hidden="true" />
                        {{ __('Templates') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="firewall-tab-activity" :active="$firewall_workspace_tab === 'activity'" wire:click="setFirewallWorkspaceTab('activity')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-clock class="h-4 w-4" aria-hidden="true" />
                        {{ __('Activity') }}
                    </span>
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setFirewallWorkspaceTab">

            @if ($firewall_workspace_tab === 'rules')
                <x-server-workspace-tab-panel
                    id="firewall-panel-rules"
                    labelled-by="firewall-tab-rules"
                    panel-class="space-y-6"
                >
                    @include('livewire.servers.partials.firewall.rules-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($firewall_workspace_tab === 'templates')
                <x-server-workspace-tab-panel
                    id="firewall-panel-templates"
                    labelled-by="firewall-tab-templates"
                >
                    @include('livewire.servers.partials.firewall.templates-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($firewall_workspace_tab === 'activity')
                <x-server-workspace-tab-panel
                    id="firewall-panel-activity"
                    labelled-by="firewall-tab-activity"
                >
                    @include('livewire.servers.partials.firewall.activity-tab')
                </x-server-workspace-tab-panel>
            @endif

            </div>
        </div>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
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
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-brand-ink/40 px-4 py-8" x-data x-on:keydown.escape.window="$wire.closeApplyPreview()">
                <div class="dply-modal-panel w-full max-w-2xl overflow-hidden">
                    <div class="border-b border-brand-ink/10 px-6 py-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Apply preview') }}</p>
                        <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Review the ufw commands') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-brand-moss">
                            {{ __('The apply job will run these commands against :host in this order. The SSH safety rule (allow :port/tcp) is always re-added — confirm and we\'ll queue the job.', [
                                'host' => $server->getSshConnectionString(),
                                'port' => (int) ($server->ssh_port ?: 22),
                            ]) }}
                        </p>
                    </div>

                    <div class="max-h-[55vh] overflow-auto bg-brand-ink/95 px-6 py-4 font-mono text-xs leading-relaxed text-emerald-100 sm:px-8">
                        @foreach ($apply_preview_lines as $line)
                            <div class="whitespace-pre-wrap break-all">$ {{ $line }}</div>
                        @endforeach
                    </div>

                    @if ($sshNotCovered)
                        <div class="border-t border-amber-200 bg-amber-50/80 px-6 py-4 text-sm text-amber-900 sm:px-8">
                            <p class="font-semibold">{{ __('No explicit SSH allow rule in the panel') }}</p>
                            <p class="mt-1 text-xs leading-relaxed">
                                {{ __('The safety rail above will keep SSH reachable for THIS apply, but you should still add an explicit rule before tightening UFW defaults. Tick the box to acknowledge and continue.') }}
                            </p>
                            <label class="mt-2 flex items-start gap-2 text-xs">
                                <input type="checkbox" wire:model.live="firewall_ack_ssh_risk" class="mt-0.5 rounded border-amber-300 text-amber-700 focus:ring-amber-500" />
                                <span>{{ __('I understand SSH may be unreachable after this apply.') }}</span>
                            </label>
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 px-6 py-4">
                        <p class="text-[11px] text-brand-mist">
                            {{ trans_choice('{0} 0 commands|{1} :n command|[2,*] :n commands', count($apply_preview_lines), ['n' => count($apply_preview_lines)]) }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-secondary-button type="button" wire:click="closeApplyPreview">{{ __('Cancel') }}</x-secondary-button>
                            <x-primary-button
                                type="button"
                                wire:click="applyFirewall(true)"
                                wire:loading.attr="disabled"
                                wire:target="applyFirewall"
                                :disabled="$sshNotCovered && ! $firewall_ack_ssh_risk"
                            >
                                <span wire:loading.remove wire:target="applyFirewall">{{ __('Run apply') }}</span>
                                <span wire:loading wire:target="applyFirewall" class="inline-flex items-center gap-2">
                                    <x-spinner variant="cream" />
                                    {{ __('Queueing…') }}
                                </span>
                            </x-primary-button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-slot>
</x-server-workspace-layout>
