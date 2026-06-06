@php
    use App\Models\SiteCertificate;

    $card = 'dply-card overflow-hidden';

    $certificateCount = $site->certificates->count();
    $activeCertificateCount = $site->certificates->where('status', SiteCertificate::STATUS_ACTIVE)->count();
    $pendingCertificateCount = $site->certificates
        ->whereIn('status', [
            SiteCertificate::STATUS_PENDING,
            SiteCertificate::STATUS_ISSUED,
            SiteCertificate::STATUS_INSTALLING,
        ])
        ->count();

    // Hostnames already covered by a live certificate — there's no reason to
    // request another cert for them, so exclude them from the preview-domain
    // picker below.
    $certCoveredHostnames = $site->certificates
        ->whereIn('status', [
            SiteCertificate::STATUS_PENDING,
            SiteCertificate::STATUS_ISSUED,
            SiteCertificate::STATUS_INSTALLING,
            SiteCertificate::STATUS_ACTIVE,
        ])
        ->flatMap(fn (SiteCertificate $certificate) => $certificate->domainHostnames())
        ->map(fn ($hostname) => strtolower((string) $hostname))
        ->unique()
        ->all();

    $availablePreviewDomains = $site->previewDomains
        ->reject(fn ($previewDomain) => in_array(strtolower((string) $previewDomain->hostname), $certCoveredHostnames, true))
        ->values();

    $statusChip = static function (string $status): array {
        return match ($status) {
            SiteCertificate::STATUS_ACTIVE => ['bg-emerald-50 text-emerald-700 ring-emerald-200', 'heroicon-m-check-circle'],
            SiteCertificate::STATUS_PENDING,
            SiteCertificate::STATUS_ISSUED,
            SiteCertificate::STATUS_INSTALLING => ['bg-amber-50 text-amber-800 ring-amber-200', 'heroicon-m-clock'],
            SiteCertificate::STATUS_FAILED => ['bg-rose-50 text-rose-700 ring-rose-200', 'heroicon-m-exclamation-triangle'],
            SiteCertificate::STATUS_EXPIRED => ['bg-rose-50 text-rose-700 ring-rose-200', 'heroicon-m-clock'],
            default => ['bg-brand-sand/40 text-brand-moss ring-brand-ink/10', 'heroicon-m-shield-check'],
        };
    };
@endphp

