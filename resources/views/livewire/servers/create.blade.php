<div>
    <div class="border-b border-slate-200 bg-white">
        <div class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
            <a href="{{ route('servers.index') }}" wire:navigate class="text-sm font-medium text-sky-700 hover:text-sky-900">{{ __('← Cancel') }}</a>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-slate-900">{{ __('Create server') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-slate-600">
                {{ __('Choose a cloud provider and API credentials from Server providers, or connect your own VPS. Region and plan options are loaded from the provider for the account you select.') }}
            </p>
        </div>
    </div>

    <div class="py-10">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-livewire-validation-errors />
            @if (session('error'))
                <div class="mb-4 p-4 rounded-lg bg-red-50 text-red-800">{{ session('error') }}</div>
            @endif
            @error('org')
                <div class="mb-4 p-4 rounded-lg bg-red-50 text-red-800">{{ $message }}</div>
            @enderror

            @env('local')
                <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50/90 px-4 py-3 text-sm text-sky-950">
                    <p class="font-medium">{{ __('Local dev: finish provisioning') }}</p>
                    <p class="mt-1 text-sky-900/90">
                        {{ __('Run :queue in another terminal. Cloud APIs are called from this app; droplet readiness is polled — no inbound callback.', ['queue' => 'php artisan queue:work']) }}
                        {{ __('Link Server providers (API token or OAuth). OAuth needs a public :app (e.g. via Expose); see :doc.', ['app' => 'APP_URL', 'doc' => 'docs/BYO_LOCAL_SETUP.md']) }}
                    </p>
                </div>
            @endenv

            @if (!$canCreateServer && $billingUrl)
                <div class="mb-8 bg-amber-50 border border-amber-200 rounded-xl p-6 text-amber-900">
                    <p class="font-medium">{{ __('Server limit reached for your plan.') }}</p>
                    <p class="mt-1 text-sm">{{ __('Upgrade to add more servers.') }}</p>
                    <a href="{{ $billingUrl }}" class="mt-4 inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg font-medium text-sm hover:bg-amber-700">{{ __('Go to billing') }}</a>
                </div>
            @endif

            <div class="@if(!$canCreateServer) opacity-60 pointer-events-none @endif space-y-10">
                <section aria-labelledby="provider-heading">
                    <h2 id="provider-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('1. Select provider') }}</h2>
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($providerCards as $card)
                            <button
                                type="button"
                                wire:click="$set('form.type', '{{ $card['id'] }}')"
                                class="relative flex flex-col items-start rounded-xl border-2 p-4 text-left transition
                                    {{ $form->type === $card['id'] ? 'border-sky-600 bg-sky-50/80 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300' }}"
                            >
                                <span class="font-medium text-slate-900">{{ $card['label'] }}</span>
                                @if (!$card['linked'] && $card['id'] !== 'custom')
                                    <span class="mt-1 text-xs text-amber-700">{{ __('No credentials') }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                    <p class="mt-4 text-sm text-slate-600">
                        <a href="{{ route('credentials.index') }}" wire:navigate class="font-medium text-sky-700 hover:text-sky-900">{{ __('Add a new provider') }}</a>
                        <span class="text-slate-400"> · </span>
                        <a href="{{ route('docs.connect-provider') }}" wire:navigate class="text-sky-700 hover:text-sky-900">{{ __('Connection guide') }}</a>
                    </p>
                </section>

                <section aria-labelledby="details-heading">
                    <h2 id="details-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('2. Details') }}</h2>

                    <form wire:submit="store" class="mt-4 space-y-6">
                        @if ($form->type !== 'custom')
                            @php
                                $digitalOceanEnvCatalog = $form->type === 'digitalocean' && filled(config('services.digitalocean.token'));
                                $showCloudStackFields = $form->provider_credential_id !== '' || $digitalOceanEnvCatalog;
                                if ($form->type === 'fly_io') {
                                    $regionSizePickReady = $catalog['credentials']->isNotEmpty();
                                } elseif ($form->type === 'digitalocean') {
                                    $regionSizePickReady = $digitalOceanEnvCatalog || $form->provider_credential_id !== '';
                                } else {
                                    $regionSizePickReady = $catalog['credentials']->isNotEmpty() && $form->provider_credential_id !== '';
                                }
                            @endphp
                            @if ($catalog['credentials']->isEmpty())
                                @if ($digitalOceanEnvCatalog)
                                    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sky-950">
                                        <p class="font-medium">{{ __('No linked DigitalOcean account') }}</p>
                                        <p class="mt-1 text-sm">
                                            {{ __('Regions and sizes below are loaded using DIGITALOCEAN_TOKEN. Add a credential under Server providers to create a droplet.') }}
                                            <a href="{{ route('credentials.index') }}" wire:navigate class="underline font-medium">{{ __('Go to Server providers') }}</a>
                                        </p>
                                    </div>
                                @else
                                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
                                        <p class="font-medium">{{ __('Add a credential first') }}</p>
                                        <p class="mt-1 text-sm">
                                            {{ __('Save an API token for this provider under Server providers, then return here.') }}
                                            <a href="{{ route('credentials.index') }}" wire:navigate class="underline font-medium">{{ __('Go to Server providers') }}</a>
                                        </p>
                                    </div>
                                @endif
                            @endif

                            <div>
                                <x-input-label for="provider_credential_id" :value="__('Credentials')" />
                                <select
                                    wire:model.live="form.provider_credential_id"
                                    id="provider_credential_id"
                                    class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500"
                                    @if($catalog['credentials']->isEmpty()) disabled @endif
                                >
                                    <option value="">{{ __('Select account') }}</option>
                                    @foreach ($catalog['credentials'] as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                            </div>

                            @if ($catalog['credentials']->isNotEmpty() && $form->provider_credential_id === '')
                                <p class="text-sm text-slate-600">{{ __('Choose an account above to configure your server.') }}</p>
                            @endif

                            @if ($showCloudStackFields)
                            <div>
                                <x-input-label for="server_name" :value="__('Server name')" />
                                <x-text-input id="server_name" wire:model="form.name" type="text" class="mt-1 block w-full" required placeholder="crunchy-salamander" autocomplete="off" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label for="server_role" :value="__('Server type')" />
                                <select wire:model.live="form.server_role" id="server_role" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                    @foreach ($provisionOptions['server_roles'] ?? [] as $role)
                                        <option value="{{ $role['id'] }}">{{ $role['label'] }}</option>
                                    @endforeach
                                </select>
                                @php
                                    $selectedServerRole = collect($provisionOptions['server_roles'] ?? [])->firstWhere('id', $form->server_role);
                                @endphp
                                @if ($selectedServerRole && ! empty($selectedServerRole['detail'] ?? null))
                                    <p class="mt-1 text-xs text-slate-600 font-mono leading-relaxed"><span class="text-slate-400 select-none" aria-hidden="true">└─</span> {{ $selectedServerRole['detail'] }}</p>
                                @endif
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ __('Preferences are stored on the server record for setup scripts. Instance size and region still come from your cloud provider.') }}
                                </p>
                                <x-input-error :messages="$errors->get('server_role')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label for="cache_service" :value="__('Cache service')" />
                                <select wire:model="form.cache_service" id="cache_service" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                    @foreach ($provisionOptions['cache_services'] ?? [] as $opt)
                                        <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-slate-500">{{ __('Select which cache service to install during setup (when your setup script supports it).') }}</p>
                                <x-input-error :messages="$errors->get('cache_service')" class="mt-1" />
                            </div>

                            <div class="grid gap-4 sm:grid-cols-3" wire:key="provision-stack-{{ $form->server_role }}">
                                <div>
                                    <x-input-label for="webserver" :value="__('Web server')" />
                                    <select wire:model="form.webserver" id="webserver" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                        @foreach ($provisionOptions['webservers'] ?? [] as $opt)
                                            <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('webserver')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="php_version" :value="__('PHP version')" />
                                    <select wire:model="form.php_version" id="php_version" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                        @foreach ($provisionOptions['php_versions'] ?? [] as $opt)
                                            <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('php_version')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="database" :value="__('Database')" />
                                    <select wire:model="form.database" id="database" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                        @foreach ($provisionOptions['databases'] ?? [] as $opt)
                                            <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('database')" class="mt-1" />
                                </div>
                            </div>

                            <div
                                class="grid gap-4 sm:grid-cols-2"
                                wire:key="catalog-{{ $form->type }}-{{ $form->provider_credential_id }}-{{ $form->region }}"
                            >
                                <div>
                                    <x-input-label for="form_region" :value="$catalog['region_label']" />
                                    <select
                                        wire:model.live="form.region"
                                        id="form_region"
                                        class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500"
                                        @if(! $regionSizePickReady) disabled @endif
                                    >
                                        <option value="">{{ __('Select options') }}</option>
                                        @foreach ($catalog['regions'] as $opt)
                                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('region')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="form_size" :value="$catalog['size_label']" />
                                    <select
                                        wire:model="form.size"
                                        id="form_size"
                                        class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500"
                                        @if(! $regionSizePickReady) disabled @endif
                                    >
                                        <option value="">{{ __('Select options') }}</option>
                                        @foreach ($catalog['sizes'] as $opt)
                                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('size')" class="mt-1" />
                                </div>
                            </div>
                            @if ($form->type === 'scaleway')
                                <p class="text-xs text-slate-500">{{ __('Choose a zone first; instance types load for that zone.') }}</p>
                            @endif

                            <div>
                                <x-input-label for="setup_script_key" :value="__('Setup script (optional)')" />
                                <select wire:model="form.setup_script_key" id="setup_script_key" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                    <option value="">{{ __('None') }}</option>
                                    @foreach ($setupScripts as $key => $script)
                                        <option value="{{ $key }}">{{ $script['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('setup_script_key')" class="mt-1" />
                            </div>

                            @if ($form->type === 'digitalocean')
                                <div class="rounded-xl border border-slate-200 bg-slate-50/90 p-4 space-y-4">
                                    <div>
                                        <p class="text-sm font-medium text-slate-900">{{ __('Droplet options') }}</p>
                                        <p class="mt-0.5 text-xs text-slate-600">{{ __('These map to DigitalOcean’s create-droplet API (IPv6, backups, monitoring, VPC, tags, cloud-init).') }}</p>
                                    </div>
                                    <div class="space-y-3">
                                        <label class="flex gap-3 items-start cursor-pointer">
                                            <input type="checkbox" wire:model="form.do_ipv6" class="mt-1 rounded border-slate-300 text-sky-600 shadow-sm focus:ring-sky-500" />
                                            <span>
                                                <span class="block text-sm font-medium text-slate-800">{{ __('Enable IPv6') }}</span>
                                                <span class="block text-xs text-slate-600">{{ __('Assign a public IPv6 address in addition to IPv4.') }}</span>
                                            </span>
                                        </label>
                                        <label class="flex gap-3 items-start cursor-pointer">
                                            <input type="checkbox" wire:model="form.do_backups" class="mt-1 rounded border-slate-300 text-sky-600 shadow-sm focus:ring-sky-500" />
                                            <span>
                                                <span class="block text-sm font-medium text-slate-800">{{ __('Enable backups') }}</span>
                                                <span class="block text-xs text-slate-600">{{ __('Weekly droplet backups (billed by DigitalOcean).') }}</span>
                                            </span>
                                        </label>
                                        <label class="flex gap-3 items-start cursor-pointer">
                                            <input type="checkbox" wire:model="form.do_monitoring" class="mt-1 rounded border-slate-300 text-sky-600 shadow-sm focus:ring-sky-500" />
                                            <span>
                                                <span class="block text-sm font-medium text-slate-800">{{ __('Enable monitoring') }}</span>
                                                <span class="block text-xs text-slate-600">{{ __('Install the DigitalOcean metrics agent (free graphs in the control panel).') }}</span>
                                            </span>
                                        </label>
                                    </div>
                                    <div>
                                        <x-input-label for="do_vpc_uuid" :value="__('VPC UUID (optional)')" />
                                        <x-text-input id="do_vpc_uuid" wire:model="form.do_vpc_uuid" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off" />
                                        <p class="mt-1 text-xs text-slate-500">{{ __('Leave empty to use the default VPC for the region.') }}</p>
                                        <x-input-error :messages="$errors->get('do_vpc_uuid')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="do_tags" :value="__('Tags (optional)')" />
                                        <x-text-input id="do_tags" wire:model="form.do_tags" type="text" class="mt-1 block w-full" placeholder="env:production, app:api" autocomplete="off" />
                                        <p class="mt-1 text-xs text-slate-500">{{ __('Comma-separated. Letters, numbers, underscores, periods, colons, hyphens only (max 25 tags).') }}</p>
                                        <x-input-error :messages="$errors->get('do_tags')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="do_user_data" :value="__('Cloud-init user data (optional)')" />
                                        <textarea id="do_user_data" wire:model="form.do_user_data" rows="5" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm font-mono text-sm focus:border-sky-500 focus:ring-sky-500" placeholder="#cloud-config"></textarea>
                                        <x-input-error :messages="$errors->get('do_user_data')" class="mt-1" />
                                    </div>
                                </div>
                            @endif
                            @endif
                        @else
                            <div class="space-y-4">
                                <div>
                                    <x-input-label for="custom_name" :value="__('Server name')" />
                                    <x-text-input id="custom_name" wire:model="form.name" type="text" class="mt-1 block w-full" required />
                                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="ip_address" :value="__('IP address')" />
                                    <x-text-input id="ip_address" wire:model="form.ip_address" type="text" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('ip_address')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="ssh_port" :value="__('SSH port')" />
                                    <x-text-input id="ssh_port" wire:model="form.ssh_port" type="number" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('ssh_port')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="ssh_user" :value="__('SSH user')" />
                                    <x-text-input id="ssh_user" wire:model="form.ssh_user" type="text" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('ssh_user')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="ssh_private_key" :value="__('SSH private key (PEM / OpenSSH)')" />
                                    <textarea id="ssh_private_key" wire:model="form.ssh_private_key" rows="6" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm font-mono text-sm focus:border-sky-500 focus:ring-sky-500"></textarea>
                                    <x-input-error :messages="$errors->get('ssh_private_key')" class="mt-1" />
                                </div>
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
                            <a href="{{ route('servers.index') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-lg font-medium text-sm text-slate-700 shadow-sm hover:bg-slate-50">{{ __('Cancel') }}</a>
                            @if ($form->type === 'custom' || $form->provider_credential_id !== '')
                                <x-primary-button type="submit">{{ __('Create server') }}</x-primary-button>
                            @endif
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</div>
