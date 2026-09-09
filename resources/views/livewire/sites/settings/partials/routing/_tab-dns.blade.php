@php
    $card = 'border-b border-brand-ink/10';

    $resolvedCredential = $site->dnsAutomationCredential();
    $resolvedCredentialIsExplicit = $resolvedCredential !== null
        && (string) $site->dns_provider_credential_id === (string) $resolvedCredential->id;
    $savedZone = trim((string) ($site->dns_zone ?? ''));
    $effectiveZone = $savedZone !== '' ? $savedZone : ($site->guessDnsZoneFromPrimaryHostname() ?? '');
    $zoneSourceLabel = match (true) {
        $savedZone !== '' => __('saved'),
        $site->guessDnsZoneFromPrimaryHostname() !== null => __('suggested from primary hostname'),
        default => __('default testing-domain pool'),
    };
    $hasAnyDnsCredentials = $providerCredentials->isNotEmpty();
    $serverIp = trim((string) ($site->server->ip_address ?? ''));
    $managed = $this->dnsRecordsManaged();

    $dnsStatusMeta = [
        'pointing' => ['label' => __('Pointing here'), 'cls' => 'bg-emerald-50 text-emerald-800 ring-emerald-200/70', 'icon' => 'heroicon-o-check-circle'],
        'cloudflare' => ['label' => __('Behind Cloudflare'), 'cls' => 'bg-sky-50 text-sky-800 ring-sky-200/70', 'icon' => 'heroicon-o-cloud'],
        // Correct at the nameservers, still cached elsewhere. Blue, not amber:
        // there is nothing to fix, so it must not read like a problem.
        'propagating' => ['label' => __('Propagating'), 'cls' => 'bg-sky-50 text-sky-800 ring-sky-200/70', 'icon' => 'heroicon-o-arrow-path'],
        'wrong' => ['label' => __('Points elsewhere'), 'cls' => 'bg-amber-50 text-amber-900 ring-amber-200/70', 'icon' => 'heroicon-o-exclamation-triangle'],
        'missing' => ['label' => __('Not resolving'), 'cls' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10', 'icon' => 'heroicon-o-minus-circle'],
    ];
@endphp

{{-- Records this site needs + where they actually point. This is the headline:
     "is my domain pointed here, and fix it in one click." The provider/zone
     automation config lives below in a collapsed disclosure. --}}
<div class="{{ $card }}" wire:init="loadDnsRecordStatuses">
    <x-workspace-panel-head
        class="border-b border-brand-ink/10"
        icon="heroicon-o-signal"
        :title="__('Point your domains at this server')"
        :note="$serverIp !== ''
            ? __('Each hostname needs an A record pointing at :ip. Live status below — dply can apply the records for zones it controls, or you can add them at your DNS host.', ['ip' => $serverIp])
            : __('This server has no IP yet — DNS records can be applied once it finishes provisioning.')"
    >
        <x-slot:actions>
            <button type="button" wire:click="recheckDnsRecords" wire:loading.attr="disabled" wire:target="recheckDnsRecords,loadDnsRecordStatuses"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="recheckDnsRecords,loadDnsRecordStatuses" />
                {{ __('Re-check') }}
            </button>
            @can('update', $site)
                @if ($managed)
                    <button type="button" wire:click="applySiteDnsRecords" wire:loading.attr="disabled" wire:target="applySiteDnsRecords"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-2.5 py-1 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:opacity-60"
                        title="{{ __('Create/update the A records via :provider.', ['provider' => $resolvedCredential?->dnsProviderLabel() ?? __('your DNS provider')]) }}">
                        <x-heroicon-o-bolt class="h-3.5 w-3.5" wire:loading.remove wire:target="applySiteDnsRecords" />
                        <span wire:loading.remove wire:target="applySiteDnsRecords">{{ __('Apply records') }}</span>
                        <span wire:loading wire:target="applySiteDnsRecords" class="inline-flex items-center gap-1.5"><x-spinner variant="cream" size="sm" />{{ __('Applying…') }}</span>
                    </button>
                @endif
            @endcan
        </x-slot:actions>
    </x-workspace-panel-head>

    {{-- Loading skeleton until the wire:init probe lands. --}}
    <div wire:loading.flex wire:target="loadDnsRecordStatuses" class="items-center gap-2 px-5 py-4 text-xs text-brand-moss sm:px-6">
        <x-spinner variant="forest" size="sm" />
        {{ __('Checking where your domains resolve…') }}
    </div>

    <div wire:loading.remove wire:target="loadDnsRecordStatuses">
        @if (! $dnsRecordsLoaded)
            <div class="px-5 py-4 text-xs text-brand-moss sm:px-6">{{ __('Loading DNS status…') }}</div>
        @elseif ($dnsRecordStatuses === [])
            <div class="flex flex-col items-center justify-center gap-1.5 px-5 py-8 text-center sm:px-6">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-moss"><x-heroicon-o-globe-alt class="h-5 w-5" /></span>
                <p class="text-sm font-medium text-brand-ink">{{ __('No customer domains on this site yet.') }}</p>
                <p class="text-xs text-brand-moss">{{ __('Add a domain on the Domains tab, then come back to point it here.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($dnsRecordStatuses as $row)
                    @php $meta = $dnsStatusMeta[$row['status']] ?? $dnsStatusMeta['missing']; @endphp
                    <li class="flex flex-col gap-2 px-5 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:px-6" wire:key="dns-row-{{ md5($row['hostname']) }}">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate font-mono text-sm font-semibold text-brand-ink">{{ $row['hostname'] }}</span>
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.14em] ring-1 ring-inset {{ $meta['cls'] }}">
                                    <x-dynamic-component :component="$meta['icon']" class="h-3 w-3" />
                                    {{ $meta['label'] }}
                                </span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-xs text-brand-moss"
                                x-data="{ copied: false }">
                                <span class="inline-flex items-center gap-1.5 rounded-md bg-brand-sand/30 px-2 py-0.5 ring-1 ring-inset ring-brand-ink/5">
                                    <span class="text-brand-mist">{{ $row['type'] }}</span>
                                    <span class="text-brand-ink">{{ $row['name'] }}</span>
                                    <span class="text-brand-mist">→</span>
                                    <span class="text-brand-ink">{{ $row['value'] ?: '—' }}</span>
                                    @if ($row['value'] !== '')
                                        <button type="button"
                                            x-on:click="navigator.clipboard.writeText('{{ $row['value'] }}'); copied = true; setTimeout(() => copied = false, 1200)"
                                            class="ml-0.5 text-brand-mist hover:text-brand-forest" title="{{ __('Copy IP') }}">
                                            <x-heroicon-o-clipboard-document class="h-3.5 w-3.5" x-show="!copied" />
                                            <x-heroicon-o-check class="h-3.5 w-3.5 text-emerald-600" x-show="copied" x-cloak />
                                        </button>
                                    @endif
                                </span>
                                @if ($row['status'] === 'wrong' && $row['resolved_ips'] !== [])
                                    <span class="text-amber-700">{{ __('currently → :ips', ['ips' => implode(', ', array_slice($row['resolved_ips'], 0, 3))]) }}</span>
                                @elseif ($row['status'] === 'propagating')
                                    {{-- Say what the stale answer is, because dig from a laptop
                                         may still show it too, and an unexplained mismatch is
                                         exactly what sends someone editing a correct record.
                                         With no stale IPs to name the old sentence rendered as
                                         "set at the nameservers — still cached", which reads as
                                         a warning about nothing; name the provider instead so it
                                         is obvious the record is already correct there. --}}
                                    <span class="text-sky-700">
                                        @if ($row['resolved_ips'] !== [])
                                            {{ __('already correct at :provider — :ips still cached elsewhere, expires on its own', ['provider' => $resolvedCredential?->dnsProviderLabel() ?? __('your DNS provider'), 'ips' => implode(', ', array_slice($row['resolved_ips'], 0, 3))]) }}
                                        @else
                                            {{ __('already correct at :provider — nothing to do here; public resolvers are still catching up', ['provider' => $resolvedCredential?->dnsProviderLabel() ?? __('your DNS provider')]) }}
                                        @endif
                                    </span>
                                @elseif ($row['status'] === 'cloudflare')
                                    <span class="text-sky-700">{{ __('proxied — Cloudflare serves TLS at its edge') }}</span>
                                @elseif (! $row['in_zone'] && $row['status'] !== 'pointing')
                                    <span class="text-brand-mist">{{ __('outside the managed zone — add at its registrar') }}</span>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-5 py-2.5 text-xs leading-relaxed text-brand-moss sm:px-6">
                @if ($managed)
                    {{ __('dply manages this zone via :provider — "Apply records" creates/updates the A records above. Cloudflare-proxied hosts already serve HTTPS at the edge and need no origin A record here.', ['provider' => $resolvedCredential?->dnsProviderLabel() ?? __('your DNS provider')]) }}
                @else
                    {{ __('dply has no DNS credential for this zone, so add the A records above at your DNS host. Connect a DigitalOcean or Cloudflare token under Automation below and dply can apply them for you.') }}
                @endif
            </div>
        @endif
    </div>
</div>

{{-- Automation (advanced): which credential + zone dply uses for its own DNS
     machinery (preview hostnames, DNS-01 challenge defaults, provisioning
     records). Collapsed by default — most operators never need to touch it. --}}
<details class="{{ $card }} group">
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/20 px-5 py-3 sm:px-6">
        <span class="flex min-w-0 items-center gap-2">
            <x-heroicon-o-cog-6-tooth class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
            <span class="min-w-0 text-sm font-semibold text-brand-ink">{{ __('DNS provider & zone (advanced)') }}</span>
        </span>
        <span class="flex shrink-0 items-center gap-2 text-xs text-brand-mist">
            <span class="inline-flex items-center gap-1">
                <span class="inline-block h-1.5 w-1.5 rounded-full {{ $resolvedCredential ? 'bg-brand-forest' : 'bg-amber-500' }}"></span>
                {{ $resolvedCredential ? $resolvedCredential->dnsProviderLabel() : __('no DNS credential') }}
            </span>
            <span class="text-brand-mist/60">·</span>
            <span>{{ $effectiveZone !== '' ? $effectiveZone : __('testing pool') }}</span>
            <x-heroicon-o-chevron-down class="h-4 w-4 transition-transform group-open:rotate-180" />
        </span>
    </summary>

    @unless ($hasAnyDnsCredentials)
        <div class="border-t border-amber-200/70 bg-amber-50 px-5 py-3 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 flex-1 basis-72 items-start gap-2">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-amber-950">{{ __('No DNS-capable credentials yet') }}</h3>
                        <p class="mt-0.5 text-xs leading-relaxed text-amber-900">{{ __('Connect DigitalOcean and/or Cloudflare under Credentials to use a custom DNS zone and let dply apply records for you.') }}</p>
                    </div>
                </div>
                <a href="{{ route('credentials.index', ['tab' => 'dns']) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-2.5 py-1 text-xs font-semibold text-amber-900 shadow-sm hover:bg-amber-100">
                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                    {{ __('Add DNS provider') }}
                </a>
            </div>
        </div>
    @endunless

    <form wire:submit="saveDnsSettings" class="border-t border-brand-ink/10">
        <div class="space-y-3 px-5 py-4 sm:px-6">
            <p class="max-w-3xl text-xs leading-relaxed text-brand-moss">
                {{ __('Pick which connected DNS credential dply uses for preview hostnames, DNS-01 challenges, and provisioning records. The credential can differ from where the server is hosted — e.g. DigitalOcean compute with Cloudflare DNS. Leave the zone empty to fall back to the app testing-domain pool.') }}
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="settings_dns_provider_credential_id" :value="__('DNS credential')" class="!text-xs" />
                    <select
                        id="settings_dns_provider_credential_id"
                        wire:model="settings_dns_provider_credential_id"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                    >
                        <option value="">{{ __('Organization default (most recently updated DNS credential)') }}</option>
                        @foreach ($providerCredentials as $credential)
                            <option value="{{ $credential->id }}">{{ $credential->name }} — {{ $credential->dnsProviderLabel() }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('settings_dns_provider_credential_id')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="settings_dns_zone" :value="__('DNS zone')" class="!text-xs" />
                    <x-text-input
                        id="settings_dns_zone"
                        wire:model="settings_dns_zone"
                        class="mt-1 block w-full font-mono text-sm"
                        placeholder="example.com"
                        autocomplete="off"
                    />
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('The apex zone in that DNS provider (e.g. DigitalOcean Networking → Domains, or a Cloudflare zone). Suggested from your primary hostname when unset.') }}
                    </p>
                    <x-input-error :messages="$errors->get('settings_dns_zone')" class="mt-1" />
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6">
            <a href="{{ route('credentials.index', ['tab' => 'dns']) }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-moss hover:text-brand-ink">
                <x-heroicon-o-key class="h-3.5 w-3.5" />
                {{ __('Manage DNS providers') }}
            </a>
            <x-primary-button size="sm" type="submit">{{ __('Save') }}</x-primary-button>
        </div>
    </form>
</details>

<div class="px-5 py-3 sm:px-6">
    <x-cli-snippet tone="stub" />
</div>
