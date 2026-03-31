@php
    $link = 'text-brand-sage hover:text-brand-ink underline underline-offset-2';
    $hint = 'mt-1 text-sm text-brand-moss leading-relaxed';
    $code = 'rounded-md bg-brand-sand/60 px-1.5 py-0.5 text-xs font-mono text-brand-ink';
@endphp

@if (session('success') || $flash_success)
    <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">{{ $flash_success ?? session('success') }}</div>
@endif
@if (session('error') || $flash_error)
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ $flash_error ?? session('error') }}</div>
@endif

<section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-brand-ink/10 bg-brand-cream/50 flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Saved in this organization') }}</h3>
        <span class="text-xs text-brand-moss">{{ __('Encrypted at rest') }}</span>
    </div>
    @if ($credentials->isEmpty())
        <p class="px-5 py-8 text-sm text-brand-moss text-center">{{ __('No credentials yet. Add a provider using the form below.') }}</p>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($credentials as $cred)
                <li class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between px-5 py-4" wire:key="cred-{{ $cred->id }}">
                    <div class="min-w-0">
                        <span class="font-medium text-brand-ink">{{ $cred->name }}</span>
                        <span class="text-brand-mist ml-2 font-mono text-xs">{{ $cred->provider }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 shrink-0">
                        @if ($this->canVerifyCredentialProvider($cred->provider))
                            <button
                                type="button"
                                wire:click="verifyCredential({{ $cred->id }})"
                                wire:loading.attr="disabled"
                                wire:target="verifyCredential"
                                class="text-sm font-medium text-brand-sage hover:text-brand-ink"
                            >
                                <span wire:loading.remove wire:target="verifyCredential">{{ __('Verify') }}</span>
                                <span wire:loading wire:target="verifyCredential" class="inline-flex items-center gap-2">
                                    <x-spinner variant="forest" size="sm" />
                                    {{ __('Verifying…') }}
                                </span>
                            </button>
                        @endif
                        <button type="button" wire:click="destroy({{ $cred->id }})" wire:confirm="{{ __('Remove this credential?') }}" class="text-sm font-medium text-red-700 hover:text-red-900">{{ __('Remove') }}</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>

@switch($active_provider)
    @case('digitalocean')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                @if (! empty($digitalOceanOAuthConfigured))
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 px-4 py-4 space-y-3">
                        <p class="text-sm text-brand-moss leading-relaxed">{{ __('Sign in with DigitalOcean to connect without pasting a personal access token. Requires a DigitalOcean OAuth app on this deployment.') }}</p>
                        @env('local')
                            <p class="text-xs text-brand-moss leading-relaxed">
                                {{ __('OAuth needs a URL DigitalOcean can redirect to. For local dev use a tunnel (e.g. :expose), set :app and :proxy in :env, and register the callback URL in your DO OAuth app.', [
                                    'expose' => 'Expose',
                                    'app' => 'APP_URL',
                                    'proxy' => 'TRUSTED_PROXIES=*',
                                    'env' => '.env',
                                ]) }}
                                {{ __('Creating droplets does not use inbound webhooks; an API token below works without a tunnel.') }}
                            </p>
                        @endenv
                        <a
                            href="{{ route('credentials.oauth.digitalocean.redirect') }}"
                            class="inline-flex items-center justify-center rounded-xl bg-[#0080FF] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#0066CC] transition-colors"
                        >{{ __('Continue with DigitalOcean') }}</a>
                    </div>
                    <p class="text-xs text-brand-mist text-center">{{ __('or use an API token') }}</p>
                @else
                    <p class="text-sm text-brand-moss leading-relaxed">{{ __('Paste a read/write token from DigitalOcean. We verify it before saving.') }}</p>
                @endif
                <form wire:submit="storeDigitalOcean" class="space-y-5">
                    <div>
                        <x-input-label for="do_name" :value="__('Label (optional)')" />
                        <x-text-input id="do_name" wire:model="do_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production billing') }}" />
                    </div>
                    <div>
                        <x-input-label for="do_api_token" :value="__('API token')" />
                        <x-text-input id="do_api_token" wire:model="do_api_token" type="password" class="mt-1 block w-full" placeholder="dop_v1_…" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token at :link.', ['link' => '<a href="https://cloud.digitalocean.com/account/api/tokens" target="_blank" rel="noopener" class="'.$link.'">DigitalOcean → API</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('do_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="storeDigitalOcean">{{ __('Connect DigitalOcean') }}</span>
                        <span wire:loading wire:target="storeDigitalOcean" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('hetzner')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeHetzner" class="space-y-5">
                    <div>
                        <x-input-label for="hetzner_name" :value="__('Label (optional)')" />
                        <x-text-input id="hetzner_name" wire:model="hetzner_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. EU project') }}" />
                    </div>
                    <div>
                        <x-input-label for="hetzner_api_token" :value="__('API token')" />
                        <x-text-input id="hetzner_api_token" wire:model="hetzner_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token in :link.', ['link' => '<a href="https://console.hetzner.cloud/" target="_blank" rel="noopener" class="'.$link.'">Hetzner Cloud Console → Security → API Tokens</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('hetzner_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="storeHetzner">{{ __('Connect Hetzner') }}</span>
                        <span wire:loading wire:target="storeHetzner" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('linode')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeLinode" class="space-y-5">
                    <div>
                        <x-input-label for="linode_name" :value="__('Label (optional)')" />
                        <x-text-input id="linode_name" wire:model="linode_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="linode_api_token" :value="__('API token')" />
                        <x-text-input id="linode_api_token" wire:model="linode_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token in :link.', ['link' => '<a href="https://cloud.linode.com/profile/tokens" target="_blank" rel="noopener" class="'.$link.'">Linode Cloud Manager → Profile → API Tokens</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('linode_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="storeLinode">{{ __('Connect Linode') }}</span>
                        <span wire:loading wire:target="storeLinode" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('vultr')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeVultr" class="space-y-5">
                    <div>
                        <x-input-label for="vultr_name" :value="__('Label (optional)')" />
                        <x-text-input id="vultr_name" wire:model="vultr_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="vultr_api_token" :value="__('API token')" />
                        <x-text-input id="vultr_api_token" wire:model="vultr_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token in :link.', ['link' => '<a href="https://my.vultr.com/settings/#settingsapi" target="_blank" rel="noopener" class="'.$link.'">Vultr → Account → API</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('vultr_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="storeVultr">{{ __('Connect Vultr') }}</span>
                        <span wire:loading wire:target="storeVultr" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('akamai')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <p class="text-sm text-brand-moss leading-relaxed">{{ __('Uses the same API as Linode. Your Linode Cloud token works here.') }}</p>
                <form wire:submit="storeAkamai" class="space-y-5">
                    <div>
                        <x-input-label for="akamai_name" :value="__('Label (optional)')" />
                        <x-text-input id="akamai_name" wire:model="akamai_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="akamai_api_token" :value="__('API token')" />
                        <x-text-input id="akamai_api_token" wire:model="akamai_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Use your Linode Cloud token. :link', ['link' => '<a href="https://cloud.linode.com/profile/tokens" target="_blank" rel="noopener" class="'.$link.'">Linode → Profile → API Tokens</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('akamai_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="storeAkamai">{{ __('Connect Akamai') }}</span>
                        <span wire:loading wire:target="storeAkamai" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('equinix_metal')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeEquinixMetal" class="space-y-5">
                    <div>
                        <x-input-label for="equinix_metal_name" :value="__('Label (optional)')" />
                        <x-text-input id="equinix_metal_name" wire:model="equinix_metal_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="equinix_metal_api_token" :value="__('API token')" />
                        <x-text-input id="equinix_metal_api_token" wire:model="equinix_metal_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token: :link', ['link' => '<a href="https://cloud.equinix.com/developers/api" target="_blank" rel="noopener" class="'.$link.'">Equinix Metal → API</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('equinix_metal_api_token')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="equinix_metal_project_id" :value="__('Project ID')" />
                        <x-text-input id="equinix_metal_project_id" wire:model="equinix_metal_project_id" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="{{ __('UUID') }}" required />
                        <x-input-error :messages="$errors->get('equinix_metal_project_id')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="storeEquinixMetal">{{ __('Connect Equinix Metal') }}</span>
                        <span wire:loading wire:target="storeEquinixMetal" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('upcloud')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeUpCloud" class="space-y-5">
                    <div>
                        <x-input-label for="upcloud_name" :value="__('Label (optional)')" />
                        <x-text-input id="upcloud_name" wire:model="upcloud_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="upcloud_username" :value="__('API username')" />
                        <x-text-input id="upcloud_username" wire:model="upcloud_username" type="text" class="mt-1 block w-full" required autocomplete="username" />
                        <p class="{{ $hint }}">{!! __('From :link.', ['link' => '<a href="https://hub.upcloud.com/account" target="_blank" rel="noopener" class="'.$link.'">UpCloud Hub → Account</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('upcloud_username')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="upcloud_password" :value="__('API password')" />
                        <x-text-input id="upcloud_password" wire:model="upcloud_password" type="password" class="mt-1 block w-full" required autocomplete="current-password" />
                        <x-input-error :messages="$errors->get('upcloud_password')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="storeUpCloud">{{ __('Connect UpCloud') }}</span>
                        <span wire:loading wire:target="storeUpCloud" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('scaleway')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeScaleway" class="space-y-5">
                    <div>
                        <x-input-label for="scaleway_name" :value="__('Label (optional)')" />
                        <x-text-input id="scaleway_name" wire:model="scaleway_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="scaleway_api_token" :value="__('Secret key')" />
                        <x-text-input id="scaleway_api_token" wire:model="scaleway_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create an API key: :link', ['link' => '<a href="https://console.scaleway.com/iam/credentials" target="_blank" rel="noopener" class="'.$link.'">Scaleway Console → IAM → API Keys</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('scaleway_api_token')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="scaleway_project_id" :value="__('Project ID')" />
                        <x-text-input id="scaleway_project_id" wire:model="scaleway_project_id" type="text" class="mt-1 block w-full font-mono text-sm" required />
                        <x-input-error :messages="$errors->get('scaleway_project_id')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="storeScaleway">{{ __('Connect Scaleway') }}</span>
                        <span wire:loading wire:target="storeScaleway" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('ovh')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="rounded-xl border border-amber-200/80 bg-amber-50/80 px-4 py-3 text-sm text-amber-950">
                    {{ __('Credential is stored for future use. Automated server creation via this provider is not available yet.') }}
                </div>
                <form wire:submit="storeOvh" class="space-y-5">
                    <div>
                        <x-input-label for="ovh_name" :value="__('Label (optional)')" />
                        <x-text-input id="ovh_name" wire:model="ovh_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="ovh_api_token" :value="__('API token')" />
                        <x-text-input id="ovh_api_token" wire:model="ovh_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('ovh_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save credential') }}</x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('rackspace')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="rounded-xl border border-amber-200/80 bg-amber-50/80 px-4 py-3 text-sm text-amber-950">
                    {{ __('Credential is stored for future use. Automated server creation via this provider is not available yet.') }}
                </div>
                <form wire:submit="storeRackspace" class="space-y-5">
                    <div>
                        <x-input-label for="rackspace_name" :value="__('Label (optional)')" />
                        <x-text-input id="rackspace_name" wire:model="rackspace_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="rackspace_api_token" :value="__('API key')" />
                        <x-text-input id="rackspace_api_token" wire:model="rackspace_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('rackspace_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save credential') }}</x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('fly_io')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeFlyIo" class="space-y-5">
                    <div>
                        <x-input-label for="fly_io_name" :value="__('Label (optional)')" />
                        <x-text-input id="fly_io_name" wire:model="fly_io_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="fly_io_api_token" :value="__('API token')" />
                        <x-text-input id="fly_io_api_token" wire:model="fly_io_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Use :cmd or :link.', ['cmd' => '<code class="'.$code.'">fly tokens create</code>', 'link' => '<a href="https://fly.io/dashboard" target="_blank" rel="noopener" class="'.$link.'">Fly.io Dashboard → Tokens</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('fly_io_api_token')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="fly_io_org_slug" :value="__('Organization slug')" />
                        <x-text-input id="fly_io_org_slug" wire:model="fly_io_org_slug" type="text" class="mt-1 block w-full" placeholder="personal" required />
                        <x-input-error :messages="$errors->get('fly_io_org_slug')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="storeFlyIo">{{ __('Connect Fly.io') }}</span>
                        <span wire:loading wire:target="storeFlyIo" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('render')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <p class="text-sm text-brand-moss">{{ __('Saved for future integrations. Not used for VM provisioning in Dply today.') }}</p>
                <form wire:submit="storeRender" class="space-y-5">
                    <div>
                        <x-input-label for="render_name" :value="__('Label (optional)')" />
                        <x-text-input id="render_name" wire:model="render_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="render_api_token" :value="__('API key')" />
                        <x-text-input id="render_api_token" wire:model="render_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save') }}</x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('railway')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <p class="text-sm text-brand-moss">{{ __('Saved for future integrations.') }}</p>
                <form wire:submit="storeRailway" class="space-y-5">
                    <div>
                        <x-input-label for="railway_name" :value="__('Label (optional)')" />
                        <x-text-input id="railway_name" wire:model="railway_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="railway_api_token" :value="__('API token')" />
                        <x-text-input id="railway_api_token" wire:model="railway_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save') }}</x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('coolify')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeCoolify" class="space-y-5">
                    <div>
                        <x-input-label for="coolify_name" :value="__('Label (optional)')" />
                        <x-text-input id="coolify_name" wire:model="coolify_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="coolify_api_url" :value="__('Coolify URL')" />
                        <x-text-input id="coolify_api_url" wire:model="coolify_api_url" type="url" class="mt-1 block w-full font-mono text-sm" placeholder="https://coolify.example.com" required />
                        <x-input-error :messages="$errors->get('coolify_api_url')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="coolify_api_token" :value="__('API token')" />
                        <x-text-input id="coolify_api_token" wire:model="coolify_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('coolify_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save') }}</x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('cap_rover')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeCapRover" class="space-y-5">
                    <div>
                        <x-input-label for="cap_rover_name" :value="__('Label (optional)')" />
                        <x-text-input id="cap_rover_name" wire:model="cap_rover_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="cap_rover_api_url" :value="__('Captain URL')" />
                        <x-text-input id="cap_rover_api_url" wire:model="cap_rover_api_url" type="url" class="mt-1 block w-full font-mono text-sm" placeholder="https://captain.example.com" required />
                        <x-input-error :messages="$errors->get('cap_rover_api_url')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="cap_rover_api_token" :value="__('API token')" />
                        <x-text-input id="cap_rover_api_token" wire:model="cap_rover_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('cap_rover_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save') }}</x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('aws')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeAws" class="space-y-5">
                    <div>
                        <x-input-label for="aws_name" :value="__('Label (optional)')" />
                        <x-text-input id="aws_name" wire:model="aws_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="aws_access_key_id" :value="__('Access key ID')" />
                        <x-text-input id="aws_access_key_id" wire:model="aws_access_key_id" type="text" class="mt-1 block w-full font-mono text-sm" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('aws_access_key_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="aws_secret_access_key" :value="__('Secret access key')" />
                        <x-text-input id="aws_secret_access_key" wire:model="aws_secret_access_key" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('aws_secret_access_key')" class="mt-2" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save') }}</x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('gcp')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeGcp" class="space-y-5">
                    <div>
                        <x-input-label for="gcp_name" :value="__('Label (optional)')" />
                        <x-text-input id="gcp_name" wire:model="gcp_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="gcp_api_token" :value="__('API token / key material')" />
                        <x-text-input id="gcp_api_token" wire:model="gcp_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save') }}</x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('azure')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeAzure" class="space-y-5">
                    <div>
                        <x-input-label for="azure_name" :value="__('Label (optional)')" />
                        <x-text-input id="azure_name" wire:model="azure_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="azure_api_token" :value="__('API token')" />
                        <x-text-input id="azure_api_token" wire:model="azure_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save') }}</x-primary-button>
                </form>
            </div>
        </div>
        @break

    @case('oracle')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <form wire:submit="storeOracle" class="space-y-5">
                    <div>
                        <x-input-label for="oracle_name" :value="__('Label (optional)')" />
                        <x-text-input id="oracle_name" wire:model="oracle_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="oracle_api_token" :value="__('API token')" />
                        <x-text-input id="oracle_api_token" wire:model="oracle_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save') }}</x-primary-button>
                </form>
            </div>
        </div>
        @break

    @default
        <div class="rounded-2xl border border-brand-ink/10 bg-amber-50 px-4 py-3 text-sm text-amber-950">{{ __('Unknown provider. Choose another from the list.') }}</div>
@endswitch
