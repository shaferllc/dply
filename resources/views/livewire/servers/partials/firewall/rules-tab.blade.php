        <details class="{{ $card }} overflow-hidden" {{ $defaultPolicies !== [] ? 'open' : '' }}>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-6 py-4 sm:px-8">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                        <x-heroicon-o-adjustments-horizontal class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Default chain policies') }}</h2>
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
                                <h2 class="text-base font-semibold text-brand-ink">{{ __('Firewall rules') }}</h2>
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
                                        <th class="w-10 px-3 py-2.5" scope="col">
                                            <span class="sr-only">{{ __('Select') }}</span>
                                        </th>
                                        <th class="px-3 py-2.5">{{ __('Name') }}</th>
                                        <th class="px-3 py-2.5">{{ __('Profile') }}</th>
                                        <th class="px-3 py-2.5">{{ __('Action') }}</th>
                                        <th class="px-3 py-2.5">{{ __('Port') }}</th>
                                        <th class="px-3 py-2.5">{{ __('Proto') }}</th>
                                        <th class="px-3 py-2.5">{{ __('Source') }}</th>
                                        <th class="px-3 py-2.5">{{ __('On') }}</th>
                                        <th class="px-3 py-2.5 text-right">
                                            <span class="sr-only">{{ __('Actions') }}</span>
                                        </th>
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
                                            <td class="px-3 py-2.5 align-top">
                                                <input
                                                    type="checkbox"
                                                    wire:model.live="firewall_bulk_ids"
                                                    value="{{ $fr->id }}"
                                                    class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-forest"
                                                />
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2.5 font-medium">
                                                {{ $fr->name ?: '—' }}
                                            </td>
                                            <td class="px-3 py-2.5 text-xs text-brand-moss">
                                                {{ $fr->profile ?: '—' }}
                                                @if (is_array($fr->tags) && $fr->tags !== [])
                                                    <span class="mt-1 block font-mono text-[0.65rem] text-brand-ink/80">{{ implode(', ', $fr->tags) }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2.5 capitalize">{{ $fr->action }}</td>
                                            <td class="px-3 py-2.5">
                                                @if (! empty($fr->app_profile))
                                                    <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wide text-brand-moss" title="{{ __('UFW application profile') }}">
                                                        app
                                                    </span>
                                                @else
                                                    {{ $fr->port ?? '—' }}
                                                @endif
                                            </td>
                                            <td class="px-3 py-2.5">
                                                @if (! empty($fr->app_profile))
                                                    <span class="font-mono text-xs">{{ $fr->app_profile }}</span>
                                                @else
                                                    {{ $fr->protocol }}
                                                @endif
                                            </td>
                                            <td class="max-w-[12rem] truncate px-3 py-2.5 font-mono text-xs" title="{{ $fr->source }}{{ ! empty($fr->iface) ? ' · '.$fr->iface_direction.' on '.$fr->iface : '' }}">
                                                {{ $fr->source }}
                                                @if (! empty($fr->iface))
                                                    <span class="mt-0.5 block text-[10px] uppercase tracking-wide text-brand-mist">
                                                        {{ $fr->iface_direction ?: 'in' }} on <span class="text-brand-ink/80">{{ $fr->iface }}</span>
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2.5">
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
                                                    </span>
                                                </button>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2.5 text-right">
                                                @php
                                                    // Reorder hidden when filtered: $loop->first/last reference the
                                                    // filtered slice, not full sort_order. Re-enabled when filter cleared.
                                                    $isFirst = $loop->first;
                                                    $isLast = $loop->last;
                                                    $reorderDisabled = $hasActiveFilter;
                                                @endphp
                                                <div class="inline-flex items-center justify-end gap-0.5">
                                                    @if (! $reorderDisabled)
                                                        <button
                                                            type="button"
                                                            wire:click="moveFirewallRule('{{ $fr->id }}', 'up')"
                                                            wire:loading.attr="disabled"
                                                            @disabled($isFirst)
                                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink disabled:cursor-not-allowed disabled:opacity-30"
                                                            title="{{ __('Move up') }}"
                                                            aria-label="{{ __('Move rule up') }}"
                                                        >
                                                            <x-heroicon-m-chevron-up class="h-3.5 w-3.5" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            wire:click="moveFirewallRule('{{ $fr->id }}', 'down')"
                                                            wire:loading.attr="disabled"
                                                            @disabled($isLast)
                                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink disabled:cursor-not-allowed disabled:opacity-30"
                                                            title="{{ __('Move down') }}"
                                                            aria-label="{{ __('Move rule down') }}"
                                                        >
                                                            <x-heroicon-m-chevron-down class="h-3.5 w-3.5" />
                                                        </button>
                                                    @endif
                                                    <button
                                                        type="button"
                                                        wire:click="startEditRule('{{ $fr->id }}')"
                                                        wire:loading.attr="disabled"
                                                        x-on:click="$dispatch('open-modal', 'add-firewall-rule-modal')"
                                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-brand-forest hover:bg-brand-sand/40"
                                                        title="{{ __('Edit') }}"
                                                        aria-label="{{ __('Edit rule') }}"
                                                    >
                                                        <span wire:loading.remove wire:target="startEditRule('{{ $fr->id }}')"><x-heroicon-m-pencil-square class="h-3.5 w-3.5" /></span>
                                                        <span wire:loading wire:target="startEditRule('{{ $fr->id }}')"><x-spinner variant="forest" size="sm" /></span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('deleteFirewallRule', ['{{ $fr->id }}'], @js(__('Delete firewall rule')), @js(__('Remove this rule from the panel and try to delete the matching UFW entry?')), @js(__('Delete rule')), true)"
                                                        wire:loading.attr="disabled"
                                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-red-600 hover:bg-red-50"
                                                        title="{{ __('Remove') }}"
                                                        aria-label="{{ __('Remove rule') }}"
                                                    >
                                                        <span wire:loading.remove wire:target="deleteFirewallRule('{{ $fr->id }}')"><x-heroicon-m-trash class="h-3.5 w-3.5" /></span>
                                                        <span wire:loading wire:target="deleteFirewallRule('{{ $fr->id }}')"><x-spinner variant="forest" size="sm" /></span>
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
