@php
    $card = 'dply-card overflow-hidden';

    // Resolve what Dply would actually use for DNS automation given the current saved
    // settings. The form fields are the *desired* state; this resolves the *effective*
    // state so the operator can see — like the SSH keys workspace shows tracked-keys /
    // last-sync — what the configuration would do right now.
    $resolvedCredential = $site->dnsAutomationCredential();
    $resolvedCredentialIsExplicit = $resolvedCredential !== null
        && (string) $site->dns_provider_credential_id === (string) $resolvedCredential->id;
    $savedZone = trim((string) ($site->dns_zone ?? ''));
    $zoneSourceLabel = match (true) {
        $savedZone !== '' => __('saved'),
        $site->guessDnsZoneFromPrimaryHostname() !== null => __('suggested from primary hostname'),
        default => __('default testing-domain pool'),
    };
    $hasAnyDnsCredentials = $providerCredentials->isNotEmpty();
@endphp

{{-- Hero header — title, description, status chips. Mirrors the SSH-keys workspace and
     routing tabs: icon, h2, two-line description, then a row of muted status chips
     summarising the resolved DNS configuration. --}}
<div class="{{ $card }}">
    <div class="flex flex-col gap-4 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                <x-heroicon-o-globe-alt class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('DNS automation') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ __('Pick which connected DNS credential Dply should use for this site, and the apex zone that exists in that provider account. Leave the zone empty to fall back to the app-default testing-domain pool.') }}
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                    <span class="inline-flex items-center gap-1">
                        <span class="inline-block h-1.5 w-1.5 rounded-full {{ $resolvedCredential ? 'bg-brand-forest' : 'bg-amber-500' }}"></span>
                        @if ($resolvedCredential)
                            {{ __('credential: :name', ['name' => $resolvedCredential->name]) }}
                            <span class="text-brand-mist/60">·</span>
                            <span>{{ $resolvedCredential->dnsProviderLabel() }}</span>
                            @if (! $resolvedCredentialIsExplicit)
                                <span class="text-brand-mist/60">·</span>
                                <span>{{ __('organization default') }}</span>
                            @endif
                        @else
                            {{ __('no DNS-capable credential resolved') }}
                        @endif
                    </span>
                    <span class="text-brand-mist/60">·</span>
                    <span class="inline-flex items-center gap-1">
                        @if ($savedZone !== '')
                            <x-heroicon-m-check class="h-3 w-3 text-emerald-600" />
                            {{ __('zone :zone', ['zone' => $savedZone]) }}
                        @else
                            <x-heroicon-m-information-circle class="h-3 w-3 text-brand-mist" />
                            {{ __('zone :source', ['source' => $zoneSourceLabel]) }}
                        @endif
                    </span>
                </div>
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <a
                href="{{ route('credentials.index', ['tab' => 'dns']) }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                <x-heroicon-o-key class="h-3.5 w-3.5" />
                {{ __('Manage DNS providers') }}
            </a>
        </div>
    </div>

    @unless ($hasAnyDnsCredentials)
        <div class="mx-6 mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900 sm:mx-8">
            <p class="min-w-0 leading-6">
                <span class="font-semibold">{{ __('No DNS-capable credentials yet.') }}</span>
                {{ __('Connect DigitalOcean and/or Cloudflare under Server providers to use a custom DNS zone.') }}
            </p>
            <a href="{{ route('credentials.index', ['tab' => 'dns']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                <x-heroicon-o-plus class="h-3.5 w-3.5" />
                {{ __('Add DNS provider') }}
            </a>
        </div>
    @endunless
</div>

{{-- Settings form — split-rail card matches the SSH keys workspace forms: left-side
     orientation copy, right-side fields, and a sand-tinted save footer. --}}
