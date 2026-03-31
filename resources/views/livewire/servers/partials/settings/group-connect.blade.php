@php
    $osVersions = $osVersions ?? config('server_settings.os_versions', []);
@endphp

<section id="settings-group-connect" class="space-y-4" aria-labelledby="settings-group-connect-title">
    @include('livewire.servers.partials.settings._intro', [
        'headingId' => 'settings-group-connect-title',
        'kicker' => __('Connect'),
        'title' => __('Connection & identity'),
        'description' => __('Name, tags, workspace, and SSH details control how Dply reaches this server. Saving updates connection settings; changing host, port, user, or workspace is recorded in your organization audit log.'),
    ])

    <div id="settings-connection" class="{{ $card }} scroll-mt-24 overflow-hidden p-6 sm:p-8">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Server details') }}</h3>
        <p class="mt-1 text-sm text-brand-moss">
            {{ __('Labels and SSH login used for deploys and Manage. Internal IP is optional documentation for private networking.') }}
        </p>

        <form wire:submit="saveServerSettingsInfo" class="mt-6 grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-input-label for="settings-name" value="{{ __('Server name') }}" />
                <input
                    id="settings-name"
                    type="text"
                    wire:model="settingsName"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
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
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canEditServerSettings)
                />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Comma-separated labels for search and filters in Dply.') }}</p>
                <x-input-error :messages="$errors->get('settingsTags')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="settings-ip" value="{{ __('IP address or hostname') }}" />
                <input
                    id="settings-ip"
                    type="text"
                    wire:model="settingsIpAddress"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm font-mono text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canEditServerSettings)
                />
                <x-input-error :messages="$errors->get('settingsIpAddress')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="settings-internal-ip" value="{{ __('Internal IP address') }}" />
                <input
                    id="settings-internal-ip"
                    type="text"
                    wire:model="settingsInternalIp"
                    placeholder="{{ __('Optional') }}"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm font-mono text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
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
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
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
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canEditServerSettings)
                />
                <x-input-error :messages="$errors->get('settingsSshUser')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="settings-os" value="{{ __('OS version (label)') }}" />
                <select
                    id="settings-os"
                    wire:model="settingsOsVersion"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canEditServerSettings)
                >
                    @foreach ($osVersions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Your catalog label; inventory can suggest a different value from the live OS.') }}</p>
                <x-input-error :messages="$errors->get('settingsOsVersion')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="settings-workspace" value="{{ __('Workspace') }}" />
                <select
                    id="settings-workspace"
                    wire:model="settingsWorkspaceId"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canEditServerSettings)
                >
                    <option value="">{{ __('No workspace') }}</option>
                    @foreach ($workspaces as $ws)
                        <option value="{{ $ws->id }}">{{ $ws->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('settingsWorkspaceId')" class="mt-2" />
            </div>
            @if ($this->canEditServerSettings)
                <div class="sm:col-span-2 flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save server details') }}</x-primary-button>
                </div>
            @endif
        </form>

        <div class="mt-8 border-t border-brand-ink/10 pt-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Effective connection (read-only)') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Snapshot of what Dply will use after your saved details. Edit fields above to change these values.') }}
            </p>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2 text-sm">
                <div>
                    <dt class="text-brand-mist">{{ __('Host') }}</dt>
                    <dd class="mt-0.5 font-mono text-xs font-medium text-brand-ink">{{ $server->ip_address ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Port / user') }}</dt>
                    <dd class="mt-0.5 font-mono text-xs font-medium text-brand-ink">{{ $server->ssh_port ?: 22 }} / {{ $server->ssh_user ?: '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-brand-mist">{{ __('Workspace') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">{{ $workspaceLabel ?? __('No workspace') }}</dd>
                </div>
                @if (! empty($meta['internal_ip']))
                    <div class="sm:col-span-2">
                        <dt class="text-brand-mist">{{ __('Internal IP (saved)') }}</dt>
                        <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $meta['internal_ip'] }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>
</section>