@if ($this->siteUsesCaddyAutoHttps())
    {{-- =================================================================
         CADDY-MANAGED TLS. Caddy terminates TLS with built-in automatic
         HTTPS — it obtains and renews the Let's Encrypt cert itself, so
         certbot never runs and there are no SiteCertificate paths. We read
         the live cert straight from Caddy's data dir over SSH (lazy, cached
         60s) so the operator can still see the real issuer + expiry.
         ================================================================= --}}
    <section class="{{ $card }} mb-6 overflow-hidden" wire:init="loadCaddyManagedCerts">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Managed by Caddy') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Automatic HTTPS') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Caddy obtains and renews this site’s certificate automatically — no certbot, no manual request needed. Details below are read live from the server.') }}
                        @if ($caddy_managed_certs_scanned_at_iso)
                            <span class="ml-1 text-brand-mist">· {{ __('Scanned :time', ['time' => \Illuminate\Support\Carbon::parse($caddy_managed_certs_scanned_at_iso)->diffForHumans()]) }}</span>
                        @endif
                    </p>
                </div>
            </div>
            <button
                type="button"
                wire:click="refreshCaddyManagedCerts"
                wire:loading.attr="disabled"
                wire:target="refreshCaddyManagedCerts,loadCaddyManagedCerts"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="refreshCaddyManagedCerts,loadCaddyManagedCerts" class="inline-flex"><x-heroicon-o-arrow-path class="h-3.5 w-3.5" /></span>
                <span wire:loading wire:target="refreshCaddyManagedCerts,loadCaddyManagedCerts" class="inline-flex"><x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" /></span>
                {{ __('Rescan') }}
            </button>
        </div>

        @if ($caddy_managed_certs_error)
            <div class="border-b border-rose-200 bg-rose-50/70 px-6 py-3 text-sm text-rose-900 sm:px-7">{{ $caddy_managed_certs_error }}</div>
        @endif

        @if (! $caddy_managed_certs_loaded)
            <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-7" @if ($caddy_managed_certs_scanning) wire:poll.2s="pollCaddyManagedCerts" @endif>
                <span class="inline-flex items-center gap-2"><x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" /> {{ __('Reading Caddy certificate…') }}</span>
            </div>
        @elseif ($caddy_managed_certs_unreadable)
            <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-7">
                {{ __('Could not read the certificate over SSH. Caddy still serves TLS — check that the deploy user has passwordless sudo for `find` + `openssl`.') }}
            </div>
        @elseif (empty($caddy_managed_certs))
            <div class="flex flex-col items-center justify-center gap-2 px-6 py-10 text-center sm:px-7">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss"><x-heroicon-o-clock class="h-6 w-6" /></span>
                <p class="text-sm font-medium text-brand-ink">{{ __('Caddy hasn’t issued a certificate yet.') }}</p>
                <p class="max-w-md text-xs text-brand-moss">{{ __('Caddy fetches the certificate on the first HTTPS request once DNS points at this server. Reload after the first visit.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($caddy_managed_certs as $cert)
                    @php
                        $urgency = (string) ($cert['urgency'] ?? 'unknown');
                        $days = $cert['days_until_expiry'] ?? null;
                    @endphp
                    <li class="px-6 py-4 sm:px-7">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200"><x-heroicon-o-lock-closed class="h-4 w-4" /></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $cert['subject'] ?: __('Caddy certificate') }}</p>
                                    @if (! $cert['error'])
                                        <span @class([
                                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1',
                                            'bg-rose-100 text-rose-900 ring-rose-200' => $urgency === 'expired',
                                            'bg-rose-50 text-rose-700 ring-rose-200' => $urgency === 'danger',
                                            'bg-amber-50 text-amber-800 ring-amber-200' => $urgency === 'warn',
                                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $urgency === 'ok',
                                            'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => $urgency === 'unknown',
                                        ])>
                                            <x-heroicon-m-clock class="h-3 w-3" />
                                            @if ($urgency === 'expired')
                                                {{ __('expired :n d ago', ['n' => abs((int) $days)]) }}
                                            @elseif ($days !== null)
                                                {{ __('expires in :n d', ['n' => (int) $days]) }}
                                            @else
                                                {{ __('expiry unknown') }}
                                            @endif
                                        </span>
                                    @endif
                                </div>
                                @if ($cert['issuer'])
                                    <p class="mt-1 text-[11px] text-brand-moss">{{ __('Issued by') }} <span class="font-medium text-brand-ink">{{ $cert['issuer'] }}</span></p>
                                @endif
                                @if (! empty($cert['not_after']))
                                    <p class="mt-0.5 text-[11px] tabular-nums text-brand-mist">{{ __('Valid until :date', ['date' => $cert['not_after']]) }}</p>
                                @endif
                                <p class="mt-1 break-all font-mono text-[11px] leading-relaxed text-brand-mist">{{ $cert['path'] }}</p>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif

<section class="{{ $card }}">
    <form wire:submit="createCertificateRequest">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('SSL') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Request or install certificates') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Create certificates against explicit customer or preview scopes so Dply never guesses which domains should receive SSL.') }}
                </p>
            </div>
        </div>

        <div class="space-y-5 px-6 py-6 sm:px-7">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <x-input-label for="new_certificate_scope" :value="__('Scope')" />
                        <select id="new_certificate_scope" wire:model.live="new_certificate_scope" class="dply-input">
                            <option value="customer">{{ __('Customer domains') }}</option>
                            <option value="preview">{{ __('Preview domain') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="new_certificate_provider_type" :value="__('Certificate type')" />
                        <select id="new_certificate_provider_type" wire:model.live="new_certificate_provider_type" class="dply-input">
                            <option value="letsencrypt">{{ __('Let\'s Encrypt') }}</option>
                            <option value="imported">{{ __('Install existing certificate') }}</option>
                            <option value="csr">{{ __('Create CSR') }}</option>
                            <option value="zerossl">{{ __('ZeroSSL') }}</option>
                        </select>
                    </div>
                </div>

                @if ($new_certificate_scope === 'preview')
                    <div>
                        <x-input-label for="new_certificate_preview_domain_id" :value="__('Preview domain')" />
                        @if ($availablePreviewDomains->isEmpty())
                            <p class="mt-2 rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss">
                                {{ $site->previewDomains->isEmpty()
                                    ? __('No preview domains are attached to this site yet.')
                                    : __('Every preview domain already has a certificate — nothing to request here.') }}
                            </p>
                        @else
                            <select id="new_certificate_preview_domain_id" wire:model="new_certificate_preview_domain_id" class="dply-input">
                                <option value="">{{ __('Select a preview domain') }}</option>
                                @foreach ($availablePreviewDomains as $previewDomain)
                                    <option value="{{ $previewDomain->id }}">{{ $previewDomain->hostname }}</option>
                                @endforeach
                            </select>
                        @endif
                        <x-input-error :messages="$errors->get('new_certificate_preview_domain_id')" class="mt-2" />
                    </div>
                @else
                    <div>
                        <x-input-label for="new_certificate_domains" :value="__('Domains')" />
                        <textarea id="new_certificate_domains" wire:model="new_certificate_domains" rows="3" class="dply-input font-mono text-xs" placeholder="app.example.com&#10;www.example.com"></textarea>
                        <x-input-error :messages="$errors->get('new_certificate_domains')" class="mt-2" />
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Leave empty to use the site’s current customer domains.') }}</p>
                    </div>
                @endif

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <x-input-label for="new_certificate_challenge_type" :value="__('Challenge flow')" />
                        <select id="new_certificate_challenge_type" wire:model.live="new_certificate_challenge_type" class="dply-input">
                            <option value="http">{{ __('HTTP challenge') }}</option>
                            <option value="dns">{{ __('DNS provider challenge') }}</option>
                            <option value="imported">{{ __('Imported certificate') }}</option>
                            <option value="manual">{{ __('Manual / CSR') }}</option>
                        </select>
                    </div>
                    @if ($new_certificate_challenge_type === 'dns')
                        <div>
                            <x-input-label for="new_certificate_dns_provider" :value="__('DNS provider')" />
                            <select id="new_certificate_dns_provider" wire:model="new_certificate_dns_provider" class="dply-input">
                                <option value="digitalocean">{{ __('DigitalOcean') }}</option>
                            </select>
                        </div>
                    @endif
                </div>

                @if ($new_certificate_challenge_type === 'dns')
                    <div>
                        <x-input-label for="new_certificate_provider_credential_id" :value="__('Provider credential')" />
                        <select id="new_certificate_provider_credential_id" wire:model="new_certificate_provider_credential_id" class="dply-input">
                            <option value="">{{ __('Select a credential') }}</option>
                            @foreach ($providerCredentials as $credential)
                                <option value="{{ $credential->id }}">{{ $credential->name }} ({{ ucfirst($credential->provider) }})</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('new_certificate_provider_credential_id')" class="mt-2" />
                    </div>
                @endif

                @if ($new_certificate_provider_type === 'imported')
                    <div class="grid gap-4">
                        <div>
                            <x-input-label for="new_certificate_certificate_pem" :value="__('Certificate PEM')" />
                            <textarea id="new_certificate_certificate_pem" wire:model="new_certificate_certificate_pem" rows="6" class="dply-input font-mono text-xs"></textarea>
                        </div>
                        <div>
                            <x-input-label for="new_certificate_private_key_pem" :value="__('Private key PEM')" />
                            <textarea id="new_certificate_private_key_pem" wire:model="new_certificate_private_key_pem" rows="6" class="dply-input font-mono text-xs"></textarea>
                        </div>
                        <div>
                            <x-input-label for="new_certificate_chain_pem" :value="__('Chain PEM')" />
                            <textarea id="new_certificate_chain_pem" wire:model="new_certificate_chain_pem" rows="4" class="dply-input font-mono text-xs"></textarea>
                        </div>
                    </div>
                @endif

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-ink">
                        <input type="checkbox" wire:model="new_certificate_force_skip_dns_checks" class="mt-1 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage/30" />
                        <span class="leading-6 text-brand-moss">{{ __('Skip DNS preflight checks when the selected challenge path allows it.') }}</span>
                    </label>
                    @if ($supportsHttp3Certificates)
                        <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-ink">
                            <input type="checkbox" wire:model="new_certificate_enable_http3" class="mt-1 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage/30" />
                            <span class="leading-6 text-brand-moss">{{ __('Record HTTP/3 intent for this certificate on hosts that support it.') }}</span>
                        </label>
                    @endif
                </div>
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <x-primary-button type="submit">{{ __('Save certificate request') }}</x-primary-button>
        </div>
    </form>
</section>

<section class="{{ $card }} mt-6">
    <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Existing certificates') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Each certificate keeps its own scope, provider, challenge path, and last output for safer retries and cleanup.') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                    <span class="inline-flex items-center gap-1">
                        <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                        {{ trans_choice('{0} no certificates|{1} :count certificate|[2,*] :count certificates', $certificateCount, ['count' => $certificateCount]) }}
                    </span>
                    @if ($activeCertificateCount > 0)
                        <span class="text-brand-mist/60">·</span>
                        <span class="inline-flex items-center gap-1">
                            <x-heroicon-o-check-circle class="h-3 w-3 text-emerald-600" />
                            {{ trans_choice('{1} :count active|[2,*] :count active', $activeCertificateCount, ['count' => $activeCertificateCount]) }}
                        </span>
                    @endif
                    @if ($pendingCertificateCount > 0)
                        <span class="text-brand-mist/60">·</span>
                        <span class="inline-flex items-center gap-1">
                            <x-heroicon-o-clock class="h-3 w-3 text-amber-600" />
                            {{ trans_choice('{1} :count pending|[2,*] :count pending', $pendingCertificateCount, ['count' => $pendingCertificateCount]) }}
                        </span>
                    @endif
                </div>
            </div>
        </div>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2.5 py-1 text-[11px] font-semibold text-brand-moss">
            <span class="h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
            {{ trans_choice('{0} no certificates|{1} :count certificate|[2,*] :count certificates', $certificateCount, ['count' => $certificateCount]) }}
        </span>
    </div>

    @if ($site->certificates->isEmpty())
        <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center sm:px-8">
            <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss">
                <x-heroicon-o-shield-check class="h-6 w-6" />
            </span>
            <p class="text-sm font-medium text-brand-ink">{{ __('No certificates have been requested for this site yet.') }}</p>
            <p class="text-xs text-brand-moss">{{ __('Use the form above to request a Let’s Encrypt cert or install an existing one.') }}</p>
        </div>
    @else
        <ul class="divide-y divide-brand-ink/8">
            @foreach ($site->certificates as $certificate)
                @php
                    [$chipClasses, $chipIcon] = $statusChip($certificate->status);
                    $hostnames = $certificate->domainHostnames();
                @endphp
                <li class="px-6 py-4 sm:px-8" wire:key="cert-{{ $certificate->id }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sand/40 text-brand-forest ring-brand-ink/10">
                            <x-heroicon-o-lock-closed class="h-4 w-4" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-brand-ink">{{ ucfirst($certificate->provider_type) }} · {{ ucfirst($certificate->scope_type) }}</p>
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $chipClasses }}">
                                    <x-dynamic-component :component="$chipIcon" class="h-3 w-3" />
                                    {{ $certificate->status }}
                                </span>
                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                    <x-heroicon-m-bolt class="h-3 w-3" />
                                    {{ $certificate->challenge_type }}
                                </span>
                            </div>

                            @if (! empty($hostnames))
                                <p class="mt-1 break-all font-mono text-[11px] leading-relaxed text-brand-mist">
                                    {{ implode(', ', $hostnames) }}
                                </p>
                            @endif

                            @if ($certificate->last_output)
                                <details class="mt-2">
                                    <summary class="cursor-pointer list-none text-[11px] font-medium uppercase tracking-wide text-brand-mist hover:text-brand-ink">
                                        <span class="inline-flex items-center gap-1">
                                            <x-heroicon-o-chevron-down class="h-3 w-3" />
                                            {{ __('Last output') }}
                                        </span>
                                    </summary>
                                    <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-brand-sand/15 px-3 py-2 font-mono text-[11px] leading-relaxed text-brand-moss">{{ \Illuminate\Support\Str::limit($certificate->last_output, 800) }}</pre>
                                </details>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-2 self-start sm:self-center">
                            @if (in_array($certificate->status, [SiteCertificate::STATUS_FAILED, SiteCertificate::STATUS_EXPIRED], true))
                                <button
                                    type="button"
                                    wire:click="repairCertificate('{{ $certificate->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="repairCertificate('{{ $certificate->id }}')"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-forest/30 bg-brand-forest/5 px-2.5 py-1.5 text-xs font-semibold text-brand-forest shadow-sm hover:bg-brand-forest/10 disabled:opacity-60"
                                    title="{{ __('Re-apply webserver routing and retry Let\'s Encrypt for this certificate') }}"
                                >
                                    <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5" wire:loading.remove wire:target="repairCertificate('{{ $certificate->id }}')" />
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="repairCertificate('{{ $certificate->id }}')" />
                                    <span wire:loading wire:target="repairCertificate('{{ $certificate->id }}')">{{ __('Repairing…') }}</span>
                                    <span wire:loading.remove wire:target="repairCertificate('{{ $certificate->id }}')">{{ __('Repair') }}</span>
                                </button>
                            @endif
                            <button
                                type="button"
                                wire:click="removeCertificate('{{ $certificate->id }}')"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-brand-mist hover:border-red-200 hover:bg-red-50 hover:text-red-700"
                                title="{{ __('Remove certificate') }}"
                            >
                                <x-heroicon-o-trash class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
        <x-cli-snippet :commands="[
            ['label' => __('Check status'), 'command' => 'dply sites:ssl:status '.$site->slug],
            ['label' => __('Issue cert'), 'command' => 'dply sites:ssl:issue '.$site->slug],
            ['label' => __('Force renew'), 'command' => 'dply sites:ssl:renew '.$site->slug],
        ]" />
    </div>
</section>
