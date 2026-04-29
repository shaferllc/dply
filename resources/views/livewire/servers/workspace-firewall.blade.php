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

    @if ($opsReady)
        <div class="space-y-6">
            <x-server-workspace-tablist :aria-label="__('Firewall workspace sections')">
                <x-server-workspace-tab id="firewall-tab-rules" :active="$firewall_workspace_tab === 'rules'" wire:click="$set('firewall_workspace_tab', 'rules')">
                    {{ __('Rules') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="firewall-tab-templates" :active="$firewall_workspace_tab === 'templates'" wire:click="$set('firewall_workspace_tab', 'templates')">
                    {{ __('Templates') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="firewall-tab-history" :active="$firewall_workspace_tab === 'history'" wire:click="$set('firewall_workspace_tab', 'history')">
                    {{ __('History') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="firewall-tab-audit" :active="$firewall_workspace_tab === 'audit'" wire:click="$set('firewall_workspace_tab', 'audit')">
                    {{ __('Audit') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            <x-server-workspace-tab-panel
                id="firewall-panel-rules"
                labelled-by="firewall-tab-rules"
                :hidden="$firewall_workspace_tab !== 'rules'"
            >
        <div class="{{ $card }} p-6 sm:p-8">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Firewall rules') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Rules are stored in Dply and applied to the server with UFW. Keep this page focused on the ports and sources you actually want reachable.') }}
                            </p>
                            @if ($sshNotCovered ?? false)
                                <p class="mt-3 rounded-lg border border-amber-300/80 bg-amber-50/90 px-3 py-2 text-sm text-amber-950">
                                    {{ __('No enabled Dply rule allows TCP :port from “any”. Add an allow for your SSH port (or a trusted CIDR) before applying deny-heavy changes.', ['port' => $server->ssh_port ?: 22]) }}
                                </p>
                                <label class="mt-3 flex items-start gap-2 text-sm text-brand-ink">
                                    <input
                                        type="checkbox"
                                        wire:model.live="firewall_ack_ssh_risk"
                                        class="mt-0.5 rounded border-amber-400 text-brand-forest focus:ring-brand-forest"
                                    />
                                    <span>{{ __('I understand SSH may be unreachable if these rules block access—I still want to apply.') }}</span>
                                </label>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                wire:click="applyFirewall"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="applyFirewall">{{ __('Apply firewall rules') }}</span>
                                <span wire:loading wire:target="applyFirewall" class="inline-flex items-center gap-2">
                                    <x-spinner variant="forest" />
                                    {{ __('Applying…') }}
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="refreshUfwStatus"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50 disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="refreshUfwStatus">{{ __('Refresh UFW status') }}</span>
                                <span wire:loading wire:target="refreshUfwStatus" class="inline-flex items-center gap-2">
                                    <x-spinner variant="forest" />
                                    {{ __('Reading…') }}
                                </span>
                            </button>
                        </div>
                    </div>

            @if ($server->firewallRules->isNotEmpty())
                        <div class="mt-6 flex flex-wrap items-center gap-2">
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
                        <div class="mt-3 overflow-x-auto rounded-xl border border-brand-ink/10">
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
                        <p class="mt-6 text-sm text-brand-moss">{{ __('No rules yet. Add one below or start from a template.') }}</p>
                    @endif

                    <div class="mt-8 border-t border-brand-ink/10 pt-6">
                        <h3 class="text-sm font-semibold text-brand-ink">
                            @if ($editing_rule_id)
                                {{ __('Edit rule') }}
                            @else
                                {{ __('Add rule') }}
                            @endif
                        </h3>
                        @if (! $editing_rule_id)
                            <p class="mt-3 text-xs font-medium uppercase tracking-wide text-brand-moss">
                                {{ __('Quick presets') }}
                            </p>
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
                        <form wire:submit="saveFirewallRule" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div class="sm:col-span-2 lg:col-span-3">
                                <x-input-label for="fw-name" :value="__('Label (optional)')" />
                                <x-text-input
                                    id="fw-name"
                                    type="text"
                                    class="mt-1 block w-full"
                                    wire:model="form.name"
                                    placeholder="{{ __('e.g. Monitoring, Office VPN') }}"
                                />
                                <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="fw-profile" :value="__('Profile (optional)')" />
                                <x-text-input
                                    id="fw-profile"
                                    type="text"
                                    class="mt-1 block w-full"
                                    wire:model="form.profile"
                                    placeholder="{{ __('web, db, admin…') }}"
                                />
                                <x-input-error :messages="$errors->get('form.profile')" class="mt-2" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label for="fw-tags" :value="__('Tags (comma-separated)')" />
                                <x-text-input
                                    id="fw-tags"
                                    type="text"
                                    class="mt-1 block w-full"
                                    wire:model="form.tags"
                                    placeholder="{{ __('monitoring, prod, …') }}"
                                />
                                <x-input-error :messages="$errors->get('form.tags')" class="mt-2" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label for="fw-runbook" :value="__('Runbook URL (optional)')" />
                                <x-text-input
                                    id="fw-runbook"
                                    type="url"
                                    class="mt-1 block w-full"
                                    wire:model="form.runbook_url"
                                    placeholder="https://…"
                                />
                                <x-input-error :messages="$errors->get('form.runbook_url')" class="mt-2" />
                            </div>
                            <div class="sm:col-span-2 lg:col-span-3">
                                <x-input-label for="fw-site" :value="__('Related site (optional)')" />
                                <select
                                    id="fw-site"
                                    wire:model="form.site_id"
                                    class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm"
                                >
                                    <option value="">{{ __('— None —') }}</option>
                                    @foreach ($server->sites as $site)
                                        <option value="{{ $site->id }}">{{ $site->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('form.site_id')" class="mt-2" />
                            </div>
                            @if (! in_array($form->protocol, ['icmp', 'ipv6-icmp'], true))
                                <div>
                                    <x-input-label for="fw-port" :value="__('Port')" />
                                    <x-text-input
                                        id="fw-port"
                                        type="number"
                                        class="mt-1 block w-full"
                                        wire:model="form.port"
                                        min="1"
                                        max="65535"
                                    />
                                    <x-input-error :messages="$errors->get('form.port')" class="mt-2" />
                                </div>
                            @endif
                            <div>
                                <x-input-label for="fw-proto" :value="__('Protocol')" />
                                <select
                                    id="fw-proto"
                                    wire:model.live="form.protocol"
                                    class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm"
                                >
                                    <option value="tcp">TCP</option>
                                    <option value="udp">UDP</option>
                                    <option value="icmp">ICMP (IPv4)</option>
                                    <option value="ipv6-icmp">{{ __('ICMPv6 (NDP, etc.)') }}</option>
                                </select>
                                <x-input-error :messages="$errors->get('form.protocol')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="fw-action" :value="__('Action')" />
                                <select
                                    id="fw-action"
                                    wire:model="form.action"
                                    class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm"
                                >
                                    <option value="allow">{{ __('Allow') }}</option>
                                    <option value="deny">{{ __('Deny') }}</option>
                                </select>
                                <x-input-error :messages="$errors->get('form.action')" class="mt-2" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label for="fw-source" :value="__('Source')" />
                                <x-text-input
                                    id="fw-source"
                                    type="text"
                                    class="mt-1 block w-full font-mono text-sm"
                                    wire:model="form.source"
                                    placeholder="any"
                                    autocomplete="off"
                                />
                                <p class="mt-1 text-xs text-brand-moss">
                                    {{ __('Use :keyword for any host, or an IPv4/IPv6 address or CIDR.', ['keyword' => 'any']) }}
                                </p>
                                <x-input-error :messages="$errors->get('form.source')" class="mt-2" />
                            </div>
                            <div class="flex items-center gap-2 sm:col-span-2">
                                <input
                                    id="fw-enabled"
                                    type="checkbox"
                                    wire:model="form.enabled"
                                    class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-forest"
                                />
                                <x-input-label for="fw-enabled" :value="__('Enabled (included when applying)')" class="!mb-0" />
                            </div>
                            <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-3">
                                @if ($editing_rule_id)
                                    <x-primary-button type="submit" class="!py-2" wire:loading.attr="disabled">
                                        <span wire:loading.remove wire:target="saveFirewallRule">{{ __('Save changes') }}</span>
                                        <span wire:loading wire:target="saveFirewallRule">{{ __('Saving…') }}</span>
                                    </x-primary-button>
                                    <button
                                        type="button"
                                        wire:click="cancelEditRule"
                                        class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm"
                                    >
                                        {{ __('Cancel') }}
                                    </button>
                                @else
                                    <x-primary-button type="submit" class="!py-2" wire:loading.attr="disabled">
                                        <span wire:loading.remove wire:target="saveFirewallRule">{{ __('Add rule') }}</span>
                                        <span wire:loading wire:target="saveFirewallRule">{{ __('Saving…') }}</span>
                                    </x-primary-button>
                                @endif
                            </div>
                        </form>
                    </div>

                    @if ($ufw_status_text !== null && $ufw_status_text !== '')
                        <div class="mt-8 overflow-hidden rounded-2xl border border-brand-ink/10">
                            <div class="border-b border-brand-ink/10 px-5 py-3 text-sm font-medium text-brand-ink">
                                {{ __('UFW status (verbose)') }}
                            </div>
                            <pre class="max-h-96 overflow-x-auto bg-zinc-950 p-4 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $ufw_status_text }}</pre>
                        </div>
                    @endif
                </div>
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
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Recent audit') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Track recent firewall changes, template applications, and apply activity for this server.') }}</p>

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
