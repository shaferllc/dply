@php
    $osVersions = $osVersions ?? config('server_settings.os_versions', []);
    $maintenanceWeekdays = config('server_settings.maintenance_weekdays', []);
    $showRepairCard = $server->isReady()
        && filled($server->ip_address)
        && $server->recoverySshPrivateKey() !== null
        && ($server->ssh_user ?? 'root') !== 'root';

    $inputClass = 'mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30';
    $monoInputClass = $inputClass.' font-mono';
@endphp

<section id="settings-group-connect" class="space-y-6" aria-labelledby="settings-group-connect-title">
    <div id="settings-connection" class="{{ $card }} scroll-mt-24">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Connect') }}</p>
                <h2 id="settings-group-connect-title" class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Connection & identity') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Name, tags, workspace, and SSH details control how Dply reaches this server. Changes to host, port, user, or workspace are recorded in your organization audit log.') }}</p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
            <form wire:submit="saveServerSettingsInfo" class="space-y-8">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Identity') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('How this server is labelled and grouped in Dply.') }}</p>
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-input-label for="settings-name" value="{{ __('Server name') }}" />
                            <input
                                id="settings-name"
                                type="text"
                                wire:model="settingsName"
                                class="{{ $inputClass }}"
                                @disabled(! $this->canEditServerSettings)
                            />
                            <x-input-error :messages="$errors->get('settingsName')" class="mt-2" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="settings-tags" value="{{ __('Tags') }}" />
                            @php
                                $tagsDisabled = ! $this->canEditServerSettings;
                            @endphp
                            <div
                                x-data="{
                                    raw: @entangle('settingsTags'),
                                    draft: '',
                                    disabled: @js($tagsDisabled),
                                    get chips() {
                                        return this.raw
                                            .split(',')
                                            .map((t) => t.trim())
                                            .filter((t) => t.length > 0);
                                    },
                                    sync(list) {
                                        this.raw = list.join(', ');
                                    },
                                    add() {
                                        if (this.disabled) return;
                                        const value = this.draft.trim().replace(/,+$/, '').trim();
                                        this.draft = '';
                                        if (value === '') return;
                                        const list = this.chips;
                                        if (! list.includes(value)) {
                                            list.push(value);
                                            this.sync(list);
                                        }
                                    },
                                    remove(index) {
                                        if (this.disabled) return;
                                        const list = this.chips;
                                        list.splice(index, 1);
                                        this.sync(list);
                                    },
                                    backspace() {
                                        if (this.disabled || this.draft !== '') return;
                                        const list = this.chips;
                                        if (list.length > 0) {
                                            list.pop();
                                            this.sync(list);
                                        }
                                    },
                                }"
                                class="mt-1 flex w-full flex-wrap items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-sm shadow-sm focus-within:border-brand-sage focus-within:ring-2 focus-within:ring-brand-sage/30"
                                :class="disabled ? 'cursor-not-allowed opacity-60' : 'cursor-text'"
                                @click="$refs.tagInput && $refs.tagInput.focus()"
                            >
                                <template x-for="(chip, index) in chips" :key="index">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-xs font-medium text-brand-ink ring-1 ring-brand-ink/10">
                                        <span x-text="chip"></span>
                                        <button
                                            type="button"
                                            x-show="! disabled"
                                            @click.stop="remove(index)"
                                            class="-mr-0.5 inline-flex h-4 w-4 items-center justify-center rounded-full text-brand-moss transition hover:bg-brand-ink/10 hover:text-brand-ink focus:outline-none focus:ring-1 focus:ring-brand-sage"
                                            :aria-label="'Remove ' + chip"
                                        >
                                            <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
                                                <path d="M3 3l6 6M9 3l-6 6" />
                                            </svg>
                                        </button>
                                    </span>
                                </template>
                                <input
                                    id="settings-tags"
                                    type="text"
                                    x-ref="tagInput"
                                    x-model="draft"
                                    @keydown.enter.prevent="add()"
                                    @keydown="if ($event.key === ',') { $event.preventDefault(); add(); }"
                                    @keydown.backspace="backspace()"
                                    @blur="add()"
                                    placeholder="{{ __('e.g. production, api') }}"
                                    autocomplete="off"
                                    class="min-w-[8rem] flex-1 border-0 bg-transparent px-1 py-1 text-sm text-brand-ink placeholder:text-brand-mist focus:outline-none focus:ring-0 disabled:cursor-not-allowed"
                                    :disabled="disabled"
                                />
                            </div>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Comma-separated labels for search and filters.') }}</p>
                            <x-input-error :messages="$errors->get('settingsTags')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="settings-os" value="{{ __('OS version (label)') }}" />
                            <select
                                id="settings-os"
                                wire:model="settingsOsVersion"
                                class="{{ $inputClass }}"
                                @disabled(! $this->canEditServerSettings)
                            >
                                @foreach ($osVersions as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Catalog label only — inventory can suggest a value from the live OS.') }}</p>
                            <x-input-error :messages="$errors->get('settingsOsVersion')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="settings-workspace" value="{{ __('Workspace') }}" />
                            <select
                                id="settings-workspace"
                                wire:model="settingsWorkspaceId"
                                class="{{ $inputClass }}"
                                @disabled(! $this->canEditServerSettings)
                            >
                                <option value="">{{ __('No workspace') }}</option>
                                @foreach ($workspaces as $ws)
                                    <option value="{{ $ws->id }}">{{ $ws->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('settingsWorkspaceId')" class="mt-2" />
                        </div>
                    </div>
                </div>

                <div class="border-t border-brand-ink/10 pt-8">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('SSH connection') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Dply reaches this host for deploys and Manage actions over SSH at the address, port, and user below. (Internal IP is private-networking metadata — it is not the SSH target.)') }}</p>
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div>
                            <x-input-label for="settings-ip" value="{{ __('IP address or hostname') }}" />
                            <input
                                id="settings-ip"
                                type="text"
                                wire:model="settingsIpAddress"
                                class="{{ $monoInputClass }}"
                                @disabled(! $this->canEditServerSettings)
                            />
                            <x-input-error :messages="$errors->get('settingsIpAddress')" class="mt-2" />
                        </div>
                        <div @if ($this->internalIpRefreshing) wire:poll.4s="reloadInternalIp" @endif>
                            <div class="flex items-center justify-between gap-2">
                                <x-input-label for="settings-internal-ip" value="{{ __('Internal IP') }}" />
                                @if ($this->canRefreshInternalIp() && $this->canEditServerSettings)
                                    <button
                                        type="button"
                                        wire:click="refreshInternalIp"
                                        wire:loading.attr="disabled"
                                        wire:target="refreshInternalIp"
                                        @disabled($this->internalIpRefreshing)
                                        class="inline-flex items-center gap-1 rounded text-xs font-medium text-brand-forest transition hover:text-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                                    >
                                        @if ($this->internalIpRefreshing)
                                            <x-spinner variant="forest" size="sm" />
                                            {{ __('Refreshing…') }}
                                        @else
                                            <span wire:loading.remove wire:target="refreshInternalIp" class="inline-flex items-center gap-1">
                                                <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                                                {{ __('Refresh') }}
                                            </span>
                                            <span wire:loading wire:target="refreshInternalIp" class="inline-flex items-center gap-1">
                                                <x-spinner variant="forest" size="sm" />
                                                {{ __('Requesting…') }}
                                            </span>
                                        @endif
                                    </button>
                                @endif
                            </div>
                            <input
                                id="settings-internal-ip"
                                type="text"
                                wire:model="settingsInternalIp"
                                placeholder="{{ __('Optional, for private networking') }}"
                                class="{{ $monoInputClass }} placeholder:font-sans placeholder:text-brand-mist"
                                @disabled(! $this->canEditServerSettings)
                            />
                            <p class="mt-1 text-xs text-brand-mist">
                                {{ __('Private-networking address for server-to-server traffic (cache replicas, DB jump host, log drain). Not used for the SSH connection above.') }}
                                @if ($this->canRefreshInternalIp())
                                    {{ __('Use Refresh to re-fetch it from :provider.', ['provider' => $server->provider?->label() ?? __('the provider')]) }}
                                @endif
                            </p>
                            <x-input-error :messages="$errors->get('settingsInternalIp')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="settings-ssh-port" value="{{ __('SSH port') }}" />
                            <input
                                id="settings-ssh-port"
                                type="number"
                                min="1"
                                max="65535"
                                wire:model="settingsSshPort"
                                class="{{ $inputClass }}"
                                @disabled(! $this->canEditServerSettings)
                            />
                            <x-input-error :messages="$errors->get('settingsSshPort')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="settings-ssh-user" value="{{ __('SSH user') }}" />
                            <input
                                id="settings-ssh-user"
                                type="text"
                                wire:model="settingsSshUser"
                                class="{{ $inputClass }}"
                                @disabled(! $this->canEditServerSettings)
                            />
                            <x-input-error :messages="$errors->get('settingsSshUser')" class="mt-2" />
                        </div>
                    </div>
                </div>

                {{-- Live connectivity verdict from the operational-SSH probe that
                     runs on save (and on demand via "Test connection"). --}}
                @php
                    $sshProbeStatus = (string) ($server->meta['ssh_operational_status'] ?? '');
                    $sshProbeTestedAt = $server->meta['ssh_operational_tested_at'] ?? null;
                    $sshProbeError = (string) ($server->meta['ssh_operational_error'] ?? '');
                @endphp
                <div @if ($this->operationalSshProbing) wire:poll.3s="reloadOperationalSshStatus" @endif>
                    @if ($this->operationalSshProbing)
                        <div class="flex items-center gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-3 py-2 text-sm text-brand-moss">
                            <x-spinner variant="forest" size="sm" />
                            {{ __('Testing the SSH connection as :user@:host…', ['user' => $settingsSshUser ?: $server->ssh_user, 'host' => $settingsIpAddress ?: $server->ip_address]) }}
                        </div>
                    @elseif ($sshProbeStatus === 'healthy')
                        <div class="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                            <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <span>
                                {{ __('Connection OK — Dply reached this host on the operational key.') }}
                                @if ($sshProbeTestedAt)
                                    <span class="text-emerald-700/80">{{ __('Tested :ago.', ['ago' => \Illuminate\Support\Carbon::parse($sshProbeTestedAt)->diffForHumans()]) }}</span>
                                @endif
                            </span>
                        </div>
                    @elseif ($sshProbeStatus === 'failing')
                        <div class="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                            <x-heroicon-m-x-circle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <span>
                                {{ __('Connection failed.') }}
                                @if ($sshProbeError !== '')
                                    <span class="break-words text-rose-700">{{ $sshProbeError }}</span>
                                @endif
                                <span class="text-rose-700/80">{{ __('Check the address, port, and user — or use Repair SSH access below.') }}</span>
                            </span>
                        </div>
                    @endif
                </div>

                @if ($this->canEditServerSettings)
                    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-brand-ink/10 pt-6">
                        <button
                            type="button"
                            wire:click="testSshConnection"
                            wire:loading.attr="disabled"
                            wire:target="testSshConnection"
                            @disabled($this->operationalSshProbing)
                            class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <x-heroicon-o-signal class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ $this->operationalSshProbing ? __('Testing…') : __('Test connection') }}
                        </button>
                        <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save changes') }}</x-primary-button>
                    </div>
                @endif
            </form>
        </div>
    </div>

    <div id="settings-timezone" class="{{ $card }} scroll-mt-24">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Timezone') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Display timezone') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Used when showing times in this workspace. The guest OS keeps its own timezone unless you change it over SSH.') }}
                </p>
            </div>
        </div>
        <div class="px-6 py-6 sm:px-7">
        <form wire:submit="saveServerTimezone" class="max-w-md">
            <x-input-label for="settings-tz" value="{{ __('Timezone') }}" />
            <select
                id="settings-tz"
                wire:model="settingsTimezone"
                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                @disabled(! $this->canEditServerSettings)
            >
                @if ($this->settingsTimezone !== '' && ! in_array($this->settingsTimezone, $tzPreset, true))
                    <option value="{{ $this->settingsTimezone }}">{{ $this->settingsTimezone }} ({{ __('current') }})</option>
                @endif
                @foreach ($tzPreset as $tz)
                    <option value="{{ $tz }}">{{ $tz }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('settingsTimezone')" class="mt-2" />
            @if ($this->canEditServerSettings)
                <x-primary-button type="submit" class="mt-4" wire:loading.attr="disabled">{{ __('Save timezone') }}</x-primary-button>
            @endif
        </form>
        </div>
    </div>

    <div id="settings-date-format" class="{{ $card }} scroll-mt-24">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-calendar-days class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Format') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Date format') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Controls how this server\'s timestamps render across the workspace — last sample, deploys, audit log, etc. Saved on the server, so different servers can use different formats.') }}
                </p>
            </div>
        </div>
        <div class="px-6 py-6 sm:px-7">
        <form wire:submit="saveServerDateFormat" class="max-w-md">
            <x-input-label for="settings-date-format-select" value="{{ __('Format') }}" />
            <select
                id="settings-date-format-select"
                wire:model="settingsDateFormat"
                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                @disabled(! $this->canEditServerSettings)
            >
                @foreach (config('server_settings.date_formats', []) as $key => $option)
                    <option value="{{ $key }}">{{ $option['label'] }} — {{ $option['sample'] }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('settingsDateFormat')" class="mt-2" />
            @php
                $previewSample = config('server_settings.date_formats.'.$this->settingsDateFormat.'.sample')
                    ?? config('server_settings.date_formats.absolute_utc.sample');
            @endphp
            <p class="mt-3 text-xs text-brand-mist">{{ __('Preview:') }} <span class="font-mono text-brand-ink">{{ $previewSample }}</span></p>
            @if ($this->canEditServerSettings)
                <x-primary-button type="submit" class="mt-4" wire:loading.attr="disabled">{{ __('Save format') }}</x-primary-button>
            @endif
        </form>
        </div>
    </div>

    {{-- The preferred maintenance schedule editor moved to the server
         Maintenance workspace → Schedule tab (servers.maintenance). --}}

    @if ($showRepairCard)
        <div id="settings-connection-repair" class="{{ $card }} scroll-mt-24">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <x-icon-badge tone="amber">
                    <x-heroicon-o-wrench-screwdriver class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Recovery') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Repair SSH access') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('If the deploy user no longer accepts Dply’s operational key, repair access from the hidden root recovery key without changing your saved connection details.') }}
                    </p>
                </div>
            </div>
            <div class="px-6 py-5 sm:px-7" @if ($this->operationalSshProbing) wire:poll.3s="reloadOperationalSshStatus" @endif>
                @php $rs = $this->recoverySshStatus; @endphp
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 space-y-3">
                        {{-- Are we on the recovery key right now? --}}
                        @if ($this->operationalSshProbing)
                            <span class="inline-flex items-center gap-2 rounded-full border border-brand-ink/10 bg-brand-sand/30 px-3 py-1 text-sm font-medium text-brand-moss">
                                <x-spinner variant="forest" size="sm" />
                                {{ __('Testing operational access…') }}
                            </span>
                        @elseif ($rs['on_recovery'])
                            <span class="inline-flex items-center gap-2 rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-sm font-semibold text-rose-800">
                                <span class="inline-block h-2 w-2 rounded-full bg-rose-500" aria-hidden="true"></span>
                                {{ __('Relying on the root recovery key — operational key rejected') }}
                            </span>
                        @elseif ($rs['state'] === 'healthy')
                            <span class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-800">
                                <span class="inline-block h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                {{ __('Operational key healthy — recovery not in use') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-2 rounded-full border border-brand-ink/15 bg-white px-3 py-1 text-sm font-medium text-brand-moss">
                                <span class="inline-block h-2 w-2 rounded-full bg-brand-mist" aria-hidden="true"></span>
                                {{ __('Operational key not yet tested') }}
                            </span>
                        @endif

                        <dl class="grid gap-x-6 gap-y-1 text-xs text-brand-moss sm:grid-cols-2">
                            @if ($rs['tested_at'])
                                <div><dt class="inline text-brand-mist">{{ __('Last tested') }}:</dt> <dd class="inline">{{ $rs['tested_at']->timezone(config('app.timezone'))->diffForHumans() }}</dd></div>
                            @endif
                            @if ($rs['last_operational_at'])
                                <div><dt class="inline text-brand-mist">{{ __('Last operational connection') }}:</dt> <dd class="inline">{{ $rs['last_operational_at']->timezone(config('app.timezone'))->diffForHumans() }} <span class="font-mono">({{ $server->ssh_user }})</span></dd></div>
                            @endif
                            @if ($rs['last_recovery_at'])
                                <div><dt class="inline text-brand-mist">{{ __('Last recovery (root) connection') }}:</dt> <dd class="inline">{{ $rs['last_recovery_at']->timezone(config('app.timezone'))->diffForHumans() }}</dd></div>
                            @endif
                            @if ($rs['on_recovery'] && $rs['error'])
                                <div class="sm:col-span-2 break-words text-rose-700">{{ $rs['error'] }}</div>
                            @endif
                        </dl>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <button
                            type="button"
                            wire:click="testSshConnection"
                            wire:loading.attr="disabled"
                            wire:target="testSshConnection"
                            @disabled($this->operationalSshProbing)
                            class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <x-heroicon-o-signal class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ $this->operationalSshProbing ? __('Testing…') : __('Test operational access') }}
                        </button>
                        <x-primary-button type="button" wire:click="repairSshAccess" wire:loading.attr="disabled" wire:target="repairSshAccess">
                            <span wire:loading.remove wire:target="repairSshAccess">{{ __('Repair SSH access') }}</span>
                            <span wire:loading wire:target="repairSshAccess" class="inline-flex items-center gap-2">
                                <x-spinner variant="forest" size="sm" />
                                {{ __('Repairing…') }}
                            </span>
                        </x-primary-button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div id="settings-provider" class="{{ $card }} scroll-mt-24">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Provider') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Provider & lifecycle') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Read-only provisioning metadata from when this server was created.') }}</p>
            </div>
        </div>
        <div class="px-6 py-6 sm:px-7">
            @php
                $statusReady = $server->status === 'ready';
                $healthOk = in_array($server->health_status, ['reachable', 'healthy', 'ok'], true);
                $statusDot = match (true) {
                    $statusReady && ($server->health_status === null || $healthOk) => 'bg-brand-forest',
                    $server->health_status !== null && ! $healthOk => 'bg-red-500',
                    default => 'bg-amber-500',
                };
            @endphp
            <dl class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                    <dd class="mt-1 flex items-center gap-2 text-sm font-medium text-brand-ink">
                        <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $statusDot }}" aria-hidden="true"></span>
                        <span>{{ __($server->status) }}@if ($server->health_status) <span class="text-brand-mist">/</span> {{ __($server->health_status) }}@endif</span>
                    </dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Provider') }}</dt>
                    <dd class="mt-1 text-sm font-medium text-brand-ink">{{ $providerLine }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Region') }}</dt>
                    <dd class="mt-1 text-sm font-medium text-brand-ink">{{ $server->region ?: '—' }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Provider server ID') }}</dt>
                    <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $server->provider_id ?: '—' }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Created in Dply') }}</dt>
                    <dd class="mt-1 text-sm font-medium text-brand-ink">{{ $server->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Inventory last checked') }}</dt>
                    <dd class="mt-1 text-sm font-medium text-brand-ink">{{ ($invAt ?? null) ? \Illuminate\Support\Carbon::parse($invAt)->timezone(config('app.timezone'))->toDayDateTimeString() : '—' }}</dd>
                </div>
            </dl>
        </div>
    </div>
</section>
