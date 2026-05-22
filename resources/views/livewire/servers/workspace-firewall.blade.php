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
        <p>{{ __('This workspace manages UFW (the Ubuntu firewall) on the server. Dply tracks rules in its own database; "Apply" pushes every enabled panel rule to the host with ufw allow/deny and turns UFW on. Apply is additive — host rules that aren\'t in the panel are left untouched. To get rid of a host rule, use "Import from host" to pull it into the panel, then click Remove (or toggle it off) — Dply will run the matching ufw delete inline.') }}</p>
        <p>{{ __('Presets are quick-to-apply rule bundles for common app shapes (HTTP only, HTTP+SSH from-anywhere, etc.). Templates are reusable rule sets you save and apply across servers.') }}</p>
        <p>{{ __('Locking yourself out is a real risk. Apply always re-adds an allow rule for the server\'s SSH port as a safety rail, but you should still keep an explicit SSH allow in the panel — the workspace warns if you\'re about to apply a rule set that doesn\'t include one.') }}</p>
    </x-explainer>

    @if ($opsReady)
        <div class="space-y-6">

        {{-- Workspace-wide banner row. Positioned above the tab strip so apply / refresh /
             diagnostics output spans the full content width and is visible on every tab. --}}
        @php
            $applyStatus = (string) data_get($server->meta ?? [], config('server_firewall.meta_apply_status_key'));
            $applyRunId = (string) data_get($server->meta ?? [], config('server_firewall.meta_apply_run_id_key'));
            $applyError = (string) data_get($server->meta ?? [], config('server_firewall.meta_apply_error_key'));
            $applyFinishedAt = data_get($server->meta ?? [], config('server_firewall.meta_apply_finished_at_key'));
            $applyBusy = in_array($applyStatus, ['queued', 'running'], true);
            $applyShowBanner = $applyRunId !== '' && in_array($applyStatus, ['queued', 'running', 'completed', 'failed'], true);
        @endphp

        @if ($applyShowBanner)
            @php
                $applyMessage = match ($applyStatus) {
                    'queued' => __('Firewall apply queued — waiting for a worker to pick it up…'),
                    'running' => __('Applying firewall to :host …', ['host' => $server->getSshConnectionString()]),
                    'completed' => __('Firewall applied — UFW updated.'),
                    'failed' => __('Firewall apply failed.'),
                    default => '',
                };
                $applySubtitle = match (true) {
                    $applyBusy => __('Refreshing every 4s · safe to leave this page — the job runs on the queue.'),
                    $applyStatus === 'failed' && $applyError !== '' => $applyError,
                    $applyStatus === 'completed' && $applyFinishedAt
                        => __('Finished :time', ['time' => \Illuminate\Support\Carbon::parse($applyFinishedAt)->diffForHumans()]),
                    default => null,
                };
            @endphp
            <x-workspace-console-banner
                :status="$applyStatus"
                :message="$applyMessage"
                :subtitle="$applySubtitle"
                :output="$this->applyOutputLines"
                :busy="$applyBusy"
                :dismiss-action="$applyBusy ? null : 'dismissApplyBanner'"
                :poll-action="$applyBusy ? 'pollApplyStatus' : null"
                poll-interval="4s"
                :default-expanded="true"
            />
        @endif

        <div wire:loading.block wire:target="refreshUfwStatus" class="w-full">
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
        <div wire:loading.block wire:target="runFirewallDiagnostics" class="w-full">
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

        @if (! $applyShowBanner && ! empty($panel_event_lines))
            <div wire:loading.remove wire:target="refreshUfwStatus,runFirewallDiagnostics">
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
                <x-server-workspace-tab id="firewall-tab-activity" :active="$firewall_workspace_tab === 'activity'" wire:click="$set('firewall_workspace_tab', 'activity')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-clock class="h-4 w-4" aria-hidden="true" />
                        {{ __('Activity') }}
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
            $defaultPolicyFallbacks = (array) config('server_firewall.default_policy_fallbacks', []);
            $defaultPolicyChains = [
                'incoming' => __('Incoming'),
                'outgoing' => __('Outgoing'),
                'routed' => __('Routed (forwarded)'),
            ];
            $defaultPolicyChoices = (array) config('server_firewall.default_policies', ['allow', 'deny', 'reject']);
        @endphp

        <details class="{{ $card }} overflow-hidden" {{ $defaultPolicies !== [] ? 'open' : '' }}>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-6 py-4 sm:px-8">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                        <x-heroicon-o-adjustments-horizontal class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Default chain policies') }}</h2>
                        <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('What UFW does with traffic that doesn\'t match any rule. "Use UFW default" leaves the host\'s current setting alone; a chosen value is pushed on the next Apply.') }}</p>
                        @if ($defaultPolicies === [])
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('No overrides set — UFW defaults (deny incoming, allow outgoing, deny routed) apply.') }}</p>
                        @else
                            <p class="mt-1 text-[11px] font-mono text-brand-mist">
                                @foreach ($defaultPolicies as $chain => $policy)
                                    {{ $chain }}={{ $policy }}@if (! $loop->last)<span class="text-brand-mist/60"> · </span>@endif
                                @endforeach
                            </p>
                        @endif
                    </div>
                </div>
                <span class="text-brand-mist transition-transform group-open:rotate-180">
                    <x-heroicon-o-chevron-down class="h-4 w-4" />
                </span>
            </summary>
            <div class="border-t border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach ($defaultPolicyChains as $chain => $label)
                        @php
                            $current = $defaultPolicies[$chain] ?? '';
                            $hostDefault = $defaultPolicyFallbacks[$chain] ?? 'deny';
                        @endphp
                        <div>
                            <x-input-label :for="'fw-default-' . $chain" :value="$label" />
                            <select
                                id="fw-default-{{ $chain }}"
                                wire:change="setFirewallDefaultPolicy('{{ $chain }}', $event.target.value)"
                                class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm"
                            >
                                <option value="" @selected($current === '')>{{ __('Use UFW default (:p)', ['p' => $hostDefault]) }}</option>
                                @foreach ($defaultPolicyChoices as $choice)
                                    <option value="{{ $choice }}" @selected($current === $choice)>{{ ucfirst($choice) }}</option>
                                @endforeach
                            </select>
                            @if ($chain === 'incoming' && $current === 'allow')
                                <p class="mt-1 text-[11px] text-amber-700">{{ __('Allow-incoming defeats the firewall — only use if you\'re managing inbound separately.') }}</p>
                            @endif
                            @if ($chain === 'outgoing' && $current === 'deny')
                                <p class="mt-1 text-[11px] text-amber-700">{{ __('Deny-outgoing breaks DNS, package updates, and most app traffic — add explicit allow rules for everything you need.') }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 grid gap-4 sm:max-w-xs">
                    <div>
                        <x-input-label for="fw-logging" :value="__('Logging level')" />
                        <select
                            id="fw-logging"
                            wire:change="setFirewallLoggingLevel($event.target.value)"
                            class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm"
                        >
                            <option value="" @selected(empty($loggingLevel))>{{ __('Leave host setting alone') }}</option>
                            @foreach ((array) config('server_firewall.logging_levels', []) as $lvl)
                                <option value="{{ $lvl }}" @selected($loggingLevel === $lvl)>{{ ucfirst($lvl) }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('off · low (UFW default — blocked only) · medium (+ accepted) · high (+ invalid) · full (everything).') }}</p>
                    </div>
                </div>
                <p class="mt-3 text-[11px] text-brand-mist">{{ __('Changes are written to /etc/default/ufw and /etc/ufw/ufw.conf on Apply and take effect on ufw --force enable.') }}</p>
            </div>
        </details>

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
                                wire:click="previewApplyFirewall"
                                wire:loading.attr="disabled"
                                wire:target="previewApplyFirewall,applyFirewall"
                                @disabled($applyBusy)
                                title="{{ $applyBusy ? __('A firewall apply is already running. Wait for it to finish.') : __('Preview the ufw commands, then confirm to queue the apply.') }}"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <x-heroicon-o-bolt wire:loading.remove wire:target="previewApplyFirewall,applyFirewall" class="h-3.5 w-3.5" />
                                <span wire:loading wire:target="previewApplyFirewall,applyFirewall" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                    <x-spinner variant="forest" size="sm" />
                                </span>
                                <span wire:loading.remove wire:target="previewApplyFirewall,applyFirewall">{{ $applyBusy ? __('Applying…') : __('Apply rules…') }}</span>
                                <span wire:loading wire:target="previewApplyFirewall,applyFirewall">{{ __('Working…') }}</span>
                            </button>
                            <x-dropdown align="right" width="w-56" contentClasses="py-1.5">
                                <x-slot name="trigger">
                                    <button
                                        type="button"
                                        class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        aria-haspopup="menu"
                                        aria-label="{{ __('More firewall actions') }}"
                                    >
                                        {{ __('More') }}
                                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5 text-brand-ink/70" />
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <button
                                        type="button"
                                        wire:click="refreshUfwStatus"
                                        wire:loading.attr="disabled"
                                        wire:target="refreshUfwStatus,applyFirewall"
                                        class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span class="inline-flex items-center gap-2">
                                            <x-heroicon-o-arrow-path wire:loading.remove wire:target="refreshUfwStatus" class="h-3.5 w-3.5 text-brand-moss" />
                                            <span wire:loading wire:target="refreshUfwStatus" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                                <x-spinner variant="forest" size="sm" />
                                            </span>
                                            <span wire:loading.remove wire:target="refreshUfwStatus">{{ __('Refresh status') }}</span>
                                            <span wire:loading wire:target="refreshUfwStatus">{{ __('Reading…') }}</span>
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="runFirewallDiagnostics"
                                        wire:loading.attr="disabled"
                                        wire:target="runFirewallDiagnostics,applyFirewall"
                                        title="{{ __('Run ufw status verbose, status numbered, ss -ltn, and iptables -L INPUT on the server.') }}"
                                        class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span class="inline-flex items-center gap-2">
                                            <x-heroicon-o-command-line wire:loading.remove wire:target="runFirewallDiagnostics" class="h-3.5 w-3.5 text-brand-moss" />
                                            <span wire:loading wire:target="runFirewallDiagnostics" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                                <x-spinner variant="forest" size="sm" />
                                            </span>
                                            <span wire:loading.remove wire:target="runFirewallDiagnostics">{{ __('Diagnostics') }}</span>
                                            <span wire:loading wire:target="runFirewallDiagnostics">{{ __('Running…') }}</span>
                                        </span>
                                    </button>
                                    <div class="my-1 border-t border-brand-ink/10" role="presentation"></div>
                                    <button
                                        type="button"
                                        wire:click="previewImportHostRules"
                                        wire:loading.attr="disabled"
                                        wire:target="previewImportHostRules,applyFirewall"
                                        title="{{ __('Read user-added rules from UFW (`ufw show added`) and pick which to import into the panel.') }}"
                                        class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span class="inline-flex items-center gap-2">
                                            <x-heroicon-o-arrow-down-tray wire:loading.remove wire:target="previewImportHostRules" class="h-3.5 w-3.5 text-brand-moss" />
                                            <span wire:loading wire:target="previewImportHostRules" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                                <x-spinner variant="forest" size="sm" />
                                            </span>
                                            <span wire:loading.remove wire:target="previewImportHostRules">{{ __('Import from host') }}</span>
                                            <span wire:loading wire:target="previewImportHostRules">{{ __('Reading…') }}</span>
                                        </span>
                                    </button>
                                    <div class="my-1 border-t border-brand-ink/10" role="presentation"></div>
                                    <button
                                        type="button"
                                        wire:click="exportFirewallRulesJson"
                                        title="{{ __('Download all rules as JSON (round-trippable — re-import as a template later).') }}"
                                        class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50"
                                    >
                                        <span class="inline-flex items-center gap-2">
                                            <x-heroicon-o-arrow-up-tray class="h-3.5 w-3.5 text-brand-moss" />
                                            {{ __('Export rules (JSON)') }}
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="exportFirewallRulesCsv"
                                        title="{{ __('Download all rules as CSV — handy for audits or spreadsheets.') }}"
                                        class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50"
                                    >
                                        <span class="inline-flex items-center gap-2">
                                            <x-heroicon-o-table-cells class="h-3.5 w-3.5 text-brand-moss" />
                                            {{ __('Export rules (CSV)') }}
                                        </span>
                                    </button>
                                </x-slot>
                            </x-dropdown>
                        </div>
                    </div>

                    @if ($sshNotCovered ?? false)
                        <div class="mx-6 mt-4 rounded-xl border border-amber-300 bg-amber-50/80 px-4 py-3 text-sm text-amber-950 sm:mx-8">
                            <div class="flex items-start gap-2">
                                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" />
                                <div class="min-w-0 flex-1">
                                    <p>{{ __('No enabled Dply rule allows TCP :port from "any". Add an allow for your SSH port (or a trusted CIDR) before applying deny-heavy changes.', ['port' => $server->ssh_port ?: 22]) }}</p>
                                    <div class="mt-2 flex flex-wrap items-center gap-3">
                                        <button
                                            type="button"
                                            wire:click="ensureSshAllowRule"
                                            wire:loading.attr="disabled"
                                            wire:target="ensureSshAllowRule"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-amber-400 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 shadow-sm hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            <x-heroicon-o-shield-check wire:loading.remove wire:target="ensureSshAllowRule" class="h-3.5 w-3.5" />
                                            <span wire:loading wire:target="ensureSshAllowRule" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                                <x-spinner variant="forest" size="sm" />
                                            </span>
                                            <span wire:loading.remove wire:target="ensureSshAllowRule">{{ __('Add SSH allow rule') }}</span>
                                            <span wire:loading wire:target="ensureSshAllowRule">{{ __('Adding…') }}</span>
                                        </button>
                                        <label class="flex items-start gap-2 text-xs">
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
                        {{-- Contextual bulk-action toolbar: only renders when one or more row
                             checkboxes are ticked. Per-row checkboxes (still visible in the
                             table below) are how an operator starts a selection. --}}
                        @php $bulkSelectedCount = count(array_filter((array) ($firewall_bulk_ids ?? []))); @endphp
                        @if ($bulkSelectedCount > 0)
                            <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/15 px-6 py-3 sm:px-8">
                                <span class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __(':n selected', ['n' => $bulkSelectedCount]) }}</span>
                                <button
                                    type="button"
                                    wire:click="selectAllFirewallRules"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                                >
                                    <x-heroicon-o-check-circle wire:loading.remove wire:target="selectAllFirewallRules" class="h-3.5 w-3.5 text-brand-moss" />
                                    <span wire:loading wire:target="selectAllFirewallRules" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                        <x-spinner variant="forest" size="sm" />
                                    </span>
                                    <span wire:loading.remove wire:target="selectAllFirewallRules">{{ __('Select all') }}</span>
                                    <span wire:loading wire:target="selectAllFirewallRules">{{ __('Selecting…') }}</span>
                                </button>
                                <button
                                    type="button"
                                    wire:click="clearFirewallBulkSelection"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-medium text-brand-moss hover:bg-brand-sand/30"
                                >
                                    <x-heroicon-o-x-circle wire:loading.remove wire:target="clearFirewallBulkSelection" class="h-3.5 w-3.5" />
                                    <span wire:loading wire:target="clearFirewallBulkSelection" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                        <x-spinner variant="forest" size="sm" />
                                    </span>
                                    <span wire:loading.remove wire:target="clearFirewallBulkSelection">{{ __('Clear') }}</span>
                                    <span wire:loading wire:target="clearFirewallBulkSelection">{{ __('Clearing…') }}</span>
                                </button>
                                <button
                                    type="button"
                                    wire:click="bulkEnableFirewallRules"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50/80 px-3 py-1.5 text-xs font-medium text-emerald-900 hover:bg-emerald-100/80"
                                >
                                    <x-heroicon-o-check wire:loading.remove wire:target="bulkEnableFirewallRules" class="h-3.5 w-3.5" />
                                    <span wire:loading wire:target="bulkEnableFirewallRules" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                        <x-spinner variant="forest" size="sm" />
                                    </span>
                                    <span wire:loading.remove wire:target="bulkEnableFirewallRules">{{ __('Enable selected') }}</span>
                                    <span wire:loading wire:target="bulkEnableFirewallRules">{{ __('Enabling…') }}</span>
                                </button>
                                <button
                                    type="button"
                                    wire:click="bulkDisableFirewallRules"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-no-symbol wire:loading.remove wire:target="bulkDisableFirewallRules" class="h-3.5 w-3.5 text-brand-moss" />
                                    <span wire:loading wire:target="bulkDisableFirewallRules" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                        <x-spinner variant="forest" size="sm" />
                                    </span>
                                    <span wire:loading.remove wire:target="bulkDisableFirewallRules">{{ __('Disable selected') }}</span>
                                    <span wire:loading wire:target="bulkDisableFirewallRules">{{ __('Disabling…') }}</span>
                                </button>
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('bulkDeleteFirewallRules', [], @js(__('Delete selected firewall rules')), @js(__('Remove selected rules from the panel and try to delete matching UFW entries?')), @js(__('Delete selected')), true)"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50/80 px-3 py-1.5 text-xs font-medium text-red-800 hover:bg-red-100/80"
                                >
                                    <x-heroicon-o-trash wire:loading.remove wire:target="bulkDeleteFirewallRules" class="h-3.5 w-3.5" />
                                    <span wire:loading wire:target="bulkDeleteFirewallRules" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                        <x-spinner variant="forest" size="sm" />
                                    </span>
                                    <span wire:loading.remove wire:target="bulkDeleteFirewallRules">{{ __('Delete selected') }}</span>
                                    <span wire:loading wire:target="bulkDeleteFirewallRules">{{ __('Deleting…') }}</span>
                                </button>
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('trimDuplicateFirewallRules', [], @js(__('Trim duplicate firewall rules')), @js(__('Trim exact duplicate firewall rules and keep the first copy of each?')), @js(__('Trim duplicates')), false)"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-scissors wire:loading.remove wire:target="trimDuplicateFirewallRules" class="h-3.5 w-3.5 text-brand-moss" />
                                    <span wire:loading wire:target="trimDuplicateFirewallRules" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                        <x-spinner variant="forest" size="sm" />
                                    </span>
                                    <span wire:loading.remove wire:target="trimDuplicateFirewallRules">{{ __('Trim duplicates') }}</span>
                                    <span wire:loading wire:target="trimDuplicateFirewallRules">{{ __('Trimming…') }}</span>
                                </button>
                            </div>
                        @endif
                        @php
                            $filteredRules = $this->filteredFirewallRules($server->firewallRules);
                            $filteredCount = $filteredRules->count();
                            $hasActiveFilter = trim($rule_filter) !== '' || trim($rule_filter_action) !== '';
                        @endphp

                        <div class="mx-6 mt-5 flex flex-col gap-2 sm:mx-8 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex flex-1 items-center gap-2">
                                <div class="relative flex-1 sm:max-w-md">
                                    <span class="pointer-events-none absolute inset-y-0 left-2 inline-flex items-center text-brand-mist">
                                        <x-heroicon-o-magnifying-glass class="h-4 w-4" />
                                    </span>
                                    <input
                                        type="search"
                                        wire:model.live.debounce.250ms="rule_filter"
                                        class="block w-full rounded-lg border border-brand-ink/15 bg-white py-1.5 pl-8 pr-3 text-xs text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-forest focus:ring-brand-forest"
                                        placeholder="{{ __('Search rules — name, port, source, profile, iface, tags…') }}"
                                        autocomplete="off"
                                    />
                                </div>
                                <div class="inline-flex rounded-lg border border-brand-ink/10 bg-white p-0.5 text-[11px] font-semibold" role="group" aria-label="{{ __('Filter by action') }}">
                                    @foreach (['' => __('All'), 'allow' => __('Allow'), 'deny' => __('Deny'), 'limit' => __('Limit')] as $key => $label)
                                        <button
                                            type="button"
                                            wire:click="$set('rule_filter_action', '{{ $key }}')"
                                            @class([
                                                'inline-flex items-center rounded-md px-2 py-1 transition-colors',
                                                'bg-brand-sand/60 text-brand-ink' => $rule_filter_action === $key,
                                                'text-brand-moss hover:bg-brand-sand/30' => $rule_filter_action !== $key,
                                            ])
                                        >{{ $label }}</button>
                                    @endforeach
                                </div>
                            </div>
                            @if ($hasActiveFilter)
                                <button type="button" wire:click="clearRuleFilter" class="inline-flex items-center gap-1 text-[11px] font-medium text-brand-forest hover:underline">
                                    <x-heroicon-o-x-circle class="h-3.5 w-3.5" />
                                    {{ __('Clear · :n / :m matching', ['n' => $filteredCount, 'm' => $server->firewallRules->count()]) }}
                                </button>
                            @endif
                        </div>

                        <div class="mx-6 mt-3 mb-6 overflow-x-auto rounded-xl border border-brand-ink/10 sm:mx-8">
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
                                    @if ($filteredRules->isEmpty())
                                        <tr>
                                            <td colspan="9" class="px-4 py-6 text-center text-xs italic text-brand-mist">
                                                {{ __('No rules match the filter. Clear it to see all :n rules.', ['n' => $server->firewallRules->count()]) }}
                                            </td>
                                        </tr>
                                    @endif
                    @foreach ($filteredRules as $fr)
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
                                            <td class="px-4 py-3">
                                                @if (! empty($fr->app_profile))
                                                    <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wide text-brand-moss" title="{{ __('UFW application profile') }}">
                                                        app
                                                    </span>
                                                @else
                                                    {{ $fr->port ?? '—' }}
                                                @endif
                                            </td>
                                            <td class="px-4 py-3">
                                                @if (! empty($fr->app_profile))
                                                    <span class="font-mono text-xs">{{ $fr->app_profile }}</span>
                                                @else
                                                    {{ $fr->protocol }}
                                                @endif
                                            </td>
                                            <td class="max-w-[12rem] truncate px-4 py-3 font-mono text-xs" title="{{ $fr->source }}{{ ! empty($fr->iface) ? ' · '.$fr->iface_direction.' on '.$fr->iface : '' }}">
                                                {{ $fr->source }}
                                                @if (! empty($fr->iface))
                                                    <span class="mt-0.5 block text-[10px] uppercase tracking-wide text-brand-mist">
                                                        {{ $fr->iface_direction ?: 'in' }} on <span class="text-brand-ink/80">{{ $fr->iface }}</span>
                                                    </span>
                                                @endif
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
                                                    @php
                                                        // When a filter is active, hide reorder buttons — they'd be confusing
                                                        // because $loop->first/last are relative to the filtered slice, not the
                                                        // full rule list that determines actual sort_order.
                                                        $isFirst = $loop->first;
                                                        $isLast = $loop->last;
                                                        $reorderDisabled = $hasActiveFilter;
                                                    @endphp
                                                    @if (! $reorderDisabled)
                                                        <div class="inline-flex items-center rounded-md border border-brand-ink/10 bg-white" role="group" aria-label="{{ __('Reorder rule') }}">
                                                            <button
                                                                type="button"
                                                                wire:click="moveFirewallRule('{{ $fr->id }}', 'up')"
                                                                wire:loading.attr="disabled"
                                                                @disabled($isFirst)
                                                                class="inline-flex h-6 w-6 items-center justify-center text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink disabled:cursor-not-allowed disabled:opacity-30"
                                                                title="{{ __('Move up — earlier rules match first') }}"
                                                                aria-label="{{ __('Move rule up') }}"
                                                            >
                                                                <x-heroicon-m-chevron-up class="h-3.5 w-3.5" />
                                                            </button>
                                                            <span class="block h-4 w-px bg-brand-ink/10" aria-hidden="true"></span>
                                                            <button
                                                                type="button"
                                                                wire:click="moveFirewallRule('{{ $fr->id }}', 'down')"
                                                                wire:loading.attr="disabled"
                                                                @disabled($isLast)
                                                                class="inline-flex h-6 w-6 items-center justify-center text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink disabled:cursor-not-allowed disabled:opacity-30"
                                                                title="{{ __('Move down — later rules match after this one') }}"
                                                                aria-label="{{ __('Move rule down') }}"
                                                            >
                                                                <x-heroicon-m-chevron-down class="h-3.5 w-3.5" />
                                                            </button>
                                                        </div>
                                                    @endif
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
                            || trim((string) ($form->site_id ?? '')) !== ''
                            || trim((string) ($form->iface ?? '')) !== '';
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
                                @php
                                    $isAppProfile = trim((string) ($form->app_profile ?? '')) !== '';
                                @endphp
                                {{-- UFW application-profile shortcut. When set, port + protocol are
                                     ignored at apply time (UFW reads /etc/ufw/applications.d). --}}
                                <div>
                                    <x-input-label for="fw-app-profile" :value="__('UFW application profile (optional)')" />
                                    <x-text-input id="fw-app-profile" type="text" class="mt-1 block w-full font-mono text-sm" wire:model.live="form.app_profile" placeholder="OpenSSH, Nginx Full, …" maxlength="64" autocomplete="off" />
                                    <p class="mt-1 text-xs text-brand-moss">
                                        {{ __('If set, Dply emits `ufw allow <profile>` and ignores port/protocol below. The profile must exist on the host (ufw app list to see what\'s installed).') }}
                                    </p>
                                    <x-input-error :messages="$errors->get('form.app_profile')" class="mt-1" />
                                </div>

                                {{-- Essentials: Port · Protocol · Action on one row, Source on the next.
                                     Hidden when an app profile is set since UFW pulls those from the
                                     profile definition. --}}
                                <div class="grid gap-3 sm:grid-cols-3 @if ($isAppProfile) opacity-50 @endif">
                                    @if (! in_array($form->protocol, ['icmp', 'ipv6-icmp'], true))
                                        <div>
                                            <x-input-label for="fw-port" :value="__('Port')" />
                                            <x-text-input id="fw-port" type="number" class="mt-1 block w-full" wire:model="form.port" min="1" max="65535" :disabled="$isAppProfile" />
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
                                            <option value="limit">{{ __('Limit (rate-limited allow, TCP only)') }}</option>
                                        </select>
                                        @if ($form->action === 'limit')
                                            <p class="mt-1 text-xs text-brand-moss">{{ __('UFW will allow connections but reject sources that hit > 6 connection attempts in 30 seconds. Standard SSH brute-force mitigation; TCP only.') }}</p>
                                        @endif
                                        <x-input-error :messages="$errors->get('form.action')" class="mt-1" />
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
                                        <div>
                                            <x-input-label for="fw-iface" :value="__('Interface (optional)')" />
                                            <x-text-input id="fw-iface" type="text" class="mt-1 block w-full font-mono text-sm" wire:model="form.iface" placeholder="eth0, wg0, …" maxlength="32" autocomplete="off" />
                                            <p class="mt-1 text-xs text-brand-moss">{{ __('Limits this rule to traffic on a specific network interface (`ufw allow in on <iface> …`).') }}</p>
                                            <x-input-error :messages="$errors->get('form.iface')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="fw-iface-dir" :value="__('Interface direction')" />
                                            <select id="fw-iface-dir" wire:model="form.iface_direction" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm">
                                                <option value="">{{ __('— None —') }}</option>
                                                <option value="in">{{ __('in (inbound on this iface)') }}</option>
                                                <option value="out">{{ __('out (outbound on this iface)') }}</option>
                                            </select>
                                            <x-input-error :messages="$errors->get('form.iface_direction')" class="mt-1" />
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

                    {{-- Import-from-host preview modal. Populated by previewImportHostRules from
                         `ufw show added`; rows already in the panel are pre-unticked, parser-skipped
                         lines render read-only so the operator can see them but not import them.
                         Open/close is driven by the standard open-modal/close-modal dispatch pattern. --}}
                    <x-modal name="import-host-firewall-rules-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
                            <div class="border-b border-brand-ink/10 px-6 py-5">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Import from host') }}</p>
                                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Pull UFW rules into the panel') }}</h2>
                                <p class="mt-2 text-sm leading-6 text-brand-moss">
                                    {{ __('Read from `ufw show added`. Importing only adds rows to the panel — nothing is changed on the host. Click "Apply rules" afterwards if you want the panel to be the source of truth for UFW.') }}
                                </p>
                            </div>

                            <div class="px-6 py-4">
                                @if (empty($import_host_rules))
                                    <p class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-4 py-6 text-center text-sm text-brand-moss">
                                        {{ __('No user-added UFW rules were found on the host.') }}
                                    </p>
                                @else
                                    <div class="max-h-96 overflow-y-auto rounded-xl border border-brand-ink/10">
                                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                                            <thead class="sticky top-0 bg-brand-sand/30 text-left text-[11px] uppercase tracking-wide text-brand-mist">
                                                <tr>
                                                    <th class="w-10 px-3 py-2"><span class="sr-only">{{ __('Select') }}</span></th>
                                                    <th class="px-3 py-2 font-semibold">{{ __('Action') }}</th>
                                                    <th class="px-3 py-2 font-semibold">{{ __('Port') }}</th>
                                                    <th class="px-3 py-2 font-semibold">{{ __('Proto') }}</th>
                                                    <th class="px-3 py-2 font-semibold">{{ __('Source') }}</th>
                                                    <th class="px-3 py-2 font-semibold">{{ __('Status') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-brand-ink/8 bg-white">
                                                @foreach ($import_host_rules as $row)
                                                    <tr @class([
                                                        'text-brand-ink',
                                                        'opacity-50' => ! $row['importable'] || $row['already_in_panel'],
                                                    ])>
                                                        <td class="px-3 py-2 align-top">
                                                            @if ($row['importable'] && ! $row['already_in_panel'])
                                                                <input
                                                                    type="checkbox"
                                                                    wire:model.live="import_host_selected"
                                                                    value="{{ $row['index'] }}"
                                                                    class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-forest"
                                                                />
                                                            @else
                                                                <span class="inline-block h-4 w-4"></span>
                                                            @endif
                                                        </td>
                                                        <td class="px-3 py-2 capitalize">{{ $row['action'] ?? '—' }}</td>
                                                        <td class="px-3 py-2 font-mono text-xs">{{ $row['port'] ?? '—' }}</td>
                                                        <td class="px-3 py-2 font-mono text-xs">{{ $row['protocol'] ?? '—' }}</td>
                                                        <td class="max-w-[12rem] truncate px-3 py-2 font-mono text-xs" title="{{ $row['source'] ?? '' }}">{{ $row['source'] ?? '—' }}</td>
                                                        <td class="px-3 py-2 text-xs">
                                                            @if (! $row['importable'])
                                                                <span class="rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] uppercase tracking-wide text-brand-moss" title="{{ $row['raw'] }}">{{ __('Skipped (unparsed)') }}</span>
                                                            @elseif ($row['already_in_panel'])
                                                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">{{ __('Already in panel') }}</span>
                                                            @else
                                                                <span class="rounded-full bg-brand-forest/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-forest/20">{{ __('New') }}</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 px-6 py-4">
                                <p class="text-xs text-brand-moss">
                                    {{ trans_choice('{0} 0 selected|{1} 1 rule selected|[2,*] :count rules selected', count($import_host_selected), ['count' => count($import_host_selected)]) }}
                                </p>
                                <div class="flex items-center gap-2">
                                    <x-secondary-button type="button" wire:click="closeImportHostRulesModal" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                                    <x-primary-button type="button" wire:click="confirmImportHostRules" wire:loading.attr="disabled" wire:target="confirmImportHostRules">
                                        <span wire:loading.remove wire:target="confirmImportHostRules">{{ __('Import selected') }}</span>
                                        <span wire:loading wire:target="confirmImportHostRules">{{ __('Importing…') }}</span>
                                    </x-primary-button>
                                </div>
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
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Adds rules to this server’s list (does not replace existing rows). Already-applied bundles are dimmed — re-applying is a no-op.') }}</p>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($bundledTemplates as $bKey => $b)
                                @php
                                    $isApplied = (bool) ($bundledAppliedMap[$bKey] ?? false);
                                    $ruleCount = count($b['rules'] ?? []);
                                @endphp
                                <button
                                    type="button"
                                    wire:click="applyBundledFirewallTemplate('{{ $bKey }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="applyBundledFirewallTemplate('{{ $bKey }}')"
                                    @class([
                                        'group flex flex-col items-start gap-1.5 rounded-xl border px-3.5 py-3 text-left transition-colors',
                                        'border-emerald-200 bg-emerald-50/40 hover:border-emerald-300 hover:bg-emerald-50/70' => $isApplied,
                                        'border-brand-ink/10 bg-white hover:border-brand-forest/30 hover:bg-brand-sand/30' => ! $isApplied,
                                    ])
                                >
                                    <div class="flex w-full items-center justify-between gap-2">
                                        <span class="text-sm font-semibold text-brand-ink">{{ __($b['label'] ?? $bKey) }}</span>
                                        @if ($isApplied)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                                <x-heroicon-m-check class="h-3 w-3" />
                                                {{ __('Applied') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                                {{ trans_choice('{1} :n rule|[2,*] :n rules', $ruleCount, ['n' => $ruleCount]) }}
                                            </span>
                                        @endif
                                    </div>
                                    @if (! empty($b['description']))
                                        <p class="text-[11px] leading-relaxed text-brand-moss">{{ __($b['description']) }}</p>
                                    @endif
                                    @if ($isApplied)
                                        <p class="text-[10px] uppercase tracking-wide text-emerald-700">{{ __('All rules already in panel · click to re-add (no-op)') }}</p>
                                    @endif
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
                id="firewall-panel-activity"
                labelled-by="firewall-tab-activity"
                :hidden="$firewall_workspace_tab !== 'activity'"
            >
                <div class="{{ $card }} p-6 sm:p-8">
                    @php
                        $activityCount = count($activityItems);
                        $latestActivity = $activityItems[0]['at'] ?? null;
                        $linesOf = static function (?string $message): array {
                            if (! is_string($message) || trim($message) === '') {
                                return [];
                            }
                            $lines = array_values(array_filter(
                                array_map('trim', preg_split("/\r?\n/", $message) ?: []),
                                static fn (string $l): bool => $l !== '',
                            ));

                            return array_slice($lines, 0, 25);
                        };
                    @endphp
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                            <x-heroicon-o-clock class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Activity') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Apply runs, rule edits, template applications, and imports — chronologically. Apply rows are expandable for the full UFW transcript.') }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                                <span class="inline-flex items-center gap-1">
                                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                    {{ trans_choice('{0} no events recorded|{1} :count event recorded|[2,*] :count events recorded', $activityCount, ['count' => $activityCount]) }}
                                </span>
                                @if ($latestActivity)
                                    <span class="text-brand-mist/60">·</span>
                                    <span>{{ __('latest :time', ['time' => $latestActivity->diffForHumans()]) }}</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if ($activityCount > 0)
                        <div class="mt-6 space-y-2">
                            @foreach ($activityItems as $item)
                                @if ($item['kind'] === 'apply')
                                    @php
                                        $log = $item['log'];
                                        $isSuccess = (bool) $log->success;
                                        $logLines = $linesOf($log->message);
                                    @endphp
                                    <details class="group overflow-hidden rounded-xl border border-brand-ink/10 bg-white" wire:key="activity-{{ $item['key'] }}">
                                        <summary class="flex cursor-pointer list-none items-start gap-3 px-4 py-3 sm:px-5">
                                            <span @class([
                                                'mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-1',
                                                'bg-emerald-50 text-emerald-700 ring-emerald-200' => $isSuccess,
                                                'bg-rose-50 text-rose-700 ring-rose-200' => ! $isSuccess,
                                            ])>
                                                @if ($isSuccess)
                                                    <x-heroicon-m-check class="h-4 w-4" />
                                                @else
                                                    <x-heroicon-m-exclamation-triangle class="h-4 w-4" />
                                                @endif
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span @class([
                                                        'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1',
                                                        'bg-emerald-50 text-emerald-800 ring-emerald-200' => $isSuccess,
                                                        'bg-rose-50 text-rose-800 ring-rose-200' => ! $isSuccess,
                                                    ])>
                                                        {{ $isSuccess ? __('Applied') : __('Failed') }}
                                                    </span>
                                                    <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[10px] text-brand-moss" title="{{ $log->rules_hash ?? '—' }}">
                                                        <x-heroicon-m-hashtag class="h-3 w-3" />
                                                        {{ $log->rules_hash ? substr($log->rules_hash, 0, 12) : '—' }}
                                                    </span>
                                                    <span class="inline-flex items-center gap-1 text-[11px] text-brand-mist">
                                                        {{ trans_choice('{0} 0 rules|{1} :count rule|[2,*] :count rules', (int) $log->rule_count, ['count' => (int) $log->rule_count]) }}
                                                    </span>
                                                    @if ($log->source)
                                                        <span class="inline-flex items-center rounded-md border border-brand-ink/10 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wide text-brand-moss">{{ $log->source }}</span>
                                                    @endif
                                                    <span class="ml-auto text-[11px] text-brand-mist" title="{{ $log->created_at?->toIso8601String() }}">{{ $log->created_at?->diffForHumans() }}</span>
                                                </div>
                                                @if ($log->user)
                                                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('by :name', ['name' => $log->user->name ?? $log->user->email]) }}</p>
                                                @endif
                                                @if (count($logLines) > 0)
                                                    <p class="mt-1 truncate font-mono text-[11px] text-brand-moss">{{ $logLines[count($logLines) - 1] }}</p>
                                                @endif
                                            </div>
                                            <span class="ml-2 mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-brand-mist transition-transform group-open:rotate-180">
                                                <x-heroicon-o-chevron-down class="h-4 w-4" />
                                            </span>
                                        </summary>
                                        @if (count($logLines) > 0)
                                            <div class="border-t border-brand-ink/8 bg-brand-sand/15 px-4 py-3 sm:px-5">
                                                <pre class="max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-[11px] leading-relaxed text-emerald-100">@foreach ($logLines as $line){{ $line }}