<div class="{{ $card }} mt-6">
    <form wire:submit="saveDnsSettings">
        <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 p-6 lg:border-b-0 lg:border-r">
                <h3 class="text-sm font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Settings') }}</h3>
                <p class="mt-3 text-sm leading-6 text-brand-moss">
                    {{ __('The credential here can differ from where the server is hosted — for example DigitalOcean compute with Cloudflare DNS.') }}
                </p>
                <p class="mt-4 text-sm">
                    <a href="{{ route('credentials.index', ['tab' => 'dns']) }}" wire:navigate class="font-medium text-brand-sage underline decoration-brand-sage/30 hover:decoration-brand-sage">
                        {{ __('Connected DNS providers') }} &raquo;
                    </a>
                </p>
            </div>

            <div class="space-y-5 p-6 sm:p-8">
                <div>
                    <x-input-label for="settings_dns_provider_credential_id" :value="__('DNS credential')" />
                    <select
                        id="settings_dns_provider_credential_id"
                        wire:model="settings_dns_provider_credential_id"
                        class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                    >
                        <option value="">{{ __('Organization default (most recently updated DNS credential)') }}</option>
                        @foreach ($providerCredentials as $credential)
                            <option value="{{ $credential->id }}">{{ $credential->name }} — {{ $credential->dnsProviderLabel() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-2 text-sm text-brand-moss">
                        {{ __('Defaults to the latest DigitalOcean or Cloudflare API token saved for this organization. Choose a specific row to pin DNS to that integration.') }}
                    </p>
                    <x-input-error :messages="$errors->get('settings_dns_provider_credential_id')" class="mt-2" />
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
                    <p class="mt-2 text-sm text-brand-moss">
                        {{ __('The apex zone in that DNS provider (e.g. DigitalOcean Networking → Domains, or Cloudflare zone). Suggested from your primary hostname when unset.') }}
                    </p>
                    <x-input-error :messages="$errors->get('settings_dns_zone')" class="mt-2" />
                </div>
            </div>
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-8">
            <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
        </div>
    </form>
</div>

{{-- Resolved configuration "console" — same dark-on-light treatment as the SSH keys
     workspace console banner. Read-only summary of the effective DNS state Dply will
     use right now, given the saved settings and organization-default fallbacks. --}}
<div class="{{ $card }} mt-6">
    <div class="border-b border-brand-ink/10 bg-brand-ink/95 px-6 py-3 sm:px-8">
        <div class="flex items-center justify-between gap-3">
            <p class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-brand-cream/80">
                <x-heroicon-o-command-line class="h-3.5 w-3.5" />
                {{ __('Resolved DNS configuration') }}
            </p>
            <span class="text-[10px] font-medium uppercase tracking-wide text-brand-cream/50">{{ __('read-only') }}</span>
        </div>
    </div>
    <div class="bg-brand-ink px-6 py-5 font-mono text-[12px] leading-relaxed text-brand-cream/90 sm:px-8">
        <p>
            <span class="text-brand-cream/50">credential</span>
            <span class="ml-2">{{ $resolvedCredential ? $resolvedCredential->name.' ('.$resolvedCredential->dnsProviderLabel().')' : '— none resolved —' }}</span>
            @if ($resolvedCredential && ! $resolvedCredentialIsExplicit)
                <span class="ml-2 inline-flex items-center rounded bg-brand-cream/10 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-brand-cream/70">{{ __('org default') }}</span>
            @endif
        </p>
        <p class="mt-1">
            <span class="text-brand-cream/50">zone</span>
            <span class="ml-2">{{ $savedZone !== '' ? $savedZone : '— '.__('falls back to app testing-domain pool').' —' }}</span>
            @if ($savedZone === '' && ($guessed = $site->guessDnsZoneFromPrimaryHostname()))
                <span class="ml-2 inline-flex items-center rounded bg-brand-cream/10 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-brand-cream/70">{{ __('would suggest :z', ['z' => $guessed]) }}</span>
            @endif
        </p>
        <p class="mt-3 text-[11px] text-brand-cream/60">
            {{ __('Dply uses this configuration for preview-hostname DNS, DNS-01 challenge defaults, and any DNS records created during provisioning.') }}
        </p>
    </div>
</div>

<x-cli-snippet class="mt-6" tone="stub" />
