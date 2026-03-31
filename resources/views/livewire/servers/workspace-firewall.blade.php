@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="firewall"
    :title="__('Firewall')"
    :description="__('UFW on the host: TCP/UDP ports, ICMP/ICMPv6, templates, drift check, import/export, and API access with network.* token abilities.')"
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
                <x-server-workspace-tab id="firewall-tab-advanced" :active="$firewall_workspace_tab === 'advanced'" wire:click="$set('firewall_workspace_tab', 'advanced')">
                    {{ __('Advanced') }}
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
                                {{ __('Rules are stored in Dply, then applied on the server with UFW. Related/established traffic is handled by the kernel connection tracking; add explicit allows for new inbound services.') }}
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
                                class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                            >
                                {{ __('Select all') }}
                            </button>
                            <button
                                type="button"
                                wire:click="clearFirewallBulkSelection"
                                class="rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-medium text-brand-moss hover:bg-brand-sand/30"
                            >
                                {{ __('Clear') }}
                            </button>
                            <button
                                type="button"
                                wire:click="bulkEnableFirewallRules"
                                class="rounded-lg border border-emerald-200 bg-emerald-50/80 px-3 py-1.5 text-xs font-medium text-emerald-900 hover:bg-emerald-100/80"
                            >
                                {{ __('Enable selected') }}
                            </button>
                            <button
                                type="button"
                                wire:click="bulkDisableFirewallRules"
                                class="rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                {{ __('Disable selected') }}
                            </button>
                            <button
                                type="button"
                                wire:click="bulkDeleteFirewallRules"
                                wire:confirm="{{ __('Remove selected rules from the panel and try to delete matching UFW entries?') }}"
                                class="rounded-lg border border-red-200 bg-red-50/80 px-3 py-1.5 text-xs font-medium text-red-800 hover:bg-red-100/80"
                            >
                                {{ __('Delete selected') }}
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
                                                    class="text-xs font-medium {{ $fr->enabled ? 'text-emerald-700 hover:underline' : 'text-brand-moss hover:underline' }}"
                                                >
                                                    {{ $fr->enabled ? __('Yes') : __('No') }}
                                                </button>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                                <div class="inline-flex flex-wrap items-center justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        wire:click="moveFirewallRuleUp('{{ $fr->id }}')"
                                                        class="text-xs text-brand-moss hover:text-brand-ink"
                                                        title="{{ __('Move up') }}"
                                                    >
                                                        ↑
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="moveFirewallRuleDown('{{ $fr->id }}')"
                                                        class="text-xs text-brand-moss hover:text-brand-ink"
                                                        title="{{ __('Move down') }}"
                                                    >
                                                        ↓
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="startEditRule('{{ $fr->id }}')"
                                                        class="text-xs font-medium text-brand-forest hover:underline"
                                                    >
                                                        {{ __('Edit') }}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="deleteFirewallRule('{{ $fr->id }}')"
                                                        wire:confirm="{{ __('Remove this rule from the panel and try to delete the matching UFW entry?') }}"
                                                        class="text-xs font-medium text-red-600 hover:underline"
                                                    >
                                                        {{ __('Remove') }}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="mt-6 text-sm text-brand-moss">{{ __('No rules yet. Add one below, use a template, or rely on provisioning defaults.') }}</p>
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
                </div>
            </x-server-workspace-tab-panel>

            <x-server-workspace-tab-panel
                id="firewall-panel-templates"
                labelled-by="firewall-tab-templates"
                :hidden="$firewall_workspace_tab !== 'templates'"
            >
                <div class="{{ $card }} p-6 sm:p-8 space-y-8">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Bundled bundles') }}</h2>
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

                    @if (! empty($policyPacks))
                        <div class="border-t border-brand-ink/10 pt-8">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Policy packs') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss">{{ __('Apply multiple bundles in order (adds to the rule list; does not replace).') }}</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach ($policyPacks as $packKey => $pack)
                                    <button
                                        type="button"
                                        wire:click="applyPolicyPack('{{ $packKey }}')"
                                        class="rounded-lg border border-brand-forest/30 bg-emerald-50/60 px-3 py-2 text-sm font-medium text-brand-ink hover:bg-emerald-100/60"
                                        title="{{ $pack['description'] ?? '' }}"
                                    >
                                        {{ $pack['label'] ?? $packKey }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

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
                id="firewall-panel-advanced"
                labelled-by="firewall-tab-advanced"
                :hidden="$firewall_workspace_tab !== 'advanced'"
                panel-class="space-y-6"
            >
                    <div class="{{ $card }} p-6 sm:p-8 space-y-6">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Dry-run / preview') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss">{{ __('Exact shell lines that would run for enabled rules (sudo when SSH user is not root).') }}</p>
                            <button
                                type="button"
                                wire:click="previewFirewallCommands"
                                wire:loading.attr="disabled"
                                wire:target="previewFirewallCommands"
                                class="mt-3 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="previewFirewallCommands">{{ __('Generate preview') }}</span>
                                <span wire:loading wire:target="previewFirewallCommands">{{ __('Generating…') }}</span>
                            </button>
                            @if (is_array($firewall_preview_lines))
                                @if (count($firewall_preview_lines) > 0)
                                    <pre class="mt-4 max-h-64 overflow-x-auto rounded-xl bg-zinc-950 p-4 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ implode("\n", $firewall_preview_lines) }}</pre>
                                @else
                                    <p class="mt-4 rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">
                                        {{ __('No enabled rules — there is nothing to apply. Enable rules on the Rules tab or add new ones.') }}
                                    </p>
                                @endif
                            @endif
                        </div>

                        <div class="border-t border-brand-ink/10 pt-6">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Drift detection') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss">{{ __('Compares Dply rules to `ufw status verbose` (best-effort; app profiles may show as extras).') }}</p>
                            <button
                                type="button"
                                wire:click="analyzeFirewallDrift"
                                class="mt-3 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium"
                            >
                                {{ __('Analyze drift') }}
                            </button>
                            @if (is_array($firewall_drift))
                                <div class="mt-4 space-y-2 text-sm">
                                    <p class="font-medium {{ ($firewall_drift['in_sync'] ?? false) ? 'text-emerald-800' : 'text-amber-900' }}">
                                        {{ ($firewall_drift['in_sync'] ?? false) ? __('No drift detected for matched signatures.') : __('Differences detected.') }}
                                    </p>
                                    @if (! empty($firewall_drift['missing_on_host'] ?? []))
                                        <p class="text-brand-moss">{{ __('Missing on host') }}: {{ implode(', ', $firewall_drift['missing_on_host']) }}</p>
                                    @endif
                                    @if (! empty($firewall_drift['extra_on_host'] ?? []))
                                        <p class="text-brand-moss">{{ __('Extra on host') }}: {{ implode(', ', $firewall_drift['extra_on_host']) }}</p>
                                    @endif
                                    <p class="text-xs text-brand-moss">{{ $firewall_drift['notes'] ?? '' }}</p>
                                </div>
                            @endif
                        </div>

                        <div class="border-t border-brand-ink/10 pt-6">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('iptables (danger zone)') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss">
                                {{ __('Read-only snapshot of filter table counters. Enable SERVER_FIREWALL_IPTABLES_COUNTERS and prefer SSH/console access if unsure.') }}
                                @if (config('server_firewall.docs.fail2ban'))
                                    <a
                                        href="{{ config('server_firewall.docs.fail2ban') }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-medium text-brand-forest underline"
                                    >fail2ban</a>
                                @endif
                            </p>
                            <button
                                type="button"
                                wire:click="refreshFirewallIptablesSnapshot"
                                wire:loading.attr="disabled"
                                wire:target="refreshFirewallIptablesSnapshot"
                                class="mt-3 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="refreshFirewallIptablesSnapshot">{{ __('Fetch iptables snapshot') }}</span>
                                <span wire:loading wire:target="refreshFirewallIptablesSnapshot">{{ __('Reading…') }}</span>
                            </button>
                            @if (is_string($firewall_iptables_text) && $firewall_iptables_text !== '')
                                <pre class="mt-4 max-h-64 overflow-x-auto rounded-xl bg-zinc-950 p-4 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $firewall_iptables_text }}</pre>
                            @endif
                        </div>

                        @if ($provider_sync_blurb)
                            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-4 py-3 text-sm text-brand-ink">
                                {{ $provider_sync_blurb }}
                            </div>
                        @endif
                    </div>

                    <div class="{{ $card }} p-6 sm:p-8 space-y-6">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Import / export') }}</h2>
                        <p class="text-sm text-brand-moss">{{ __('JSON for IaC backups. Import replaces all rules unless you merge manually in the file first.') }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="exportFirewallJson" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm">
                                {{ __('Export to field') }}
                            </button>
                        </div>
                        <form wire:submit="importFirewallJson" class="space-y-3">
                            <textarea
                                wire:model="firewall_import_text"
                                rows="8"
                                class="w-full rounded-lg border-brand-ink/15 font-mono text-xs"
                                placeholder="{{ __('Paste exported JSON…') }}"
                            ></textarea>
                            <x-input-error :messages="$errors->get('firewall_import_text')" />
                            <button type="submit" class="rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white">
                                {{ __('Import (replace all)') }}
                            </button>
                        </form>
                    </div>

                    <div class="{{ $card }} p-6 sm:p-8 space-y-4">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Terraform (documentation)') }}</h2>
                        <p class="text-sm text-brand-moss">{{ __('Cloud-style HCL for hand-off; UFW on the host remains authoritative.') }}</p>
                        <button
                            type="button"
                            wire:click="exportFirewallTerraform"
                            class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm"
                        >
                            {{ __('Generate snippet') }}
                        </button>
                        @if ($firewall_terraform_hcl !== '')
                            <textarea
                                readonly
                                rows="12"
                                class="w-full rounded-lg border-brand-ink/15 font-mono text-xs"
                            >{{ $firewall_terraform_hcl }}</textarea>
                        @endif
                    </div>

                    <div class="{{ $card }} p-6 sm:p-8 space-y-6">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Snapshots (rollback)') }}</h2>
                        <form wire:submit="createFirewallSnapshot" class="flex flex-wrap items-end gap-2">
                            <div class="grow sm:max-w-xs">
                                <x-input-label for="snap-label" :value="__('Label (optional)')" />
                                <x-text-input id="snap-label" type="text" class="mt-1 block w-full" wire:model="snapshot_label_input" />
                            </div>
                            <x-primary-button type="submit" class="!py-2">{{ __('Save snapshot') }}</x-primary-button>
                        </form>
                        @if ($snapshots->isNotEmpty())
                            <ul class="space-y-2 text-sm">
                                @foreach ($snapshots as $snap)
                                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-brand-ink/10 px-3 py-2">
                                        <span>{{ $snap->label ?: $snap->id }}</span>
                                        <span class="text-xs text-brand-moss">{{ $snap->created_at?->diffForHumans() }}</span>
                                        <button
                                            type="button"
                                            wire:click="restoreFirewallSnapshot('{{ $snap->id }}')"
                                            wire:confirm="{{ __('Replace all rules on this server with this snapshot?') }}"
                                            class="text-xs font-medium text-amber-800 hover:underline"
                                        >
                                            {{ __('Restore') }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="{{ $card }} p-6 sm:p-8 space-y-4">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Scheduled apply') }}</h2>
                        <p class="text-sm text-brand-moss">{{ __('Queue a full apply after a delay (runs via your queue worker).') }}</p>
                        <form wire:submit="scheduleDelayedFirewallApply" class="flex flex-wrap items-end gap-2">
                            <div>
                                <x-input-label for="sched-min" :value="__('Delay (minutes)')" />
                                <x-text-input id="sched-min" type="number" wire:model="schedule_apply_delay_minutes" min="1" max="1440" class="mt-1 w-28" />
                                <x-input-error :messages="$errors->get('schedule_apply_delay_minutes')" />
                            </div>
                            <x-primary-button type="submit" class="!py-2">{{ __('Queue apply') }}</x-primary-button>
                        </form>
                    </div>

                    @if (isset($applyLogs) && $applyLogs->isNotEmpty())
                        <div class="{{ $card }} p-6 sm:p-8">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Apply history') }}</h2>
                            <ul class="mt-4 space-y-3 text-sm">
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
                        </div>
                    @endif

                    @if ($auditEvents->isNotEmpty())
                        <div class="{{ $card }} p-6 sm:p-8">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Recent audit') }}</h2>
                            <ul class="mt-4 space-y-2 text-sm text-brand-moss">
                                @foreach ($auditEvents as $ev)
                                    <li class="flex flex-wrap justify-between gap-2 border-b border-brand-ink/5 pb-2">
                                        <span class="font-mono text-xs text-brand-ink">{{ $ev->event }}</span>
                                        <span class="text-xs">{{ $ev->created_at?->diffForHumans() }}</span>
                                        <span class="w-full text-xs">{{ $ev->user?->name ?? __('API') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($ufw_status_text !== null && $ufw_status_text !== '')
                        <div class="{{ $card }}">
                            <div class="border-b border-brand-ink/10 px-5 py-3 text-sm font-medium text-brand-ink">
                                {{ __('UFW status (verbose)') }}
                            </div>
                            <pre class="max-h-96 overflow-x-auto rounded-b-2xl bg-zinc-950 p-4 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $ufw_status_text }}</pre>
                        </div>
                    @endif
        </x-server-workspace-tab-panel>
        </div>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