@endforeach</pre>
                                            </div>
                                        @endif
                                    </details>
                                @else
                                    <div wire:key="activity-{{ $item['key'] }}">
                                        @include('livewire.servers.partials.activity-audit-row', ['event' => $item['event'], 'server' => $server])
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        @if (! $activity_exhausted)
                            <div class="mt-4 flex justify-center">
                                <button
                                    type="button"
                                    wire:click="loadMoreFirewallActivity"
                                    wire:loading.attr="disabled"
                                    wire:target="loadMoreFirewallActivity"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <x-heroicon-o-arrow-down class="h-3.5 w-3.5" wire:loading.remove wire:target="loadMoreFirewallActivity" />
                                    <span wire:loading wire:target="loadMoreFirewallActivity" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                        <x-spinner variant="forest" size="sm" />
                                    </span>
                                    <span wire:loading.remove wire:target="loadMoreFirewallActivity">{{ __('Load older activity') }}</span>
                                    <span wire:loading wire:target="loadMoreFirewallActivity">{{ __('Loading…') }}</span>
                                </button>
                            </div>
                        @elseif ($activity_visible >= \App\Livewire\Servers\WorkspaceFirewall::ACTIVITY_MAX_VISIBLE)
                            <p class="mt-4 text-center text-[11px] italic text-brand-mist">
                                {{ __('Showing the most recent :n events. Older history lives in audit logs.', ['n' => \App\Livewire\Servers\WorkspaceFirewall::ACTIVITY_MAX_VISIBLE]) }}
                            </p>
                        @endif
                    @else
                        <div class="mt-6 flex flex-col items-center gap-2 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-10 text-center">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-white text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-clock class="h-5 w-5" />
                            </span>
                            <p class="text-sm font-medium text-brand-ink">{{ __('No firewall activity yet.') }}</p>
                            <p class="text-xs text-brand-moss">{{ __('Adding, editing, importing, or applying rules will all show up here.') }}</p>
                        </div>
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
