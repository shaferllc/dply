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
