@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="firewall"
    :title="__('Firewall')"
    :description="__('Manage basic UFW access on the host with rules, presets, templates, apply, status, and recent history.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer class="mb-4" tone="warn">
        <p>{{ __('This workspace manages UFW (the Ubuntu firewall) on the server. Dply tracks rules in its own database; "Apply" reconciles them onto the host with ufw allow/deny commands. Anything UFW already had that\'s NOT in the dply rule list will be removed when you apply.') }}</p>
        <p>{{ __('Presets are quick-to-apply rule bundles for common app shapes (HTTP only, HTTP+SSH from-anywhere, etc.). Templates are reusable rule sets you save and apply across servers.') }}</p>
        <p>{{ __('Locking yourself out is a real risk. Always keep an SSH allow rule in place; the workspace warns if you\'re about to apply a rule set that doesn\'t include one.') }}</p>
    </x-explainer>

    @if ($opsReady)
        <div class="space-y-6">
            <x-server-workspace-tablist :aria-label="__('Firewall workspace sections')">
                <x-server-workspace-tab id="firewall-tab-rules" :active="$firewall_workspace_tab === 'rules'" wire:click="$set('firewall_workspace_tab', 'rules')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-shield-check class="h-4 w-4" aria-hidden="true" />
                        {{ __('Rules') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="firewall-tab-templates" :active="$firewall_workspace_tab === 'templates'" wire:click="$set('firewall_workspace_tab', 'templates')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-document-duplicate class="h-4 w-4" aria-hidden="true" />
                        {{ __('Templates') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="firewall-tab-history" :active="$firewall_workspace_tab === 'history'" wire:click="$set('firewall_workspace_tab', 'history')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-clock class="h-4 w-4" aria-hidden="true" />
                        {{ __('History') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="firewall-tab-audit" :active="$firewall_workspace_tab === 'audit'" wire:click="$set('firewall_workspace_tab', 'audit')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-list-bullet class="h-4 w-4" aria-hidden="true" />
                        {{ __('Audit') }}
                    </span>
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            <x-server-workspace-tab-panel
                id="firewall-panel-rules"
                labelled-by="firewall-tab-rules"
                :hidden="$firewall_workspace_tab !== 'rules'"
                panel-class="space-y-6"
            >
        @php
            $ruleCount = $server->firewallRules->count();
            $enabledRuleCount = $server->firewallRules->where('enabled', true)->count();
            $lastApplyLog = \App\Models\ServerFirewallApplyLog::query()
                ->where('server_id', $server->id)
                ->orderByDesc('id')
                ->first();
        @endphp

        {{-- Workspace-wide console banner. Currently surfaces panel events from add/update/
             remove (via the EmitsPanelEvent trait). Phase 2 will hook the apply/diagnostics
             flows in here too once they queue. --}}
        {{-- Optimistic running banners — wire:loading shows them only while their target
             action is in flight. As soon as the request returns, these hide and the real
             panel-event banner below takes over with the actual transcript. --}}
        <div wire:loading wire:target="applyFirewall">
            <x-workspace-console-banner
                status="running"
                :message="__('Applying firewall to :host …', ['host' => $server->getSshConnectionString()])"
                :subtitle="__('Running ufw allow / deny / reload over SSH. Output will appear when it returns.')"
                :output="[]"
                :busy="true"
                :default-expanded="false"
                :dismiss-action="null"
            />
        </div>
        <div wire:loading wire:target="refreshUfwStatus">
            <x-workspace-console-banner
                status="running"
                :message="__('Reading UFW status from :host …', ['host' => $server->getSshConnectionString()])"
                :subtitle="__('Running ufw status verbose over SSH.')"
                :output="[]"
                :busy="true"
                :default-expanded="false"
                :dismiss-action="null"
            />
        </div>
        <div wire:loading wire:target="runFirewallDiagnostics">
            <x-workspace-console-banner
                status="running"
                :message="__('Running firewall diagnostics on :host …', ['host' => $server->getSshConnectionString()])"
                :subtitle="__('Running ufw status verbose · numbered · ss -ltn · iptables -L INPUT.')"
                :output="[]"
                :busy="true"
                :default-expanded="false"
                :dismiss-action="null"
            />
        </div>

        {{-- Real result banner — populated by emitPanelEvent in the Livewire methods. Hidden
             while any of the long actions is in flight to avoid showing stale data alongside
             the optimistic running banner above. --}}
        @if (! empty($panel_event_lines))
            <div wire:loading.remove wire:target="applyFirewall,refreshUfwStatus,runFirewallDiagnostics">
                @php
                    $panelSubtitle = match ($panel_event_status) {
                        'failed' => null,
                        default => __('The host firewall was touched. Output below — dismiss when you\'re done reading.'),
                    };
                @endphp
                <x-workspace-console-banner
                    :status="$panel_event_status"
                    :message="$panel_event_message"
                    :subtitle="$panelSubtitle"
                    :output="$panel_event_lines"
                    :busy="false"
                    dismiss-action="dismissPanelBanner"
                    :default-expanded="true"
                />
            </div>
        @endif

        <div class="{{ $card }} overflow-hidden">
                    <div class="flex flex-col gap-4 border-b border-brand-ink/10 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                                <x-heroicon-o-shield-check class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Firewall rules') }}</h2>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Stored in Dply, applied to the server with UFW.') }}</p>
                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                                    <span class="inline-flex items-center gap-1">
                                        <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                        {{ trans_choice('{0} no rules tracked|{1} :count rule tracked|[2,*] :count rules tracked', $ruleCount, ['count' => $ruleCount]) }}
                                        @if ($enabledRuleCount !== $ruleCount && $ruleCount > 0)
                                            ({{ __(':count enabled', ['count' => $enabledRuleCount]) }})
                                        @endif
                                    </span>
                                    @if ($lastApplyLog)
                                        <span class="text-brand-mist/60">·</span>
                                        <span class="inline-flex items-center gap-1">
                                            @if ($lastApplyLog->status === 'success')
                                                <x-heroicon-o-check-circle class="h-3 w-3 text-emerald-600" />
                                                {{ __('applied :time', ['time' => $lastApplyLog->created_at?->diffForHumans()]) }}
                                            @else
                                                <x-heroicon-o-exclamation-triangle class="h-3 w-3 text-rose-600" />
                                                {{ __('last apply failed :time', ['time' => $lastApplyLog->created_at?->diffForHumans()]) }}
                                            @endif
                                        </span>
                                    @else
                                        <span class="text-brand-mist/60">·</span>
                                        <span>{{ __('not yet applied') }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <button
                                type="button"
                                x-on:click="$wire.cancelEditRule(); $dispatch('open-modal', 'add-firewall-rule-modal')"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90"
                            >
                                <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                {{ __('Add a rule') }}
                            </button>
                            <span class="hidden h-5 w-px bg-brand-ink/10 sm:block" aria-hidden="true"></span>
                            <button
                                type="button"
                                wire:click="applyFirewall({{ $applyFirewallConfirmMessage !== '' ? 'true' : 'false' }})"
                                @if ($applyFirewallConfirmMessage !== '')
                                    wire:confirm="{{ $applyFirewallConfirmMessage }}"
                                @endif
                                wire:loading.attr="disabled"
                                wire:target="applyFirewall"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <x-heroicon-o-bolt wire:loading.remove wire:target="applyFirewall" class="h-3.5 w-3.5" />
                                <span wire:loading wire:target="applyFirewall" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                    <x-spinner variant="forest" size="sm" />
                                </span>
                                <span wire:loading.remove wire:target="applyFirewall">{{ __('Apply rules') }}</span>
                                <span wire:loading wire:target="applyFirewall">{{ __('Applying…') }}</span>
                            </button>
                            <button
                                type="button"
                                wire:click="refreshUfwStatus"
                                wire:loading.attr="disabled"
                                wire:target="refreshUfwStatus,applyFirewall"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <x-heroicon-o-arrow-path wire:loading.remove wire:target="refreshUfwStatus" class="h-3.5 w-3.5" />
                                <span wire:loading wire:target="refreshUfwStatus" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                    <x-spinner variant="forest" size="sm" />
                                </span>
                                <span wire:loading.remove wire:target="refreshUfwStatus">{{ __('Refresh status') }}</span>
                                <span wire:loading wire:target="refreshUfwStatus">{{ __('Reading…') }}</span>
                            </button>
                            <button
                                type="button"
                                wire:click="runFirewallDiagnostics"
                                wire:loading.attr="disabled"
                                wire:target="runFirewallDiagnostics,applyFirewall"
                                title="{{ __('Run ufw status verbose, status numbered, ss -ltn, and iptables -L INPUT on the server.') }}"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <x-heroicon-o-command-line wire:loading.remove wire:target="runFirewallDiagnostics" class="h-3.5 w-3.5" />
                                <span wire:loading wire:target="runFirewallDiagnostics" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                    <x-spinner variant="forest" size="sm" />
                                </span>
                                <span wire:loading.remove wire:target="runFirewallDiagnostics">{{ __('Diagnostics') }}</span>
                                <span wire:loading wire:target="runFirewallDiagnostics">{{ __('Running…') }}</span>
                            </button>
                        </div>
                    </div>

                    @if ($sshNotCovered ?? false)
                        <div class="mx-6 mt-4 rounded-xl border border-amber-300 bg-amber-50/80 px-4 py-3 text-sm text-amber-950 sm:mx-8">
                            <div class="flex items-start gap-2">
                                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" />
                                <div class="min-w-0 flex-1">
                                    <p>{{ __('No enabled Dply rule allows TCP :port from "any". Add an allow for your SSH port (or a trusted CIDR) before applying deny-heavy changes.', ['port' => $server->ssh_port ?: 22]) }}</p>
                                    <label class="mt-2 flex items-start gap-2 text-xs">
                                        <input
                                            type="checkbox"
                                            wire:model.live="firewall_ack_ssh_risk"
                                            class="mt-0.5 rounded border-amber-400 text-brand-forest focus:ring-brand-forest"
                                        />
                                        <span>{{ __('I understand SSH may be unreachable—still apply.') }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if (\App\Support\Servers\FakeCloudProvision::isFakeServer($server))
                        <div class="mx-6 mt-4 rounded-xl border border-sky-200 bg-sky-50/70 px-4 py-3 text-sm text-sky-900 sm:mx-8">
                            <div class="flex items-start gap-2">
                                <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0 text-sky-700" />
                                <div class="min-w-0 flex-1">
                                    <p>
                                        <span class="font-semibold">{{ __('Local Docker container — UFW rules here are cosmetic.') }}</span>
                                        {{ __('Docker manages the host\'s iptables; ufw inside the container does not actually filter inbound traffic. Rules added via Dply will appear in `ufw status` and exercise the apply pipeline, but real packet filtering is the host\'s job. On a real DigitalOcean droplet (or any cloud VM) ufw is the actual firewall and rules apply normally.') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div>

            @if ($server->firewallRules->isNotEmpty())
                        {{-- Bulk-action strip: tinted bg + horizontal rule above and below so it
                             reads as a distinct toolbar between the trigger header and the rules
                             table, rather than floating mid-card. --}}
                        <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/15 px-6 py-3 sm:px-8">
                            <span class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Bulk') }}</span>
                            <button
                                type="button"
                                wire:click="selectAllFirewallRules"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                            >
                                <span wire:loading.remove wire:target="selectAllFirewallRules">{{ __('Select all') }}</span>
                                <span wire:loading wire:target="selectAllFirewallRules" class="inline-flex items-center gap-1.5">
                                    <x-spinner variant="forest" size="sm" />
                                    {{ __('Selecting…') }}
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="clearFirewallBulkSelection"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-medium text-brand-moss hover:bg-brand-sand/30"
                            >
                                <span wire:loading.remove wire:target="clearFirewallBulkSelection">{{ __('Clear') }}</span>
                                <span wire:loading wire:target="clearFirewallBulkSelection" class="inline-flex items-center gap-1.5">
                                    <x-spinner variant="forest" size="sm" />
                                    {{ __('Clearing…') }}
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="bulkEnableFirewallRules"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-emerald-200 bg-emerald-50/80 px-3 py-1.5 text-xs font-medium text-emerald-900 hover:bg-emerald-100/80"
                            >
                                <span wire:loading.remove wire:target="bulkEnableFirewallRules">{{ __('Enable selected') }}</span>
                                <span wire:loading wire:target="bulkEnableFirewallRules" class="inline-flex items-center gap-1.5">
                                    <x-spinner variant="forest" size="sm" />
                                    {{ __('Enabling…') }}
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="bulkDisableFirewallRules"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                <span wire:loading.remove wire:target="bulkDisableFirewallRules">{{ __('Disable selected') }}</span>
                                <span wire:loading wire:target="bulkDisableFirewallRules" class="inline-flex items-center gap-1.5">
                                    <x-spinner variant="forest" size="sm" />
                                    {{ __('Disabling…') }}
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('bulkDeleteFirewallRules', [], @js(__('Delete selected firewall rules')), @js(__('Remove selected rules from the panel and try to delete matching UFW entries?')), @js(__('Delete selected')), true)"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-red-200 bg-red-50/80 px-3 py-1.5 text-xs font-medium text-red-800 hover:bg-red-100/80"
                            >
                                <span wire:loading.remove wire:target="bulkDeleteFirewallRules">{{ __('Delete selected') }}</span>
                                <span wire:loading wire:target="bulkDeleteFirewallRules" class="inline-flex items-center gap-1.5">
                                    <x-spinner variant="forest" size="sm" />
                                    {{ __('Deleting…') }}
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('trimDuplicateFirewallRules', [], @js(__('Trim duplicate firewall rules')), @js(__('Trim exact duplicate firewall rules and keep the first copy of each?')), @js(__('Trim duplicates')), false)"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                <span wire:loading.remove wire:target="trimDuplicateFirewallRules">{{ __('Trim duplicates') }}</span>
                                <span wire:loading wire:target="trimDuplicateFirewallRules" class="inline-flex items-center gap-1.5">
                                    <x-spinner variant="forest" size="sm" />
                                    {{ __('Trimming…') }}
                                </span>
                            </button>
                        </div>
                        <div class="mx-6 mt-5 mb-6 overflow-x-auto rounded-xl border border-brand-ink/10 sm:mx-8">
                            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                                <thead class="bg-brand-sand/30 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                    <tr>
                                        <th class="w-10 px-3 py-3" scope="col">
                                            <span class="sr-only">{{ __('Select') }}</span>
                                        </th>
                                        <th class="px-4 py-3">{{ __('Name') }}</th>
                                        <th class="px-4 py-3">{{ __('Profile') }}</th>
                                        <th class="px-4 py-3">{{ __('Action') }}</th>
                                        <th class="px-4 py-3">{{ __('Port') }}</th>
                                        <th class="px-4 py-3">{{ __('Proto') }}</th>
                                        <th class="px-4 py-3">{{ __('Source') }}</th>
                                        <th class="px-4 py-3">{{ __('On') }}</th>
                                        <th class="px-4 py-3 text-right">{{ __('') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($server->firewallRules as $fr)
                                        <tr wire:key="fw-{{ $fr->id }}" class="text-brand-ink">
                                            <td class="px-3 py-3 align-top">
                                                <input
                                                    type="checkbox"
                                                    wire:model.live="firewall_bulk_ids"
                                                    value="{{ $fr->id }}"
                                                    class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-forest"
                                                />
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 font-medium">
                                                {{ $fr->name ?: '—' }}
                                            </td>
                                            <td class="px-4 py-3 text-xs text-brand-moss">
                                                {{ $fr->profile ?: '—' }}
                                                @if (is_array($fr->tags) && $fr->tags !== [])
                                                    <span class="mt-1 block font-mono text-[0.65rem] text-brand-ink/80">{{ implode(', ', $fr->tags) }}</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 capitalize">{{ $fr->action }}</td>
                                            <td class="px-4 py-3">{{ $fr->port ?? '—' }}</td>
                                            <td class="px-4 py-3">{{ $fr->protocol }}</td>
                                            <td class="max-w-[12rem] truncate px-4 py-3 font-mono text-xs" title="{{ $fr->source }}">
                                                {{ $fr->source }}
                                            </td>
                                            <td class="px-4 py-3">
                                                <button
                                                    type="button"
                                                    wire:click="toggleFirewallRuleEnabled('{{ $fr->id }}')"
                                                    wire:loading.attr="disabled"
                                                    class="text-xs font-medium {{ $fr->enabled ? 'text-emerald-700 hover:underline' : 'text-brand-moss hover:underline' }}"
                                                >
                                                    <span wire:loading.remove wire:target="toggleFirewallRuleEnabled('{{ $fr->id }}')">
                                                        {{ $fr->enabled ? __('Yes') : __('No') }}
                                                    </span>
                                                    <span wire:loading wire:target="toggleFirewallRuleEnabled('{{ $fr->id }}')" class="inline-flex items-center gap-1">
                                                        <x-spinner variant="forest" size="sm" />
                                                        {{ __('Saving…') }}
                                                    </span>
                                                </button>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                                <div class="inline-flex flex-wrap items-center justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        wire:click="startEditRule('{{ $fr->id }}')"
                                                        wire:loading.attr="disabled"
                                                        x-on:click="$dispatch('open-modal', 'add-firewall-rule-modal')"
                                                        class="text-xs font-medium text-brand-forest hover:underline"
                                                    >
                                                        <span wire:loading.remove wire:target="startEditRule('{{ $fr->id }}')">{{ __('Edit') }}</span>
                                                        <span wire:loading wire:target="startEditRule('{{ $fr->id }}')" class="inline-flex items-center gap-1">
                                                            <x-spinner variant="forest" size="sm" />
                                                            {{ __('Loading…') }}
                                                        </span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('deleteFirewallRule', ['{{ $fr->id }}'], @js(__('Delete firewall rule')), @js(__('Remove this rule from the panel and try to delete the matching UFW entry?')), @js(__('Delete rule')), true)"
                                                        wire:loading.attr="disabled"
                                                        class="text-xs font-medium text-red-600 hover:underline"
                                                    >
                                                        <span wire:loading.remove wire:target="deleteFirewallRule('{{ $fr->id }}')">{{ __('Remove') }}</span>
                                                        <span wire:loading wire:target="deleteFirewallRule('{{ $fr->id }}')" class="inline-flex items-center gap-1">
                                                            <x-spinner variant="forest" size="sm" />
                                                            {{ __('Removing…') }}
                                                        </span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="px-6 py-8 text-center sm:px-8">
                            <p class="text-sm text-brand-moss">{{ __('No rules yet. Click "Add a rule" or start from a template.') }}</p>
                        </div>
                    @endif

                    @php
                        $hasAdvanced = trim((string) ($form->name ?? '')) !== ''
                            || trim((string) ($form->profile ?? '')) !== ''
                            || trim((string) ($form->tags ?? '')) !== ''
                            || trim((string) ($form->runbook_url ?? '')) !== ''
                            || trim((string) ($form->site_id ?? '')) !== '';
                    @endphp

                    {{-- Add / Edit rule modal. Triggered by the "Add a rule" button on the trigger
                         card and by the per-row "Edit" button (which sets editing_rule_id first,
                         then opens this modal). Closes on successful saveFirewallRule (Livewire
                         dispatches close-modal from the action). --}}
                    <x-modal name="add-firewall-rule-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
                        <div class="border-b border-brand-ink/10 px-6 py-5">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Firewall rule') }}</p>
                            <h2 class="mt-2 text-xl font-semibold text-brand-ink">
                                @if ($editing_rule_id)
                                    {{ __('Edit firewall rule') }}
                                @else
                                    {{ __('Add a firewall rule') }}
                                @endif
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-brand-moss">
                                {{ __('Saved here · only written to the host on Apply.') }}
                            </p>
                        </div>

                        <div class="px-6 py-6">
                            @if (! $editing_rule_id)
                                <p class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Quick presets') }}</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach (config('server_firewall.presets', []) as $presetKey => $preset)
                                        <button
                                            type="button"
                                            wire:click="useFirewallPreset('{{ $presetKey }}')"
                                            class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/60"
                                        >
                                            {{ __($preset['label'] ?? $presetKey) }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            <form wire:submit="saveFirewallRule" id="add-firewall-rule-form" class="@if (! $editing_rule_id) mt-5 @endif space-y-4">
                                {{-- Essentials: Port · Protocol · Action on one row, Source on the next. --}}
                                <div class="grid gap-3 sm:grid-cols-3">
                                    @if (! in_array($form->protocol, ['icmp', 'ipv6-icmp'], true))
                                        <div>
                                            <x-input-label for="fw-port" :value="__('Port')" />
                                            <x-text-input id="fw-port" type="number" class="mt-1 block w-full" wire:model="form.port" min="1" max="65535" />
                                            <x-input-error :messages="$errors->get('form.port')" class="mt-1" />
                                        </div>
                                    @endif
                                    <div @class([
                                        'sm:col-span-1' => ! in_array($form->protocol, ['icmp', 'ipv6-icmp'], true),
                                        'sm:col-span-2' => in_array($form->protocol, ['icmp', 'ipv6-icmp'], true),
                                    ])>
                                        <x-input-label for="fw-proto" :value="__('Protocol')" />
                                        <select id="fw-proto" wire:model.live="form.protocol" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm">
                                            <option value="tcp">TCP</option>
                                            <option value="udp">UDP</option>
                                            <option value="icmp">ICMP (IPv4)</option>
                                            <option value="ipv6-icmp">{{ __('ICMPv6') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <x-input-label for="fw-action" :value="__('Action')" />
                                        <select id="fw-action" wire:model="form.action" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm">
                                            <option value="allow">{{ __('Allow') }}</option>
                                            <option value="deny">{{ __('Deny') }}</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <x-input-label for="fw-source" :value="__('Source')" />
                                    <x-text-input id="fw-source" type="text" class="mt-1 block w-full font-mono text-sm" wire:model="form.source" placeholder="any" autocomplete="off" />
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('Use :keyword for any host, or an IPv4/IPv6 address or CIDR.', ['keyword' => 'any']) }}</p>
                                    <x-input-error :messages="$errors->get('form.source')" class="mt-1" />
                                </div>

                                <label class="flex items-center gap-2 text-sm">
                                    <input id="fw-enabled" type="checkbox" wire:model="form.enabled" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-forest" />
                                    <span>{{ __('Enabled (included when applying)') }}</span>
                                </label>

                                {{-- Advanced — label / profile / tags / runbook / related site. Auto-opens
                                     when any of these have content (e.g. when editing an existing rule). --}}
                                <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3" @if ($hasAdvanced) open @endif>
                                    <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-brand-mist">
                                        <span class="inline-flex items-center gap-1.5">
                                            <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                                            {{ __('Advanced — naming, tags, runbook, related site') }}
                                        </span>
                                    </summary>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                        <div class="sm:col-span-2">
                                            <x-input-label for="fw-name" :value="__('Label (optional)')" />
                                            <x-text-input id="fw-name" type="text" class="mt-1 block w-full" wire:model="form.name" placeholder="{{ __('e.g. Monitoring, Office VPN') }}" />
                                            <x-input-error :messages="$errors->get('form.name')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="fw-profile" :value="__('Profile (optional)')" />
                                            <x-text-input id="fw-profile" type="text" class="mt-1 block w-full" wire:model="form.profile" placeholder="{{ __('web, db, admin…') }}" />
                                            <x-input-error :messages="$errors->get('form.profile')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="fw-tags" :value="__('Tags (comma-separated)')" />
                                            <x-text-input id="fw-tags" type="text" class="mt-1 block w-full" wire:model="form.tags" placeholder="{{ __('monitoring, prod, …') }}" />
                                            <x-input-error :messages="$errors->get('form.tags')" class="mt-1" />
                                        </div>
                                        <div class="sm:col-span-2">
                                            <x-input-label for="fw-runbook" :value="__('Runbook URL (optional)')" />
                                            <x-text-input id="fw-runbook" type="url" class="mt-1 block w-full" wire:model="form.runbook_url" placeholder="https://…" />
                                            <x-input-error :messages="$errors->get('form.runbook_url')" class="mt-1" />
                                        </div>
                                        <div class="sm:col-span-2">
                                            <x-input-label for="fw-site" :value="__('Related site (optional)')" />
                                            <select id="fw-site" wire:model="form.site_id" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm">
                                                <option value="">{{ __('— None —') }}</option>
                                                @foreach ($server->sites as $site)
                                                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                                                @endforeach
                                            </select>
                                            <x-input-error :messages="$errors->get('form.site_id')" class="mt-1" />
                                        </div>
                                    </div>
                                </details>
                            </form>
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                            @if ($editing_rule_id)
                                <x-secondary-button type="button" wire:click="cancelEditRule" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                                <x-primary-button type="submit" form="add-firewall-rule-form" wire:loading.attr="disabled" wire:target="saveFirewallRule">
                                    <span wire:loading.remove wire:target="saveFirewallRule">{{ __('Save changes') }}</span>
                                    <span wire:loading wire:target="saveFirewallRule">{{ __('Saving…') }}</span>
                                </x-primary-button>
                            @else
                                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                                <x-primary-button type="submit" form="add-firewall-rule-form" wire:loading.attr="disabled" wire:target="saveFirewallRule">
                                    <span wire:loading.remove wire:target="saveFirewallRule">{{ __('Add rule') }}</span>
                                    <span wire:loading wire:target="saveFirewallRule">{{ __('Saving…') }}</span>
                                </x-primary-button>
                            @endif
                        </div>
                    </x-modal>

                    {{-- UFW status + diagnostics output is now surfaced through the workspace
                         console banner above (Refresh status / Diagnostics actions populate it
                         via emitPanelEvent). The previous inline `<pre>` and full-page modal
                         have been removed in favor of the shared banner pattern. --}}
                    </div>
                </div>

                {{-- Listening ports — what's actually bound on the host right
                     now, sourced from the inventory probe. Useful context
                     when adding or tightening rules: "is this port even
                     open?" "what process is bound there?" Renders nothing
                     when meta.manage_listening_ports is empty (e.g. the
                     server hasn't been probed yet). --}}
                @include('livewire.servers.partials.server-listening-ports', ['server' => $server])
            </x-server-workspace-tab-panel>

            <x-server-workspace-tab-panel
                id="firewall-panel-templates"
                labelled-by="firewall-tab-templates"
                :hidden="$firewall_workspace_tab !== 'templates'"
            >
                <div class="{{ $card }} p-6 sm:p-8 space-y-8">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Bundled templates') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Adds rules to this server’s list (does not replace existing rows).') }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach ($bundledTemplates as $bKey => $b)
                                <button
                                    type="button"
                                    wire:click="applyBundledFirewallTemplate('{{ $bKey }}')"
                                    class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/60"
                                >
                                    {{ __($b['label'] ?? $bKey) }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @if ($savedTemplates->isNotEmpty())
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Saved templates') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss">{{ __('Organization or server-scoped templates.') }}</p>
                            <ul class="mt-4 space-y-2">
                                @foreach ($savedTemplates as $tpl)
                                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-brand-ink/10 px-3 py-2 text-sm">
                                        <span>
                                            <span class="font-medium text-brand-ink">{{ $tpl->name }}</span>
                                            @if ($tpl->server_id)
                                                <span class="ml-2 text-xs text-brand-moss">{{ __('This server') }}</span>
                                            @else
                                                <span class="ml-2 text-xs text-brand-moss">{{ __('Organization') }}</span>
                                            @endif
                                        </span>
                                        <button
                                            type="button"
                                            wire:click="applySavedFirewallTemplate('{{ $tpl->id }}')"
                                            class="text-xs font-medium text-brand-forest hover:underline"
                                        >
                                            {{ __('Apply') }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="border-t border-brand-ink/10 pt-6">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Save current rules as template') }}</h2>
                        <form wire:submit="saveCurrentRulesAsTemplate" class="mt-4 grid gap-3 sm:max-w-lg">
                            <div>
                                <x-input-label for="tpl-name" :value="__('Name')" />
                                <x-text-input id="tpl-name" type="text" class="mt-1 block w-full" wire:model="new_saved_template_name" />
                                <x-input-error :messages="$errors->get('new_saved_template_name')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="tpl-desc" :value="__('Description (optional)')" />
                                <x-text-input id="tpl-desc" type="text" class="mt-1 block w-full" wire:model="new_saved_template_description" />
                            </div>
                            <div>
                                <x-input-label for="tpl-scope" :value="__('Scope')" />
                                <select id="tpl-scope" wire:model="new_saved_template_scope" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm">
                                    <option value="org">{{ __('Whole organization') }}</option>
                                    <option value="server">{{ __('This server only') }}</option>
                                </select>
                            </div>
                            <x-primary-button type="submit" class="!py-2 w-fit">{{ __('Save template') }}</x-primary-button>
                        </form>
                    </div>
                </div>
            </x-server-workspace-tab-panel>

            <x-server-workspace-tab-panel
                id="firewall-panel-history"
                labelled-by="firewall-tab-history"
                :hidden="$firewall_workspace_tab !== 'history'"
            >
                <div class="{{ $card }} p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Apply history') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Review recent firewall apply attempts and the rule set hash recorded for each run.') }}</p>

                    @if (isset($applyLogs) && $applyLogs->isNotEmpty())
                        <ul class="mt-6 space-y-3 text-sm">
                            @foreach ($applyLogs as $log)
                                <li class="border-b border-brand-ink/5 pb-3 last:border-0">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="font-medium {{ $log->success ? 'text-emerald-800' : 'text-red-700' }}">
                                            {{ $log->success ? __('Applied') : __('Failed') }}
                                        </span>
                                        <span class="text-xs text-brand-moss">{{ $log->created_at?->diffForHumans() }}</span>
                                    </div>
                                    <p class="mt-1 font-mono text-xs text-brand-ink/80">
                                        {{ $log->rules_hash ? substr($log->rules_hash, 0, 12).'…' : '—' }}
                                        · {{ $log->rule_count }} {{ __('rules') }}
                                    </p>
                                    @if ($log->message)
                                        <p class="mt-1 text-xs text-brand-moss">{{ \Illuminate\Support\Str::limit($log->message, 240) }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-6 text-sm text-brand-moss">{{ __('No firewall apply history yet.') }}</p>
                    @endif
                </div>
            </x-server-workspace-tab-panel>

            <x-server-workspace-tab-panel
                id="firewall-panel-audit"
                labelled-by="firewall-tab-audit"
                :hidden="$firewall_workspace_tab !== 'audit'"
            >
                <div class="{{ $card }} p-6 sm:p-8">
                    @php
                        $firewallAuditCount = $auditEvents->count();
                        $latestFirewallAudit = $auditEvents->first()?->created_at;
                    @endphp
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                            <x-heroicon-o-clock class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Recent audit') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Track recent firewall changes, template applications, and apply activity for this server.') }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                                <span class="inline-flex items-center gap-1">
                                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                    {{ trans_choice('{0} no events recorded|{1} :count event recorded|[2,*] :count events recorded', $firewallAuditCount, ['count' => $firewallAuditCount]) }}
                                </span>
                                @if ($latestFirewallAudit)
                                    <span class="text-brand-mist/60">·</span>
                                    <span>{{ __('latest :time', ['time' => $latestFirewallAudit->diffForHumans()]) }}</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if ($auditEvents->isNotEmpty())
                        <ul class="mt-6 space-y-2 text-sm text-brand-moss">
                            @foreach ($auditEvents as $ev)
                                <li class="flex flex-wrap justify-between gap-2 border-b border-brand-ink/5 pb-2">
                                    <span class="font-mono text-xs text-brand-ink">{{ $ev->event }}</span>
                                    <span class="text-xs">{{ $ev->created_at?->diffForHumans() }}</span>
                                    <span class="w-full text-xs">{{ $ev->user?->name ?? __('API') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-6 text-sm text-brand-moss">{{ __('No firewall audit events yet.') }}</p>
                    @endif
                </div>
            </x-server-workspace-tab-panel>
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
    </x-slot>
</x-server-workspace-layout>
