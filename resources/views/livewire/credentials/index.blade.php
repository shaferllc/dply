<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Cloud credentials') }}</h2>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <x-livewire-validation-errors />
            @if (session('success') || $flash_success)
                <div class="mb-4 p-4 rounded-md bg-green-50 text-green-800">{{ $flash_success ?? session('success') }}</div>
            @endif
            @if (session('error') || $flash_error)
                <div class="mb-4 p-4 rounded-md bg-red-50 text-red-800">{{ $flash_error ?? session('error') }}</div>
            @endif
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 mb-6">
                <h3 class="font-medium text-slate-900 mb-4">Add DigitalOcean</h3>
                <form wire:submit="storeDigitalOcean" class="space-y-4">
                    <div>
                        <x-input-label for="do_name" value="Label (optional)" />
                        <x-text-input id="do_name" wire:model="do_name" type="text" class="mt-1 block w-full" placeholder="e.g. Personal" />
                    </div>
                    <div>
                        <x-input-label for="do_api_token" value="API token" />
                        <x-text-input id="do_api_token" wire:model="do_api_token" type="password" class="mt-1 block w-full" placeholder="dop_v1_..." required />
                        <p class="mt-1 text-sm text-slate-500">Create a token at <a href="https://cloud.digitalocean.com/account/api/tokens" target="_blank" rel="noopener" class="text-slate-600 hover:underline">DigitalOcean → API</a>.</p>
                        <x-input-error :messages="$errors->get('do_api_token')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="storeDigitalOcean">Connect</span>
                    <span wire:loading wire:target="storeDigitalOcean">Connecting…</span>
                </x-primary-button>
                </form>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 mb-6">
                <h3 class="font-medium text-slate-900 mb-4">Add Hetzner</h3>
                <form wire:submit="storeHetzner" class="space-y-4">
                    <div>
                        <x-input-label for="hetzner_name" value="Label (optional)" />
                        <x-text-input id="hetzner_name" wire:model="hetzner_name" type="text" class="mt-1 block w-full" placeholder="e.g. Personal" />
                    </div>
                    <div>
                        <x-input-label for="hetzner_api_token" value="API token" />
                        <x-text-input id="hetzner_api_token" wire:model="hetzner_api_token" type="password" class="mt-1 block w-full" placeholder="Hetzner API token" required />
                        <p class="mt-1 text-sm text-slate-500">Create a token in <a href="https://console.hetzner.cloud/" target="_blank" rel="noopener" class="text-slate-600 hover:underline">Hetzner Cloud Console → Security → API Tokens</a>.</p>
                        <x-input-error :messages="$errors->get('hetzner_api_token')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="storeHetzner">Connect</span>
                    <span wire:loading wire:target="storeHetzner">Connecting…</span>
                </x-primary-button>
                </form>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 mb-6">
                <h3 class="font-medium text-slate-900 mb-4">Add Linode</h3>
                <form wire:submit="storeLinode" class="space-y-4">
                    <div>
                        <x-input-label for="linode_name" value="Label (optional)" />
                        <x-text-input id="linode_name" wire:model="linode_name" type="text" class="mt-1 block w-full" placeholder="e.g. Personal" />
                    </div>
                    <div>
                        <x-input-label for="linode_api_token" value="API token" />
                        <x-text-input id="linode_api_token" wire:model="linode_api_token" type="password" class="mt-1 block w-full" placeholder="Linode API token" required />
                        <p class="mt-1 text-sm text-slate-500">Create a token in <a href="https://cloud.linode.com/profile/tokens" target="_blank" rel="noopener" class="text-slate-600 hover:underline">Linode Cloud Manager → Profile → API Tokens</a>.</p>
                        <x-input-error :messages="$errors->get('linode_api_token')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="storeLinode">Connect</span>
                    <span wire:loading wire:target="storeLinode">Connecting…</span>
                </x-primary-button>
                </form>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 mb-6">
                <h3 class="font-medium text-slate-900 mb-4">Add Vultr</h3>
                <form wire:submit="storeVultr" class="space-y-4">
                    <div>
                        <x-input-label for="vultr_name" value="Label (optional)" />
                        <x-text-input id="vultr_name" wire:model="vultr_name" type="text" class="mt-1 block w-full" placeholder="e.g. Personal" />
                    </div>
                    <div>
                        <x-input-label for="vultr_api_token" value="API token" />
                        <x-text-input id="vultr_api_token" wire:model="vultr_api_token" type="password" class="mt-1 block w-full" placeholder="Vultr API token" required />
                        <p class="mt-1 text-sm text-slate-500">Create a token in <a href="https://my.vultr.com/settings/#settingsapi" target="_blank" rel="noopener" class="text-slate-600 hover:underline">Vultr → Account → API</a>.</p>
                        <x-input-error :messages="$errors->get('vultr_api_token')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="storeVultr">Connect</span>
                    <span wire:loading wire:target="storeVultr">Connecting…</span>
                </x-primary-button>
                </form>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 mb-6">
                <h3 class="font-medium text-slate-900 mb-4">Add Akamai (Linode API)</h3>
                <form wire:submit="storeAkamai" class="space-y-4">
                    <div>
                        <x-input-label for="akamai_name" value="Label (optional)" />
                        <x-text-input id="akamai_name" wire:model="akamai_name" type="text" class="mt-1 block w-full" placeholder="e.g. Personal" />
                    </div>
                    <div>
                        <x-input-label for="akamai_api_token" value="API token" />
                        <x-text-input id="akamai_api_token" wire:model="akamai_api_token" type="password" class="mt-1 block w-full" placeholder="Akamai/Linode API token" required />
                        <p class="mt-1 text-sm text-slate-500">Use your Linode Cloud API token (Akamai uses the same API). <a href="https://cloud.linode.com/profile/tokens" target="_blank" rel="noopener" class="text-slate-600 hover:underline">Linode → Profile → API Tokens</a>.</p>
                        <x-input-error :messages="$errors->get('akamai_api_token')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="storeAkamai">Connect</span>
                    <span wire:loading wire:target="storeAkamai">Connecting…</span>
                </x-primary-button>
                </form>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 mb-6">
                <h3 class="font-medium text-slate-900 mb-4">Add Equinix Metal</h3>
                <form wire:submit="storeEquinixMetal" class="space-y-4">
                    <div>
                        <x-input-label for="equinix_metal_name" value="Label (optional)" />
                        <x-text-input id="equinix_metal_name" wire:model="equinix_metal_name" type="text" class="mt-1 block w-full" placeholder="e.g. Personal" />
                    </div>
                    <div>
                        <x-input-label for="equinix_metal_api_token" value="API token" />
                        <x-text-input id="equinix_metal_api_token" wire:model="equinix_metal_api_token" type="password" class="mt-1 block w-full" placeholder="Metal API token" required />
                        <p class="mt-1 text-sm text-slate-500">Create a token in <a href="https://cloud.equinix.com/developers/api" target="_blank" rel="noopener" class="text-slate-600 hover:underline">Equinix Metal → API</a>.</p>
                        <x-input-error :messages="$errors->get('equinix_metal_api_token')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="equinix_metal_project_id" value="Project ID" />
                        <x-text-input id="equinix_metal_project_id" wire:model="equinix_metal_project_id" type="text" class="mt-1 block w-full" placeholder="UUID of your Metal project" required />
                        <x-input-error :messages="$errors->get('equinix_metal_project_id')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="storeEquinixMetal">Connect</span>
                    <span wire:loading wire:target="storeEquinixMetal">Connecting…</span>
                </x-primary-button>
                </form>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 mb-6">
                <h3 class="font-medium text-slate-900 mb-4">Add UpCloud</h3>
                <form wire:submit="storeUpCloud" class="space-y-4">
                    <div>
                        <x-input-label for="upcloud_name" value="Label (optional)" />
                        <x-text-input id="upcloud_name" wire:model="upcloud_name" type="text" class="mt-1 block w-full" placeholder="e.g. Personal" />
                    </div>
                    <div>
                        <x-input-label for="upcloud_username" value="API username" />
                        <x-text-input id="upcloud_username" wire:model="upcloud_username" type="text" class="mt-1 block w-full" required />
                        <p class="mt-1 text-sm text-slate-500">UpCloud API username (from <a href="https://hub.upcloud.com/account" target="_blank" rel="noopener" class="text-slate-600 hover:underline">Hub → Account</a>).</p>
                        <x-input-error :messages="$errors->get('upcloud_username')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="upcloud_password" value="API password" />
                        <x-text-input id="upcloud_password" wire:model="upcloud_password" type="password" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('upcloud_password')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="storeUpCloud">Connect</span>
                    <span wire:loading wire:target="storeUpCloud">Connecting…</span>
                </x-primary-button>
                </form>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 mb-6">
                <h3 class="font-medium text-slate-900 mb-4">Add Scaleway</h3>
                <form wire:submit="storeScaleway" class="space-y-4">
                    <div>
                        <x-input-label for="scaleway_name" value="Label (optional)" />
                        <x-text-input id="scaleway_name" wire:model="scaleway_name" type="text" class="mt-1 block w-full" placeholder="e.g. Personal" />
                    </div>
                    <div>
                        <x-input-label for="scaleway_api_token" value="Secret key" />
                        <x-text-input id="scaleway_api_token" wire:model="scaleway_api_token" type="password" class="mt-1 block w-full" placeholder="Scaleway API secret key" required />
                        <p class="mt-1 text-sm text-slate-500">Create an API key in <a href="https://console.scaleway.com/iam/credentials" target="_blank" rel="noopener" class="text-slate-600 hover:underline">Scaleway Console → IAM → API Keys</a>.</p>
                        <x-input-error :messages="$errors->get('scaleway_api_token')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="scaleway_project_id" value="Project ID" />
                        <x-text-input id="scaleway_project_id" wire:model="scaleway_project_id" type="text" class="mt-1 block w-full" placeholder="UUID of your project" required />
                        <x-input-error :messages="$errors->get('scaleway_project_id')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="storeScaleway">Connect</span>
                    <span wire:loading wire:target="storeScaleway">Connecting…</span>
                </x-primary-button>
                </form>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 mb-6">
                <h3 class="font-medium text-slate-900 mb-4">Add OVH Public Cloud</h3>
                <p class="text-sm text-slate-500 mb-4">Credentials are saved for future use. Server creation via API is not yet implemented.</p>
                <form wire:submit="storeOvh" class="space-y-4">
                    <div>
                        <x-input-label for="ovh_name" value="Label (optional)" />
                        <x-text-input id="ovh_name" wire:model="ovh_name" type="text" class="mt-1 block w-full" placeholder="e.g. Personal" />
                    </div>
                    <div>
                        <x-input-label for="ovh_api_token" value="API token" />
                        <x-text-input id="ovh_api_token" wire:model="ovh_api_token" type="password" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('ovh_api_token')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">Save credential</x-primary-button>
                </form>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 mb-6">
                <h3 class="font-medium text-slate-900 mb-4">Add Rackspace (OpenStack)</h3>
                <p class="text-sm text-slate-500 mb-4">Credentials are saved for future use. Server creation via API is not yet implemented.</p>
                <form wire:submit="storeRackspace" class="space-y-4">
                    <div>
                        <x-input-label for="rackspace_name" value="Label (optional)" />
                        <x-text-input id="rackspace_name" wire:model="rackspace_name" type="text" class="mt-1 block w-full" placeholder="e.g. Personal" />
                    </div>
                    <div>
                        <x-input-label for="rackspace_api_token" value="API key" />
                        <x-text-input id="rackspace_api_token" wire:model="rackspace_api_token" type="password" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('rackspace_api_token')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">Save credential</x-primary-button>
                </form>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 mb-6">
                <h3 class="font-medium text-slate-900 mb-4">Add Fly.io</h3>
                <form wire:submit="storeFlyIo" class="space-y-4">
                    <div>
                        <x-input-label for="fly_io_name" value="Label (optional)" />
                        <x-text-input id="fly_io_name" wire:model="fly_io_name" type="text" class="mt-1 block w-full" placeholder="e.g. Personal" />
                    </div>
                    <div>
                        <x-input-label for="fly_io_api_token" value="API token" />
                        <x-text-input id="fly_io_api_token" wire:model="fly_io_api_token" type="password" class="mt-1 block w-full" placeholder="Fly.io API token" required />
                        <p class="mt-1 text-sm text-slate-500">Create a token with <code class="text-xs bg-slate-100 px-1">fly tokens create</code> or in <a href="https://fly.io/dashboard" target="_blank" rel="noopener" class="text-slate-600 hover:underline">Fly.io Dashboard → Tokens</a>.</p>
                        <x-input-error :messages="$errors->get('fly_io_api_token')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="fly_io_org_slug" value="Organization slug" />
                        <x-text-input id="fly_io_org_slug" wire:model="fly_io_org_slug" type="text" class="mt-1 block w-full" placeholder="personal" required />
                        <p class="mt-1 text-sm text-slate-500">Your Fly.io org slug (e.g. <code class="text-xs bg-slate-100 px-1">personal</code>).</p>
                        <x-input-error :messages="$errors->get('fly_io_org_slug')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="storeFlyIo">Connect</span>
                        <span wire:loading wire:target="storeFlyIo">Connecting…</span>
                    </x-primary-button>
                </form>
            </div>
            <p class="text-sm text-slate-500 mb-2 mt-8">Other providers (credentials saved for future create/destroy)</p>
            <div class="grid gap-6 sm:grid-cols-2">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">Render</h3>
                    <form wire:submit="storeRender" class="space-y-4">
                        <x-text-input wire:model="render_name" type="text" class="mt-1 block w-full" placeholder="Label (optional)" />
                        <x-text-input wire:model="render_api_token" type="password" class="mt-1 block w-full" placeholder="API key" required />
                        <x-primary-button type="submit" wire:loading.attr="disabled">Save</x-primary-button>
                    </form>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">Railway</h3>
                    <form wire:submit="storeRailway" class="space-y-4">
                        <x-text-input wire:model="railway_name" type="text" class="mt-1 block w-full" placeholder="Label (optional)" />
                        <x-text-input wire:model="railway_api_token" type="password" class="mt-1 block w-full" placeholder="API token" required />
                        <x-primary-button type="submit" wire:loading.attr="disabled">Save</x-primary-button>
                    </form>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">Coolify</h3>
                    <form wire:submit="storeCoolify" class="space-y-4">
                        <x-text-input wire:model="coolify_name" type="text" class="mt-1 block w-full" placeholder="Label (optional)" />
                        <x-text-input wire:model="coolify_api_url" type="url" class="mt-1 block w-full" placeholder="https://coolify.example.com" required />
                        <x-text-input wire:model="coolify_api_token" type="password" class="mt-1 block w-full" placeholder="API token" required />
                        <x-primary-button type="submit" wire:loading.attr="disabled">Save</x-primary-button>
                    </form>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">CapRover</h3>
                    <form wire:submit="storeCapRover" class="space-y-4">
                        <x-text-input wire:model="cap_rover_name" type="text" class="mt-1 block w-full" placeholder="Label (optional)" />
                        <x-text-input wire:model="cap_rover_api_url" type="url" class="mt-1 block w-full" placeholder="https://captain.example.com" required />
                        <x-text-input wire:model="cap_rover_api_token" type="password" class="mt-1 block w-full" placeholder="API token" required />
                        <x-primary-button type="submit" wire:loading.attr="disabled">Save</x-primary-button>
                    </form>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">AWS</h3>
                    <form wire:submit="storeAws" class="space-y-4">
                        <x-text-input wire:model="aws_name" type="text" class="mt-1 block w-full" placeholder="Label (optional)" />
                        <x-text-input wire:model="aws_access_key_id" type="text" class="mt-1 block w-full" placeholder="Access key ID" required />
                        <x-text-input wire:model="aws_secret_access_key" type="password" class="mt-1 block w-full" placeholder="Secret access key" required />
                        <x-primary-button type="submit" wire:loading.attr="disabled">Save</x-primary-button>
                    </form>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">GCP</h3>
                    <form wire:submit="storeGcp" class="space-y-4">
                        <x-text-input wire:model="gcp_name" type="text" class="mt-1 block w-full" placeholder="Label (optional)" />
                        <x-text-input wire:model="gcp_api_token" type="password" class="mt-1 block w-full" placeholder="API token" required />
                        <x-primary-button type="submit" wire:loading.attr="disabled">Save</x-primary-button>
                    </form>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">Azure</h3>
                    <form wire:submit="storeAzure" class="space-y-4">
                        <x-text-input wire:model="azure_name" type="text" class="mt-1 block w-full" placeholder="Label (optional)" />
                        <x-text-input wire:model="azure_api_token" type="password" class="mt-1 block w-full" placeholder="API token" required />
                        <x-primary-button type="submit" wire:loading.attr="disabled">Save</x-primary-button>
                    </form>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">Oracle Cloud</h3>
                    <form wire:submit="storeOracle" class="space-y-4">
                        <x-text-input wire:model="oracle_name" type="text" class="mt-1 block w-full" placeholder="Label (optional)" />
                        <x-text-input wire:model="oracle_api_token" type="password" class="mt-1 block w-full" placeholder="API token" required />
                        <x-primary-button type="submit" wire:loading.attr="disabled">Save</x-primary-button>
                    </form>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-8">
                <h3 class="font-medium text-slate-900 p-4 border-b">Your credentials</h3>
                @if ($credentials->isEmpty())
                    <p class="p-6 text-slate-500 text-sm">No credentials yet. Add a cloud provider token above.</p>
                @else
                    <ul class="divide-y divide-slate-200">
                        @foreach ($credentials as $cred)
                            <li class="flex items-center justify-between px-6 py-4">
                                <div>
                                    <span class="font-medium">{{ $cred->name }}</span>
                                    <span class="text-slate-500 text-sm ml-2">{{ $cred->provider }}</span>
                                </div>
                                <button type="button" wire:click="destroy({{ $cred->id }})" wire:confirm="Remove this credential?" class="text-red-600 hover:underline text-sm">Remove</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
