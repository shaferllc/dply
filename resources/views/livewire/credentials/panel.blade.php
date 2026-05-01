@php
    $link = 'text-brand-sage hover:text-brand-ink underline underline-offset-2';
    $hint = 'mt-1 text-sm text-brand-moss leading-relaxed';
    $code = 'rounded-md bg-brand-sand/60 px-1.5 py-0.5 text-xs font-mono text-brand-ink';
@endphp

@if ($credentials->isNotEmpty())
    <section class="dply-card overflow-hidden">
        <div class="px-5 py-4 border-b border-brand-ink/10 bg-brand-cream/50 flex flex-wrap items-center justify-between gap-2">
            <h3 class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink">
                <x-heroicon-o-archive-box class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                {{ __('Saved in this organization') }}
            </h3>
            <span
                class="inline-flex max-w-[min(100%,18rem)] items-start gap-1.5 text-xs leading-snug text-brand-moss sm:text-end"
                title="{{ __('Tokens and keys are encrypted in the database before they are stored on disk (encryption at rest).') }}"
            >
                <x-heroicon-o-lock-closed class="mt-0.5 h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                <span>{{ __('Stored encrypted in our database') }}</span>
            </span>
        </div>
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($credentials as $cred)
                <li class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between px-5 py-4" wire:key="cred-{{ $cred->id }}">
                    <div class="min-w-0">
                        <span class="font-medium text-brand-ink">{{ $cred->name }}</span>
                        <span class="text-brand-mist ml-2 font-mono text-xs">{{ $cred->provider }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 shrink-0">
                        @if ($this->canVerifyCredentialProvider($cred->provider))
                            @php $verifyingThis = $verifyingCredentialId === (string) $cred->id; @endphp
                            <button
                                type="button"
                                wire:click="verifyCredential('{{ $cred->id }}')"
                                @if ($verifyingCredentialId !== null) disabled @endif
                                class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-sage hover:text-brand-ink disabled:pointer-events-none disabled:opacity-60"
                            >
                                @if ($verifyingThis)
                                    <span class="inline-flex items-center gap-2">
                                        <x-spinner variant="forest" size="sm" />
                                        {{ __('Verifying…') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5">
                                        <x-heroicon-o-check-circle class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                        {{ __('Verify with provider') }}
                                    </span>
                                @endif
                            </button>
                        @endif
                        <button type="button" wire:click="openConfirmActionModal('destroy', ['{{ $cred->id }}'], @js(__('Remove credential')), @js(__('Remove this credential?')), @js(__('Remove')), true)" class="inline-flex items-center gap-1.5 text-sm font-medium text-red-700 hover:text-red-900">
                            <x-heroicon-o-trash class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Remove') }}
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif

@switch($active_provider)
    @case('digitalocean')
        <div class="dply-card overflow-hidden">
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
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#0080FF] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#0066CC] transition-colors"
                        >
                            <x-heroicon-o-cloud class="h-4 w-4 shrink-0 opacity-95" aria-hidden="true" />
                            {{ __('Continue with DigitalOcean') }}
                        </a>
                    </div>
                    <p class="text-xs text-brand-mist text-center">{{ __('or use an API token') }}</p>
                @else
                    <p class="text-sm text-brand-moss leading-relaxed">{{ __('Paste a read/write token from DigitalOcean. We verify it before saving.') }}</p>
                @endif
                <div class="space-y-5">
                    <div>
                        <x-input-label for="do_name" class="flex items-center gap-2">
                            <x-heroicon-o-tag class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('Label (optional)') }}
                        </x-input-label>
                        <x-text-input id="do_name" wire:model="do_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production billing') }}" />
                    </div>
                    <div>
                        <x-input-label for="do_api_token" class="flex items-center gap-2">
                            <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('API token') }}
                        </x-input-label>
                        <x-text-input id="do_api_token" wire:model="do_api_token" type="password" class="mt-1 block w-full" placeholder="dop_v1_…" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token at :link.', ['link' => '<a href="https://cloud.digitalocean.com/account/api/tokens" target="_blank" rel="noopener" class="'.$link.'">DigitalOcean → API</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('do_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeDigitalOcean" wire:loading.attr="disabled" wire:target="storeDigitalOcean">
                        <span wire:loading.remove wire:target="storeDigitalOcean" class="inline-flex items-center justify-center gap-2">
                            <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Connect DigitalOcean') }}
                        </span>
                        <span wire:loading wire:target="storeDigitalOcean" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('cloudflare')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <p class="text-sm text-brand-moss leading-relaxed">
                    {{ __('Use an API token with Zone:DNS:Edit (and Zone:Zone:Read) for the zones Dply should manage. This is independent of where servers are hosted.') }}
                </p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="cloudflare_name" class="flex items-center gap-2">
                            <x-heroicon-o-tag class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('Label (optional)') }}
                        </x-input-label>
                        <x-text-input id="cloudflare_name" wire:model="cloudflare_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production DNS') }}" />
                    </div>
                    <div>
                        <x-input-label for="cloudflare_api_token" class="flex items-center gap-2">
                            <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('API token') }}
                        </x-input-label>
                        <x-text-input id="cloudflare_api_token" wire:model="cloudflare_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token in the :link with DNS permissions for your zones.', ['link' => '<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener" class="'.$link.'">Cloudflare dashboard</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('cloudflare_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeCloudflare" wire:loading.attr="disabled" wire:target="storeCloudflare">
                        <span wire:loading.remove wire:target="storeCloudflare" class="inline-flex items-center justify-center gap-2">
                            <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Connect Cloudflare') }}
                        </span>
                        <span wire:loading wire:target="storeCloudflare" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('hetzner')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
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
                    <x-primary-button type="button" wire:click="storeHetzner" wire:loading.attr="disabled" wire:target="storeHetzner">
                        <span wire:loading.remove wire:target="storeHetzner">{{ __('Connect Hetzner') }}</span>
                        <span wire:loading wire:target="storeHetzner" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('linode')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
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
                    <x-primary-button type="button" wire:click="storeLinode" wire:loading.attr="disabled" wire:target="storeLinode">
                        <span wire:loading.remove wire:target="storeLinode">{{ __('Connect Linode') }}</span>
                        <span wire:loading wire:target="storeLinode" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('vultr')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
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
                    <x-primary-button type="button" wire:click="storeVultr" wire:loading.attr="disabled" wire:target="storeVultr">
                        <span wire:loading.remove wire:target="storeVultr">{{ __('Connect Vultr') }}</span>
                        <span wire:loading wire:target="storeVultr" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('akamai')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <p class="text-sm text-brand-moss leading-relaxed">{{ __('Uses the same API as Linode. Your Linode Cloud token works here.') }}</p>
                <div class="space-y-5">
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
                    <x-primary-button type="button" wire:click="storeAkamai" wire:loading.attr="disabled" wire:target="storeAkamai">
                        <span wire:loading.remove wire:target="storeAkamai">{{ __('Connect Akamai') }}</span>
                        <span wire:loading wire:target="storeAkamai" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('equinix_metal')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
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
                    <x-primary-button type="button" wire:click="storeEquinixMetal" wire:loading.attr="disabled" wire:target="storeEquinixMetal">
                        <span wire:loading.remove wire:target="storeEquinixMetal">{{ __('Connect Equinix Metal') }}</span>
                        <span wire:loading wire:target="storeEquinixMetal" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('upcloud')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
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
                    <x-primary-button type="button" wire:click="storeUpCloud" wire:loading.attr="disabled" wire:target="storeUpCloud">
                        <span wire:loading.remove wire:target="storeUpCloud">{{ __('Connect UpCloud') }}</span>
                        <span wire:loading wire:target="storeUpCloud" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('scaleway')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
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
                    <x-primary-button type="button" wire:click="storeScaleway" wire:loading.attr="disabled" wire:target="storeScaleway">
                        <span wire:loading.remove wire:target="storeScaleway">{{ __('Connect Scaleway') }}</span>
                        <span wire:loading wire:target="storeScaleway" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('ovh')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="rounded-xl border border-amber-200/80 bg-amber-50/80 px-4 py-3 text-sm text-amber-950">
                    {{ __('Credential is stored for future use. Automated server creation via this provider is not available yet.') }}
                </div>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="ovh_name" :value="__('Label (optional)')" />
                        <x-text-input id="ovh_name" wire:model="ovh_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="ovh_api_token" :value="__('API token')" />
                        <x-text-input id="ovh_api_token" wire:model="ovh_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('ovh_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeOvh" wire:loading.attr="disabled" wire:target="storeOvh">{{ __('Save credential') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('rackspace')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="rounded-xl border border-amber-200/80 bg-amber-50/80 px-4 py-3 text-sm text-amber-950">
                    {{ __('Credential is stored for future use. Automated server creation via this provider is not available yet.') }}
                </div>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="rackspace_name" :value="__('Label (optional)')" />
                        <x-text-input id="rackspace_name" wire:model="rackspace_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="rackspace_api_token" :value="__('API key')" />
                        <x-text-input id="rackspace_api_token" wire:model="rackspace_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('rackspace_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeRackspace" wire:loading.attr="disabled" wire:target="storeRackspace">{{ __('Save credential') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('fly_io')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
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
                    <x-primary-button type="button" wire:click="storeFlyIo" wire:loading.attr="disabled" wire:target="storeFlyIo">
                        <span wire:loading.remove wire:target="storeFlyIo">{{ __('Connect Fly.io') }}</span>
                        <span wire:loading wire:target="storeFlyIo" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('render')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <p class="text-sm text-brand-moss">{{ __('Saved for future integrations. Not used for VM provisioning in Dply today.') }}</p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="render_name" :value="__('Label (optional)')" />
                        <x-text-input id="render_name" wire:model="render_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="render_api_token" :value="__('API key')" />
                        <x-text-input id="render_api_token" wire:model="render_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                    </div>
                    <x-primary-button type="button" wire:click="storeRender" wire:loading.attr="disabled" wire:target="storeRender">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('railway')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <p class="text-sm text-brand-moss">{{ __('Saved for future integrations.') }}</p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="railway_name" :value="__('Label (optional)')" />
                        <x-text-input id="railway_name" wire:model="railway_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="railway_api_token" :value="__('API token')" />
                        <x-text-input id="railway_api_token" wire:model="railway_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                    </div>
                    <x-primary-button type="button" wire:click="storeRailway" wire:loading.attr="disabled" wire:target="storeRailway">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('coolify')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
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
                    <x-primary-button type="button" wire:click="storeCoolify" wire:loading.attr="disabled" wire:target="storeCoolify">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('cap_rover')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
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
                    <x-primary-button type="button" wire:click="storeCapRover" wire:loading.attr="disabled" wire:target="storeCapRover">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('aws')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
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
                    <x-primary-button type="button" wire:click="storeAws" wire:loading.attr="disabled" wire:target="storeAws">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('gcp')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
                    <div>
                        <x-input-label for="gcp_name" :value="__('Label (optional)')" />
                        <x-text-input id="gcp_name" wire:model="gcp_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="gcp_api_token" :value="__('API token / key material')" />
                        <x-text-input id="gcp_api_token" wire:model="gcp_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                    </div>
                    <x-primary-button type="button" wire:click="storeGcp" wire:loading.attr="disabled" wire:target="storeGcp">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('azure')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
                    <div>
                        <x-input-label for="azure_name" :value="__('Label (optional)')" />
                        <x-text-input id="azure_name" wire:model="azure_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="azure_api_token" :value="__('API token')" />
                        <x-text-input id="azure_api_token" wire:model="azure_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                    </div>
                    <x-primary-button type="button" wire:click="storeAzure" wire:loading.attr="disabled" wire:target="storeAzure">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('oracle')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
                    <div>
                        <x-input-label for="oracle_name" :value="__('Label (optional)')" />
                        <x-text-input id="oracle_name" wire:model="oracle_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="oracle_api_token" :value="__('API token')" />
                        <x-text-input id="oracle_api_token" wire:model="oracle_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                    </div>
                    <x-primary-button type="button" wire:click="storeOracle" wire:loading.attr="disabled" wire:target="storeOracle">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @default
        <div class="rounded-2xl border border-brand-ink/10 bg-amber-50 px-4 py-3 text-sm text-amber-950">{{ __('Unknown provider. Choose another from the list.') }}</div>
@endswitch
