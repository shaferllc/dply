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
                            @php($tagsDisabled = ! $this->canEditServerSettings)
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
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Where Dply reaches this host for deploys and Manage actions.') }}</p>
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
                            @if ($this->canRefreshInternalIp())
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Re-fetch the private networking address from :provider.', ['provider' => $server->provider?->label() ?? __('the provider')]) }}</p>
                            @endif
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

                @if ($this->canEditServerSettings)
                    <div class="flex justify-end border-t border-brand-ink/10 pt-6">
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

    <div id="settings-maintenance" class="{{ $card }} scroll-mt-24">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-bell-alert class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Schedule') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Preferred maintenance schedule') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Advisory only — the days and hours you\'d prefer Dply to run disruptive work (upgrades, reboots). Dply uses it to warn before risky actions; it doesn\'t pause cron or suspend sites. Times use your Dply timezone preference above, not the server OS clock.') }}
                </p>
            </div>
        </div>
        <div class="px-6 py-6 sm:px-7">
        <form wire:submit="saveMaintenanceWindow" class="space-y-5">
            <fieldset @disabled(! $this->canEditServerSettings)>
                <legend class="text-sm font-medium text-brand-ink">{{ __('Preferred days') }}</legend>
                <div class="mt-2 flex flex-wrap gap-3">
                    @foreach ($maintenanceWeekdays as $key => $label)
                        <label class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2 text-sm">
                            <input type="checkbox" wire:model="settingsMaintenanceDays" value="{{ $key }}" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('settingsMaintenanceDays')" class="mt-2" />
            </fieldset>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="settings-maint-start" value="{{ __('Start (local)') }}" />
                    <input
                        id="settings-maint-start"
                        type="time"
                        wire:model="settingsMaintenanceStart"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        @disabled(! $this->canEditServerSettings)
                    />
                    <x-input-error :messages="$errors->get('settingsMaintenanceStart')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="settings-maint-end" value="{{ __('End (local)') }}" />
                    <input
                        id="settings-maint-end"
                        type="time"
                        wire:model="settingsMaintenanceEnd"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        @disabled(! $this->canEditServerSettings)
                    />
                    <x-input-error :messages="$errors->get('settingsMaintenanceEnd')" class="mt-2" />
                </div>
            </div>
            <div>
                <x-input-label for="settings-maint-note" value="{{ __('Note') }}" />
                <textarea
                    id="settings-maint-note"
                    wire:model="settingsMaintenanceNote"
                    rows="3"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canEditServerSettings)
                ></textarea>
                <x-input-error :messages="$errors->get('settingsMaintenanceNote')" class="mt-2" />
            </div>
            @if ($this->canEditServerSettings)
                <div class="flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save preferred schedule') }}</x-primary-button>
                </div>
            @endif
        </form>
        </div>
    </div>

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
            <div class="flex justify-end px-6 py-5 sm:px-7">
                <x-primary-button type="button" wire:click="repairSshAccess" wire:loading.attr="disabled" wire:target="repairSshAccess">
                    <span wire:loading.remove wire:target="repairSshAccess">{{ __('Repair SSH access') }}</span>
                    <span wire:loading wire:target="repairSshAccess" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Repairing…') }}
                    </span>
                </x-primary-button>
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
            <dl class="grid gap-4 sm:grid-cols-2 text-sm">
                <div>
                    <dt class="text-brand-mist">{{ __('Created in Dply') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">{{ $server->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Provider') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">{{ $providerLine }}</dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Region') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">{{ $server->region ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Provider server ID') }}</dt>
                    <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $server->provider_id ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Status') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">{{ __($server->status) }} @if ($server->health_status) / {{ __($server->health_status) }} @endif</dd>
                </div>
                @if ($invAt ?? null)
                    <div>
                        <dt class="text-brand-mist">{{ __('Inventory last checked') }}</dt>
                        <dd class="mt-0.5 text-xs text-brand-moss">{{ \Illuminate\Support\Carbon::parse($invAt)->timezone(config('app.timezone'))->toDayDateTimeString() }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>
</section>
