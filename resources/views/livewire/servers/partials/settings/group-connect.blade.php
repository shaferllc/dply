@php
    $osVersions = $osVersions ?? config('server_settings.os_versions', []);
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
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
            </span>
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
                            <input
                                id="settings-tags"
                                type="text"
                                wire:model="settingsTags"
                                placeholder="{{ __('e.g. production, api') }}"
                                autocomplete="off"
                                class="{{ $inputClass }} placeholder:text-brand-mist"
                                @disabled(! $this->canEditServerSettings)
                            />
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
                        <div>
                            <x-input-label for="settings-internal-ip" value="{{ __('Internal IP') }}" />
                            <input
                                id="settings-internal-ip"
                                type="text"
                                wire:model="settingsInternalIp"
                                placeholder="{{ __('Optional, for private networking') }}"
                                class="{{ $monoInputClass }} placeholder:font-sans placeholder:text-brand-mist"
                                @disabled(! $this->canEditServerSettings)
                            />
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

    @if ($showRepairCard)
        <div id="settings-connection-repair" class="{{ $card }} scroll-mt-24">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 ring-1 ring-amber-200">
                    <x-heroicon-o-wrench-screwdriver class="h-5 w-5" aria-hidden="true" />
                </span>
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
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
            </span>
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
