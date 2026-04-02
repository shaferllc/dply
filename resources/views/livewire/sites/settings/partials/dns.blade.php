<section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <form wire:submit="saveDnsSettings">
        <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <div class="border-b border-slate-200 bg-slate-50 p-6 lg:border-b-0 lg:border-r">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('DNS settings') }}</h2>
                <p class="mt-3 text-sm leading-6 text-slate-600">
                    {{ __('Pick which connected DNS credential Dply should use for this site. That can differ from where the server is hosted—for example DigitalOcean compute with Cloudflare DNS. Set the DNS zone (apex) that exists in that provider account. Leave the zone empty to use the app default testing-domain pool instead.') }}
                </p>
                <p class="mt-4 text-sm">
                    <a href="{{ route('credentials.index') }}" wire:navigate class="font-medium text-sky-700 hover:text-sky-900">
                        {{ __('Go to server providers to connect DNS-capable credentials') }} &raquo;
                    </a>
                </p>
            </div>

            <div class="space-y-5 p-6 sm:p-8">
                <div>
                    <x-input-label for="settings_dns_provider_credential_id" :value="__('DNS credential')" />
                    <select
                        id="settings_dns_provider_credential_id"
                        wire:model="settings_dns_provider_credential_id"
                        class="mt-2 block w-full rounded-md border-slate-300 text-sm shadow-sm"
                    >
                        <option value="">{{ __('Organization default (most recently updated DNS credential)') }}</option>
                        @foreach ($providerCredentials as $credential)
                            <option value="{{ $credential->id }}">{{ $credential->name }} — {{ $credential->dnsProviderLabel() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-2 text-sm text-slate-600">
                        {{ __('Defaults to the latest DigitalOcean or Cloudflare API token saved for this organization. Choose a specific row to pin DNS to that integration.') }}
                    </p>
                    <x-input-error :messages="$errors->get('settings_dns_provider_credential_id')" class="mt-2" />
                    @if ($providerCredentials->isEmpty())
                        <p class="mt-3 text-sm text-amber-900">
                            {{ __('No DNS-capable credentials yet. Connect DigitalOcean and/or Cloudflare under Server providers.') }}
                        </p>
                    @endif
                </div>

                <div>
                    <x-input-label for="settings_dns_zone" :value="__('DNS zone')" />
                    <x-text-input
                        id="settings_dns_zone"
                        wire:model="settings_dns_zone"
                        class="mt-2 block w-full font-mono text-sm"
                        placeholder="example.com"
                        autocomplete="off"
                    />
                    <p class="mt-2 text-sm text-slate-600">
                        {{ __('The apex zone in that DNS provider (e.g. DigitalOcean Networking → Domains, or Cloudflare zone). Suggested from your primary hostname when unset.') }}
                    </p>
                    <x-input-error :messages="$errors->get('settings_dns_zone')" class="mt-2" />
                </div>
            </div>
        </div>

        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-6 py-4 sm:px-8">
            <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
        </div>
    </form>
</section>
