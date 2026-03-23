<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Add Server') }}</h2>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <x-livewire-validation-errors />
            @if (session('error'))
                <div class="mb-4 p-4 rounded-md bg-red-50 text-red-800">{{ session('error') }}</div>
            @endif
            @error('org')
                <div class="mb-4 p-4 rounded-md bg-red-50 text-red-800">{{ $message }}</div>
            @enderror
            @if (!$canCreateServer && $billingUrl)
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-6 text-amber-800">
                    <p class="font-medium">Server limit reached for your plan.</p>
                    <p class="mt-1 text-sm">Upgrade to Pro to add more servers.</p>
                    <a href="{{ $billingUrl }}" class="mt-4 inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-md font-semibold text-xs uppercase tracking-widest hover:bg-amber-700">Go to Billing</a>
                </div>
            @endif
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 @if(!$canCreateServer) opacity-60 pointer-events-none @endif" x-data="{ tab: $wire.entangle('type') }">
                <ul class="flex border-b border-slate-200 mb-6">
                    <li><button type="button" @click="tab = 'digitalocean'" :class="tab === 'digitalocean' ? 'border-b-2 border-slate-800 text-slate-900' : 'text-slate-500'" class="px-4 py-2">DigitalOcean</button></li>
                    <li><button type="button" @click="tab = 'hetzner'" :class="tab === 'hetzner' ? 'border-b-2 border-slate-800 text-slate-900' : 'text-slate-500'" class="px-4 py-2">Hetzner</button></li>
                    <li><button type="button" @click="tab = 'linode'" :class="tab === 'linode' ? 'border-b-2 border-slate-800 text-slate-900' : 'text-slate-500'" class="px-4 py-2">Linode</button></li>
                    <li><button type="button" @click="tab = 'vultr'" :class="tab === 'vultr' ? 'border-b-2 border-slate-800 text-slate-900' : 'text-slate-500'" class="px-4 py-2">Vultr</button></li>
                    <li><button type="button" @click="tab = 'akamai'" :class="tab === 'akamai' ? 'border-b-2 border-slate-800 text-slate-900' : 'text-slate-500'" class="px-4 py-2">Akamai</button></li>
                    <li><button type="button" @click="tab = 'scaleway'" :class="tab === 'scaleway' ? 'border-b-2 border-slate-800 text-slate-900' : 'text-slate-500'" class="px-4 py-2">Scaleway</button></li>
                    <li><button type="button" @click="tab = 'upcloud'" :class="tab === 'upcloud' ? 'border-b-2 border-slate-800 text-slate-900' : 'text-slate-500'" class="px-4 py-2">UpCloud</button></li>
                    <li><button type="button" @click="tab = 'equinix_metal'" :class="tab === 'equinix_metal' ? 'border-b-2 border-slate-800 text-slate-900' : 'text-slate-500'" class="px-4 py-2">Equinix Metal</button></li>
                    <li><button type="button" @click="tab = 'fly_io'" :class="tab === 'fly_io' ? 'border-b-2 border-slate-800 text-slate-900' : 'text-slate-500'" class="px-4 py-2">Fly.io</button></li>
                    <li><button type="button" @click="tab = 'aws'" :class="tab === 'aws' ? 'border-b-2 border-slate-800 text-slate-900' : 'text-slate-500'" class="px-4 py-2">AWS EC2</button></li>
                    <li><button type="button" @click="tab = 'custom'" :class="tab === 'custom' ? 'border-b-2 border-slate-800 text-slate-900' : 'text-slate-500'" class="px-4 py-2">Connect existing server</button></li>
                </ul>
                <form wire:submit="store">
                    <div x-show="tab === 'digitalocean'">
                        @if ($credentials->isEmpty())
                            <div class="text-amber-800 bg-amber-50 border border-amber-200 p-4 rounded-lg mb-4">
                                <p class="font-medium">Add a credential first.</p>
                                <p class="mt-1 text-sm">Add a DigitalOcean API token in Credentials, then return here. <a href="{{ route('credentials.index') }}" class="underline font-medium">Go to Credentials</a> · <a href="{{ route('docs.connect-provider') }}" class="underline font-medium">Connect a cloud provider (guide)</a></p>
                            </div>
                        @endif
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="provider_credential_id" value="Account" />
                                <select wire:model="provider_credential_id" id="provider_credential_id" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" required x-bind:disabled="tab !== 'digitalocean'">
                                    <option value="">Select account</option>
                                    @foreach ($credentials as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="name" value="Server name" />
                                <x-text-input id="name" wire:model="name" type="text" class="mt-1 block w-full" required x-bind:disabled="tab !== 'digitalocean'" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="region" value="Region" />
                                <select wire:model="region" id="region" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" required x-bind:disabled="tab !== 'digitalocean'">
                                    @foreach ($regions as $r)
                                        <option value="{{ $r['slug'] ?? $r['id'] ?? '' }}">{{ $r['name'] ?? $r['slug'] ?? '' }} ({{ $r['slug'] ?? $r['id'] ?? '' }})</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('region')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="size" value="Size" />
                                <select wire:model="size" id="size" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" required x-bind:disabled="tab !== 'digitalocean'">
                                    @foreach ($sizes as $s)
                                        <option value="{{ $s['slug'] ?? $s['id'] ?? '' }}">{{ $s['slug'] ?? $s['id'] ?? '' }} — {{ $s['memory'] ?? 0 }}MB / {{ $s['vcpus'] ?? 0 }} vCPU</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('size')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="setup_script_key" value="Setup script (optional)" />
                                <select wire:model="setup_script_key" id="setup_script_key" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'digitalocean'">
                                    <option value="">None</option>
                                    @foreach ($setupScripts as $key => $script)
                                        <option value="{{ $key }}">{{ $script['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('setup_script_key')" class="mt-1" />
                            </div>
                        </div>
                    </div>
                    <div x-show="tab === 'hetzner'" style="display: none;" x-cloak>
                        @if ($hetznerCredentials->isEmpty())
                            <div class="text-amber-800 bg-amber-50 border border-amber-200 p-4 rounded-lg mb-4">
                                <p class="font-medium">Add a credential first.</p>
                                <p class="mt-1 text-sm">Add a Hetzner API token in Credentials, then return here. <a href="{{ route('credentials.index') }}" class="underline font-medium">Go to Credentials</a> · <a href="{{ route('docs.connect-provider') }}" class="underline font-medium">Connect a cloud provider (guide)</a></p>
                            </div>
                        @endif
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="hetzner_provider_credential_id" value="Account" />
                                <select wire:model="provider_credential_id" id="hetzner_provider_credential_id" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'hetzner'">
                                    <option value="">Select account</option>
                                    @foreach ($hetznerCredentials as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="hetzner_name" value="Server name" />
                                <x-text-input id="hetzner_name" wire:model="name" type="text" class="mt-1 block w-full" x-bind:disabled="tab !== 'hetzner'" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="hetzner_region" value="Location" />
                                <select wire:model="region" id="hetzner_region" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'hetzner'">
                                    @foreach ($hetznerLocations as $loc)
                                        <option value="{{ $loc['name'] ?? $loc['id'] ?? '' }}">{{ $loc['description'] ?? $loc['name'] ?? $loc['id'] }} ({{ $loc['name'] ?? $loc['id'] }})</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('region')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="hetzner_size" value="Server type" />
                                <select wire:model="size" id="hetzner_size" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'hetzner'">
                                    @foreach ($hetznerSizes as $st)
                                        <option value="{{ $st['name'] ?? '' }}">{{ $st['name'] ?? '' }} — {{ $st['memory'] ?? 0 }}GB / {{ $st['cores'] ?? 0 }} vCPU</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('size')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="hetzner_setup_script_key" value="Setup script (optional)" />
                                <select wire:model="setup_script_key" id="hetzner_setup_script_key" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'hetzner'">
                                    <option value="">None</option>
                                    @foreach ($setupScripts as $key => $script)
                                        <option value="{{ $key }}">{{ $script['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('setup_script_key')" class="mt-1" />
                            </div>
                        </div>
                    </div>
                    <div x-show="tab === 'linode'" style="display: none;" x-cloak>
                        @if ($linodeCredentials->isEmpty())
                            <div class="text-amber-800 bg-amber-50 border border-amber-200 p-4 rounded-lg mb-4">
                                <p class="font-medium">Add a credential first.</p>
                                <p class="mt-1 text-sm">Add a Linode API token in Credentials, then return here. <a href="{{ route('credentials.index') }}" class="underline font-medium">Go to Credentials</a></p>
                            </div>
                        @endif
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="linode_provider_credential_id" value="Account" />
                                <select wire:model="provider_credential_id" id="linode_provider_credential_id" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'linode'">
                                    <option value="">Select account</option>
                                    @foreach ($linodeCredentials as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="linode_name" value="Server name" />
                                <x-text-input id="linode_name" wire:model="name" type="text" class="mt-1 block w-full" x-bind:disabled="tab !== 'linode'" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="linode_region" value="Region" />
                                <select wire:model="region" id="linode_region" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'linode'">
                                    @foreach ($linodeRegions as $reg)
                                        <option value="{{ $reg['id'] ?? '' }}">{{ $reg['label'] ?? $reg['id'] ?? '' }} ({{ $reg['id'] ?? '' }})</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('region')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="linode_size" value="Type" />
                                <select wire:model="size" id="linode_size" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'linode'">
                                    @foreach ($linodeTypes as $t)
                                        <option value="{{ $t['id'] ?? '' }}">{{ $t['label'] ?? $t['id'] ?? '' }} — {{ ($t['memory'] ?? 0) / 1024 }}GB / {{ $t['vcpus'] ?? 0 }} vCPU</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('size')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="linode_setup_script_key" value="Setup script (optional)" />
                                <select wire:model="setup_script_key" id="linode_setup_script_key" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'linode'">
                                    <option value="">None</option>
                                    @foreach ($setupScripts as $key => $script)
                                        <option value="{{ $key }}">{{ $script['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('setup_script_key')" class="mt-1" />
                            </div>
                        </div>
                    </div>
                    <div x-show="tab === 'vultr'" style="display: none;" x-cloak>
                        @if ($vultrCredentials->isEmpty())
                            <div class="text-amber-800 bg-amber-50 border border-amber-200 p-4 rounded-lg mb-4">
                                <p class="font-medium">Add a credential first.</p>
                                <p class="mt-1 text-sm">Add a Vultr API token in Credentials, then return here. <a href="{{ route('credentials.index') }}" class="underline font-medium">Go to Credentials</a></p>
                            </div>
                        @endif
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="vultr_provider_credential_id" value="Account" />
                                <select wire:model="provider_credential_id" id="vultr_provider_credential_id" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'vultr'">
                                    <option value="">Select account</option>
                                    @foreach ($vultrCredentials as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="vultr_name" value="Server name" />
                                <x-text-input id="vultr_name" wire:model="name" type="text" class="mt-1 block w-full" x-bind:disabled="tab !== 'vultr'" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="vultr_region" value="Region" />
                                <select wire:model="region" id="vultr_region" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'vultr'">
                                    @foreach ($vultrRegions as $reg)
                                        <option value="{{ $reg['id'] ?? '' }}">{{ $reg['city'] ?? $reg['id'] ?? '' }} ({{ $reg['id'] ?? '' }})</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('region')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="vultr_size" value="Plan" />
                                <select wire:model="size" id="vultr_size" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'vultr'">
                                    @foreach ($vultrPlans as $p)
                                        <option value="{{ $p['id'] ?? '' }}">{{ $p['id'] ?? '' }} — {{ ($p['ram'] ?? 0) }}MB / {{ $p['vcpu_count'] ?? 0 }} vCPU</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('size')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="vultr_setup_script_key" value="Setup script (optional)" />
                                <select wire:model="setup_script_key" id="vultr_setup_script_key" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'vultr'">
                                    <option value="">None</option>
                                    @foreach ($setupScripts as $key => $script)
                                        <option value="{{ $key }}">{{ $script['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('setup_script_key')" class="mt-1" />
                            </div>
                        </div>
                    </div>
                    <div x-show="tab === 'akamai'" style="display: none;" x-cloak>
                        @if ($akamaiCredentials->isEmpty())
                            <div class="text-amber-800 bg-amber-50 border border-amber-200 p-4 rounded-lg mb-4">
                                <p class="font-medium">Add a credential first.</p>
                                <p class="mt-1 text-sm">Add an Akamai (Linode API) token in Credentials, then return here. <a href="{{ route('credentials.index') }}" class="underline font-medium">Go to Credentials</a></p>
                            </div>
                        @endif
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="akamai_provider_credential_id" value="Account" />
                                <select wire:model="provider_credential_id" id="akamai_provider_credential_id" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'akamai'">
                                    <option value="">Select account</option>
                                    @foreach ($akamaiCredentials as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="akamai_name" value="Server name" />
                                <x-text-input id="akamai_name" wire:model="name" type="text" class="mt-1 block w-full" x-bind:disabled="tab !== 'akamai'" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="akamai_region" value="Region" />
                                <select wire:model="region" id="akamai_region" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'akamai'">
                                    @foreach ($akamaiRegions as $reg)
                                        <option value="{{ $reg['id'] ?? '' }}">{{ $reg['label'] ?? $reg['id'] ?? '' }} ({{ $reg['id'] ?? '' }})</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('region')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="akamai_size" value="Type" />
                                <select wire:model="size" id="akamai_size" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'akamai'">
                                    @foreach ($akamaiTypes as $t)
                                        <option value="{{ $t['id'] ?? '' }}">{{ $t['label'] ?? $t['id'] ?? '' }} — {{ ($t['memory'] ?? 0) / 1024 }}GB / {{ $t['vcpus'] ?? 0 }} vCPU</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('size')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="akamai_setup_script_key" value="Setup script (optional)" />
                                <select wire:model="setup_script_key" id="akamai_setup_script_key" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'akamai'">
                                    <option value="">None</option>
                                    @foreach ($setupScripts as $key => $script)
                                        <option value="{{ $key }}">{{ $script['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('setup_script_key')" class="mt-1" />
                            </div>
                        </div>
                    </div>
                    <div x-show="tab === 'scaleway'" style="display: none;" x-cloak>
                        @if ($scalewayCredentials->isEmpty())
                            <div class="text-amber-800 bg-amber-50 border border-amber-200 p-4 rounded-lg mb-4">
                                <p class="font-medium">Add a credential first.</p>
                                <p class="mt-1 text-sm">Add a Scaleway secret key and Project ID in Credentials, then return here. <a href="{{ route('credentials.index') }}" class="underline font-medium">Go to Credentials</a></p>
                            </div>
                        @endif
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="scaleway_provider_credential_id" value="Account" />
                                <select wire:model="provider_credential_id" id="scaleway_provider_credential_id" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'scaleway'">
                                    <option value="">Select account</option>
                                    @foreach ($scalewayCredentials as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="scaleway_name" value="Server name" />
                                <x-text-input id="scaleway_name" wire:model="name" type="text" class="mt-1 block w-full" x-bind:disabled="tab !== 'scaleway'" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="scaleway_zone" value="Zone" />
                                <select wire:model="region" id="scaleway_zone" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'scaleway'">
                                    @foreach ($scalewayZones as $z)
                                        <option value="{{ $z['id'] ?? '' }}">{{ $z['name'] ?? $z['id'] ?? '' }} ({{ $z['id'] ?? '' }})</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('region')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="scaleway_type" value="Type" />
                                <select wire:model="size" id="scaleway_type" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'scaleway'">
                                    @foreach ($scalewayTypes as $t)
                                        <option value="{{ $t['name'] ?? $t['id'] ?? '' }}">{{ $t['name'] ?? $t['id'] ?? '' }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('size')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="scaleway_setup_script_key" value="Setup script (optional)" />
                                <select wire:model="setup_script_key" id="scaleway_setup_script_key" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'scaleway'">
                                    <option value="">None</option>
                                    @foreach ($setupScripts as $key => $script)
                                        <option value="{{ $key }}">{{ $script['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('setup_script_key')" class="mt-1" />
                            </div>
                        </div>
                    </div>
                    <div x-show="tab === 'upcloud'" style="display: none;" x-cloak>
                        @if ($upcloudCredentials->isEmpty())
                            <div class="text-amber-800 bg-amber-50 border border-amber-200 p-4 rounded-lg mb-4">
                                <p class="font-medium">Add a credential first.</p>
                                <p class="mt-1 text-sm">Add UpCloud API username and password in Credentials, then return here. <a href="{{ route('credentials.index') }}" class="underline font-medium">Go to Credentials</a></p>
                            </div>
                        @endif
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="upcloud_provider_credential_id" value="Account" />
                                <select wire:model="provider_credential_id" id="upcloud_provider_credential_id" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'upcloud'">
                                    <option value="">Select account</option>
                                    @foreach ($upcloudCredentials as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="upcloud_name" value="Server name" />
                                <x-text-input id="upcloud_name" wire:model="name" type="text" class="mt-1 block w-full" x-bind:disabled="tab !== 'upcloud'" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="upcloud_zone" value="Zone" />
                                <select wire:model="region" id="upcloud_zone" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'upcloud'">
                                    @foreach ($upcloudZones as $z)
                                        <option value="{{ $z['id'] ?? '' }}">{{ $z['description'] ?? $z['id'] ?? '' }} ({{ $z['id'] ?? '' }})</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('region')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="upcloud_plan" value="Plan" />
                                <select wire:model="size" id="upcloud_plan" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'upcloud'">
                                    @foreach ($upcloudPlans as $p)
                                        <option value="{{ $p['name'] ?? '' }}">{{ $p['name'] ?? '' }} — {{ $p['core_number'] ?? 0 }} CPU / {{ $p['memory_amount'] ?? 0 }}MB</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('size')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="upcloud_setup_script_key" value="Setup script (optional)" />
                                <select wire:model="setup_script_key" id="upcloud_setup_script_key" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'upcloud'">
                                    <option value="">None</option>
                                    @foreach ($setupScripts as $key => $script)
                                        <option value="{{ $key }}">{{ $script['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('setup_script_key')" class="mt-1" />
                            </div>
                        </div>
                    </div>
                    <div x-show="tab === 'equinix_metal'" style="display: none;" x-cloak>
                        @if ($equinixMetalCredentials->isEmpty())
                            <div class="text-amber-800 bg-amber-50 border border-amber-200 p-4 rounded-lg mb-4">
                                <p class="font-medium">Add a credential first.</p>
                                <p class="mt-1 text-sm">Add an Equinix Metal API token and Project ID in Credentials, then return here. <a href="{{ route('credentials.index') }}" class="underline font-medium">Go to Credentials</a></p>
                            </div>
                        @endif
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="equinix_metal_provider_credential_id" value="Account" />
                                <select wire:model="provider_credential_id" id="equinix_metal_provider_credential_id" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'equinix_metal'">
                                    <option value="">Select account</option>
                                    @foreach ($equinixMetalCredentials as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="equinix_metal_name" value="Server name" />
                                <x-text-input id="equinix_metal_name" wire:model="name" type="text" class="mt-1 block w-full" x-bind:disabled="tab !== 'equinix_metal'" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="equinix_metal_metro" value="Metro" />
                                <select wire:model="region" id="equinix_metal_metro" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'equinix_metal'">
                                    @foreach ($equinixMetalMetros as $m)
                                        <option value="{{ $m['code'] ?? $m['id'] ?? '' }}">{{ $m['name'] ?? $m['code'] ?? '' }} ({{ $m['code'] ?? $m['id'] ?? '' }})</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('region')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="equinix_metal_plan" value="Plan" />
                                <select wire:model="size" id="equinix_metal_plan" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'equinix_metal'">
                                    @foreach ($equinixMetalPlans as $p)
                                        <option value="{{ $p['slug'] ?? $p['id'] ?? '' }}">{{ $p['name'] ?? $p['slug'] ?? '' }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('size')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="equinix_metal_setup_script_key" value="Setup script (optional)" />
                                <select wire:model="setup_script_key" id="equinix_metal_setup_script_key" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'equinix_metal'">
                                    <option value="">None</option>
                                    @foreach ($setupScripts as $key => $script)
                                        <option value="{{ $key }}">{{ $script['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('setup_script_key')" class="mt-1" />
                            </div>
                        </div>
                    </div>
                    <div x-show="tab === 'fly_io'" style="display: none;" x-cloak>
                        @if ($flyIoCredentials->isEmpty())
                            <div class="text-amber-800 bg-amber-50 border border-amber-200 p-4 rounded-lg mb-4">
                                <p class="font-medium">Add a credential first.</p>
                                <p class="mt-1 text-sm">Add a Fly.io API token and org slug in Credentials, then return here. <a href="{{ route('credentials.index') }}" class="underline font-medium">Go to Credentials</a></p>
                            </div>
                        @endif
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="fly_io_provider_credential_id" value="Account" />
                                <select wire:model="provider_credential_id" id="fly_io_provider_credential_id" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'fly_io'">
                                    <option value="">Select account</option>
                                    @foreach ($flyIoCredentials as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="fly_io_name" value="Machine name" />
                                <x-text-input id="fly_io_name" wire:model="name" type="text" class="mt-1 block w-full" x-bind:disabled="tab !== 'fly_io'" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="fly_io_region" value="Region" />
                                <select wire:model="region" id="fly_io_region" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'fly_io'">
                                    @foreach ($flyIoRegions as $r)
                                        <option value="{{ $r['id'] }}">{{ $r['name'] }} ({{ $r['id'] }})</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('region')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="fly_io_size" value="VM size" />
                                <select wire:model="size" id="fly_io_size" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'fly_io'">
                                    @foreach ($flyIoVmSizes as $s)
                                        <option value="{{ $s['id'] }}">{{ $s['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('size')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="fly_io_setup_script_key" value="Setup script (optional)" />
                                <select wire:model="setup_script_key" id="fly_io_setup_script_key" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'fly_io'">
                                    <option value="">None</option>
                                    @foreach ($setupScripts as $key => $script)
                                        <option value="{{ $key }}">{{ $script['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('setup_script_key')" class="mt-1" />
                            </div>
                        </div>
                    </div>
                    <div x-show="tab === 'aws'" style="display: none;" x-cloak>
                        @if ($awsCredentials->isEmpty())
                            <div class="text-amber-800 bg-amber-50 border border-amber-200 p-4 rounded-lg mb-4">
                                <p class="font-medium">Add a credential first.</p>
                                <p class="mt-1 text-sm">Add AWS access key and secret in Credentials, then return here. <a href="{{ route('credentials.index') }}" class="underline font-medium">Go to Credentials</a></p>
                            </div>
                        @endif
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="aws_provider_credential_id" value="Account" />
                                <select wire:model="provider_credential_id" id="aws_provider_credential_id" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'aws'">
                                    <option value="">Select account</option>
                                    @foreach ($awsCredentials as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="aws_name" value="Server name" />
                                <x-text-input id="aws_name" wire:model="name" type="text" class="mt-1 block w-full" x-bind:disabled="tab !== 'aws'" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="aws_region" value="Region" />
                                <select wire:model="region" id="aws_region" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'aws'">
                                    @foreach ($awsRegions as $r)
                                        <option value="{{ $r['id'] }}">{{ $r['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('region')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="aws_size" value="Instance type" />
                                <select wire:model="size" id="aws_size" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'aws'">
                                    @foreach ($awsInstanceTypes as $s)
                                        <option value="{{ $s['id'] }}">{{ $s['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('size')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="aws_setup_script_key" value="Setup script (optional)" />
                                <select wire:model="setup_script_key" id="aws_setup_script_key" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm" x-bind:disabled="tab !== 'aws'">
                                    <option value="">None</option>
                                    @foreach ($setupScripts as $key => $script)
                                        <option value="{{ $key }}">{{ $script['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('setup_script_key')" class="mt-1" />
                            </div>
                        </div>
                    </div>
                    <div x-show="tab === 'custom'" style="display: none;" x-cloak>
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="custom_name" value="Server name" />
                                <x-text-input id="custom_name" wire:model="name" type="text" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="ip_address" value="IP address" />
                                <x-text-input id="ip_address" wire:model="ip_address" type="text" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('ip_address')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="ssh_port" value="SSH port" />
                                <x-text-input id="ssh_port" wire:model="ssh_port" type="number" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('ssh_port')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="ssh_user" value="SSH user" />
                                <x-text-input id="ssh_user" wire:model="ssh_user" type="text" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('ssh_user')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="ssh_private_key" value="SSH private key (PEM / OpenSSH)" />
                                <textarea id="ssh_private_key" wire:model="ssh_private_key" rows="6" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm font-mono text-sm"></textarea>
                                <x-input-error :messages="$errors->get('ssh_private_key')" class="mt-1" />
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 flex gap-3">
                        <x-primary-button type="submit">Add server</x-primary-button>
                        <a href="{{ route('servers.index') }}" class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest shadow-sm hover:bg-slate-50">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
