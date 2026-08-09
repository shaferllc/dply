@php
    use App\Models\SiteCertificate;

    $card = 'border-b border-brand-ink/10';
    $panelBody = 'px-5 py-3 sm:px-6';
    $btnOutline = 'dply-btn dply-btn-xs dply-btn-outline';

    $certificateCount = $site->certificates->count();
    $activeCertificateCount = $site->certificates->where('status', SiteCertificate::STATUS_ACTIVE)->count();
    $pendingCertificateCount = $site->certificates
        ->whereIn('status', [
            SiteCertificate::STATUS_PENDING,
            SiteCertificate::STATUS_ISSUED,
            SiteCertificate::STATUS_INSTALLING,
        ])
        ->count();

    // Hostnames already covered by a live certificate — exclude from the
    // preview-domain picker below.
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

    $uncoveredCustomerDomains = collect($site->sslIssuanceHostnames())
        ->reject(fn (string $hostname) => in_array(strtolower($hostname), $certCoveredHostnames, true))
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

{{-- Cloudflare-edge TLS detection. Probe is operator-triggered (Recheck), not
     wire:init — an always-on init POSTed every load and 419 loops were bad. --}}
@if ($cloudflare_tls_checking)
    <div wire:poll.3s="pollCloudflareTlsStatus"></div>
@endif

@if ($site->cloudflareTerminatesTls())
    <div class="{{ $card }}">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-shield-check"
            :title="__('TLS terminated at Cloudflare’s edge')"
            :note="$site->cloudflareTlsCheckedAt()
                ? __('Checked :time. Origin cert optional for Full (Strict).', ['time' => \Illuminate\Support\Carbon::parse($site->cloudflareTlsCheckedAt())->diffForHumans()])
                : __('Cloudflare serves HTTPS at the edge. Origin cert optional for Full (Strict).')"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="refreshCloudflareTlsStatus"
                    wire:loading.attr="disabled"
                    wire:target="refreshCloudflareTlsStatus"
                    class="{{ $btnOutline }}"
                >
                    <span wire:loading.remove wire:target="refreshCloudflareTlsStatus" class="inline-flex"><x-heroicon-o-arrow-path class="h-3.5 w-3.5" /></span>
                    <span wire:loading wire:target="refreshCloudflareTlsStatus" class="inline-flex"><x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" /></span>
                    {{ __('Recheck') }}
                </button>
            </x-slot:actions>
        </x-workspace-panel-head>
    </div>
@endif

@if ($this->siteUsesCaddyAutoHttps())
    <section class="{{ $card }}" wire:init="loadCaddyManagedCerts">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-shield-check"
            :title="__('Automatic HTTPS (managed by Caddy)')"
            :note="$caddy_managed_certs_scanned_at_iso
                ? __('Scanned :time. Caddy renews automatically — no certbot.', ['time' => \Illuminate\Support\Carbon::parse($caddy_managed_certs_scanned_at_iso)->diffForHumans()])
                : __('Caddy obtains and renews the cert automatically — no certbot.')"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="refreshCaddyManagedCerts"
                    wire:loading.attr="disabled"
                    wire:target="refreshCaddyManagedCerts,loadCaddyManagedCerts"
                    class="{{ $btnOutline }}"
                >
                    <span wire:loading.remove wire:target="refreshCaddyManagedCerts,loadCaddyManagedCerts" class="inline-flex"><x-heroicon-o-arrow-path class="h-3.5 w-3.5" /></span>
                    <span wire:loading wire:target="refreshCaddyManagedCerts,loadCaddyManagedCerts" class="inline-flex"><x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" /></span>
                    {{ __('Rescan') }}
                </button>
            </x-slot:actions>
        </x-workspace-panel-head>

        @if ($caddy_managed_certs_error)
            <div class="border-b border-rose-200 bg-rose-50/70 px-5 py-2 text-xs text-rose-900 sm:px-6">{{ $caddy_managed_certs_error }}</div>
        @endif

        @if (! $caddy_managed_certs_loaded)
            <div class="px-5 py-4 text-center text-xs text-brand-moss sm:px-6" @if ($caddy_managed_certs_scanning) wire:poll.2s="pollCaddyManagedCerts" @endif>
                <span class="inline-flex items-center gap-2"><x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" /> {{ __('Reading Caddy certificate…') }}</span>
            </div>
        @else
            <x-replay-log :frames="$caddy_managed_certs_progress">
                @if ($caddy_managed_certs_unreadable)
                    <div class="px-5 py-4 text-center text-xs text-brand-moss sm:px-6">
                        {{ __('Could not read the certificate over SSH. Caddy still serves TLS — check deploy-user sudo for find + openssl.') }}
                    </div>
                @elseif (empty($caddy_managed_certs))
                    <div class="px-5 py-5 text-center sm:px-6">
                        <p class="text-xs font-medium text-brand-ink">{{ __('Caddy hasn’t issued a certificate yet.') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Fetched on the first HTTPS request once DNS points here.') }}</p>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($caddy_managed_certs as $cert)
                            @php
                                $urgency = (string) ($cert['urgency'] ?? 'unknown');
                                $days = $cert['days_until_expiry'] ?? null;
                            @endphp
                            <li class="px-5 py-2.5 sm:px-6">
                                <div class="flex items-start gap-2.5">
                                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200"><x-heroicon-o-lock-closed class="h-3 w-3" /></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <p class="text-xs font-semibold text-brand-ink">{{ $cert['subject'] ?: __('Caddy certificate') }}</p>
                                            @if (! $cert['error'])
                                                <span @class([
                                                    'inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1',
                                                    'bg-rose-100 text-rose-900 ring-rose-200' => $urgency === 'expired',
                                                    'bg-rose-50 text-rose-700 ring-rose-200' => $urgency === 'danger',
                                                    'bg-amber-50 text-amber-800 ring-amber-200' => $urgency === 'warn',
                                                    'bg-emerald-50 text-emerald-700 ring-emerald-200' => $urgency === 'ok',
                                                    'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => $urgency === 'unknown',
                                                ])>
                                                    <x-heroicon-m-clock class="h-2.5 w-2.5" />
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
                                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Issued by') }} <span class="font-medium text-brand-ink">{{ $cert['issuer'] }}</span></p>
                                        @endif
                                        @if (! empty($cert['not_after']))
                                            <p class="text-xs tabular-nums text-brand-mist">{{ __('Valid until :date', ['date' => $cert['not_after']]) }}</p>
                                        @endif
                                        <p class="mt-0.5 break-all font-mono text-2xs text-brand-mist">{{ $cert['path'] }}</p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-replay-log>
        @endif
    </section>
@endif

@php $cloudflareOptionalCert = $site->cloudflareTerminatesTls(); @endphp
<section @class([$card, 'opacity-70 transition-opacity hover:opacity-100 focus-within:opacity-100' => $cloudflareOptionalCert])>
    <form wire:submit="createCertificateRequest">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-shield-check"
            :title="__('Request or install certificates')"
            :note="$cloudflareOptionalCert
                ? __('Optional while Cloudflare terminates TLS — use for Full (Strict) origin certs.')
                : __('Create against explicit customer or preview scopes — Dply never guesses domains.')"
            :count="$cloudflareOptionalCert ? __('Optional') : null"
        >
            <x-slot:actions>
                @if ($cloudflareOptionalCert)
                    <x-secondary-button size="sm" type="submit">{{ __('Save request') }}</x-secondary-button>
                @else
                    <x-primary-button size="sm" type="submit">{{ __('Save request') }}</x-primary-button>
                @endif
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="{{ $panelBody }} space-y-2.5">
            <div class="grid gap-2.5 md:grid-cols-2">
                <div>
                    <x-input-label for="new_certificate_scope" :value="__('Scope')" class="!text-xs" />
                    <select id="new_certificate_scope" wire:model.live="new_certificate_scope" class="dply-input mt-1">
                        <option value="customer">{{ __('Customer domains') }}</option>
                        <option value="preview">{{ __('Preview domain') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="new_certificate_provider_type" :value="__('Certificate type')" class="!text-xs" />
                    <select id="new_certificate_provider_type" wire:model.live="new_certificate_provider_type" class="dply-input mt-1">
                        <option value="letsencrypt">{{ __('Let\'s Encrypt') }}</option>
                        <option value="imported">{{ __('Install existing certificate') }}</option>
                        <option value="csr">{{ __('Create CSR') }}</option>
                        <option value="zerossl">{{ __('ZeroSSL') }}</option>
                    </select>
                </div>
            </div>

            @if ($new_certificate_scope === 'preview')
                <div>
                    <x-input-label for="new_certificate_preview_domain_id" :value="__('Preview domain')" class="!text-xs" />
                    @if ($availablePreviewDomains->isEmpty())
                        <p class="mt-1 rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/20 px-3 py-1.5 text-xs text-brand-moss">
                            {{ $site->previewDomains->isEmpty()
                                ? __('No preview domains are attached to this site yet.')
                                : __('Every preview domain already has a certificate.') }}
                        </p>
                    @else
                        <select id="new_certificate_preview_domain_id" wire:model="new_certificate_preview_domain_id" class="dply-input mt-1">
                            <option value="">{{ __('Select a preview domain') }}</option>
                            @foreach ($availablePreviewDomains as $previewDomain)
                                <option value="{{ $previewDomain->id }}">{{ $previewDomain->hostname }}</option>
                            @endforeach
                        </select>
                    @endif
                    <x-input-error :messages="$errors->get('new_certificate_preview_domain_id')" class="mt-1" />
                </div>
            @else
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <x-input-label for="new_certificate_domains" :value="__('Domains')" class="!mb-0 !text-xs" />
                        <button
                            type="button"
                            wire:click="loadUncoveredCertificateDomains"
                            wire:loading.attr="disabled"
                            wire:target="loadUncoveredCertificateDomains"
                            @disabled($uncoveredCustomerDomains->isEmpty())
                            class="{{ $btnOutline }} disabled:cursor-not-allowed disabled:opacity-50"
                            title="{{ $uncoveredCustomerDomains->isEmpty()
                                ? __('Every customer domain already has a certificate.')
                                : __('Fill with customer domains that do not already have a certificate.') }}"
                        >
                            <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                            <span wire:loading.remove wire:target="loadUncoveredCertificateDomains">
                                {{ $uncoveredCustomerDomains->isEmpty()
                                    ? __('No uncovered domains')
                                    : trans_choice('{1} Load :count without cert|[2,*] Load :count without certs', $uncoveredCustomerDomains->count(), ['count' => $uncoveredCustomerDomains->count()]) }}
                            </span>
                            <span wire:loading wire:target="loadUncoveredCertificateDomains">{{ __('Loading…') }}</span>
                        </button>
                    </div>
                    <textarea id="new_certificate_domains" wire:model="new_certificate_domains" rows="2" class="dply-input mt-1 font-mono text-xs" placeholder="app.example.com&#10;www.example.com"></textarea>
                    <x-input-error :messages="$errors->get('new_certificate_domains')" class="mt-1" />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Leave empty to use all customer domains, or load only those without a certificate.') }}</p>
                </div>
            @endif

            <div class="grid gap-2.5 md:grid-cols-2">
                <div>
                    <x-input-label for="new_certificate_challenge_type" :value="__('Challenge flow')" class="!text-xs" />
                    <select id="new_certificate_challenge_type" wire:model.live="new_certificate_challenge_type" class="dply-input mt-1">
                        <option value="http">{{ __('HTTP challenge') }}</option>
                        <option value="dns">{{ __('DNS provider challenge') }}</option>
                        <option value="imported">{{ __('Imported certificate') }}</option>
                        <option value="manual">{{ __('Manual / CSR') }}</option>
                    </select>
                </div>
                @if ($new_certificate_challenge_type === 'dns')
                    <div>
                        <x-input-label for="new_certificate_dns_provider" :value="__('DNS provider')" class="!text-xs" />
                        <select id="new_certificate_dns_provider" wire:model="new_certificate_dns_provider" class="dply-input mt-1">
                            <option value="digitalocean">{{ __('DigitalOcean') }}</option>
                        </select>
                    </div>
                @endif
            </div>

            @if ($new_certificate_challenge_type === 'dns')
                <div>
                    <x-input-label for="new_certificate_provider_credential_id" :value="__('Provider credential')" class="!text-xs" />
                    <select id="new_certificate_provider_credential_id" wire:model="new_certificate_provider_credential_id" class="dply-input mt-1">
                        <option value="">{{ __('Select a credential') }}</option>
                        @foreach ($providerCredentials as $credential)
                            <option value="{{ $credential->id }}">{{ $credential->name }} ({{ ucfirst($credential->provider) }})</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('new_certificate_provider_credential_id')" class="mt-1" />
                </div>
            @endif

            @if ($new_certificate_provider_type === 'imported')
                <div class="grid gap-2.5">
                    <div>
                        <x-input-label for="new_certificate_certificate_pem" :value="__('Certificate PEM')" class="!text-xs" />
                        <textarea id="new_certificate_certificate_pem" wire:model="new_certificate_certificate_pem" rows="4" class="dply-input mt-1 font-mono text-xs"></textarea>
                    </div>
                    <div>
                        <x-input-label for="new_certificate_private_key_pem" :value="__('Private key PEM')" class="!text-xs" />
                        <textarea id="new_certificate_private_key_pem" wire:model="new_certificate_private_key_pem" rows="4" class="dply-input mt-1 font-mono text-xs"></textarea>
                    </div>
                    <div>
                        <x-input-label for="new_certificate_chain_pem" :value="__('Chain PEM')" class="!text-xs" />
                        <textarea id="new_certificate_chain_pem" wire:model="new_certificate_chain_pem" rows="3" class="dply-input mt-1 font-mono text-xs"></textarea>
                    </div>
                </div>
            @endif

            <div class="grid gap-2 sm:grid-cols-2">
                <label class="flex items-start gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2 text-xs text-brand-ink">
                    <input type="checkbox" wire:model="new_certificate_force_skip_dns_checks" class="mt-0.5 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage/30" />
                    <span class="text-xs leading-relaxed text-brand-moss">{{ __('Skip DNS preflight checks when the challenge path allows it.') }}</span>
                </label>
                @if ($supportsHttp3Certificates)
                    <label class="flex items-start gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2 text-xs text-brand-ink">
                        <input type="checkbox" wire:model="new_certificate_enable_http3" class="mt-0.5 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage/30" />
                        <span class="text-xs leading-relaxed text-brand-moss">{{ __('Record HTTP/3 intent on hosts that support it.') }}</span>
                    </label>
                @endif
            </div>
        </div>
    </form>
</section>

<section class="{{ $card }}">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-lock-closed"
        :title="__('Existing certificates')"
        :count="trans_choice('{0} none|{1} :count|[2,*] :count', $certificateCount, ['count' => $certificateCount])"
        :note="__('Scope, provider, challenge path, and last output for retries and cleanup.')"
    >
        @if ($activeCertificateCount > 0 || $pendingCertificateCount > 0)
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-2xs text-brand-mist">
                    @if ($activeCertificateCount > 0)
                        <span class="inline-flex items-center gap-1">
                            <x-heroicon-o-check-circle class="h-3 w-3 text-emerald-600" />
                            {{ trans_choice('{1} :count active|[2,*] :count active', $activeCertificateCount, ['count' => $activeCertificateCount]) }}
                        </span>
                    @endif
                    @if ($pendingCertificateCount > 0)
                        <span class="inline-flex items-center gap-1">
                            <x-heroicon-o-clock class="h-3 w-3 text-amber-600" />
                            {{ trans_choice('{1} :count pending|[2,*] :count pending', $pendingCertificateCount, ['count' => $pendingCertificateCount]) }}
                        </span>
                    @endif
                </div>
            </x-slot:actions>
        @endif
    </x-workspace-panel-head>

    @if ($site->certificates->isEmpty())
        <div class="px-5 py-5 text-center sm:px-6">
            <p class="text-xs font-medium text-brand-ink">{{ __('No certificates have been requested yet.') }}</p>
            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Use the form above to request Let’s Encrypt or install an existing cert.') }}</p>
        </div>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($site->certificates as $certificate)
                @php
                    [$chipClasses, $chipIcon] = $statusChip($certificate->status);
                    $hostnames = $certificate->domainHostnames();
                @endphp
                <li class="px-5 py-2.5 sm:px-6" wire:key="cert-{{ $certificate->id }}">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10">
                            <x-heroicon-o-lock-closed class="h-3 w-3" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="text-xs font-semibold text-brand-ink">{{ ucfirst($certificate->provider_type) }} · {{ ucfirst($certificate->scope_type) }}</p>
                                <span class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1 {{ $chipClasses }}">
                                    <x-dynamic-component :component="$chipIcon" class="h-2.5 w-2.5" />
                                    {{ $certificate->status }}
                                </span>
                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                                    {{ $certificate->challenge_type }}
                                </span>
                            </div>

                            @if (! empty($hostnames))
                                <p class="mt-0.5 break-all font-mono text-xs text-brand-mist">
                                    {{ implode(', ', $hostnames) }}
                                </p>
                            @endif

                            @if ($certificate->last_output)
                                <details class="mt-1">
                                    <summary class="cursor-pointer list-none text-2xs font-medium uppercase tracking-wide text-brand-mist hover:text-brand-ink">
                                        <span class="inline-flex items-center gap-1">
                                            <x-heroicon-o-chevron-down class="h-3 w-3" />
                                            {{ __('Last output') }}
                                        </span>
                                    </summary>
                                    <pre class="mt-1.5 max-h-40 overflow-auto rounded-lg bg-brand-sand/15 px-2.5 py-1.5 font-mono text-xs leading-relaxed text-brand-moss">{{ \Illuminate\Support\Str::limit($certificate->last_output, 800) }}</pre>
                                </details>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5 self-start sm:self-center">
                            @if (in_array($certificate->status, [SiteCertificate::STATUS_FAILED, SiteCertificate::STATUS_EXPIRED], true))
                                <button
                                    type="button"
                                    wire:click="repairCertificate('{{ $certificate->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="repairCertificate('{{ $certificate->id }}')"
                                    class="{{ $btnOutline }} !border-brand-forest/30 !bg-brand-forest/5 !text-brand-forest hover:!bg-brand-forest/10"
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
                                class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-transparent text-brand-mist hover:border-red-200 hover:bg-red-50 hover:text-red-700"
                                title="{{ __('Remove certificate') }}"
                            >
                                <x-heroicon-o-trash class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6">
        <x-cli-snippet :commands="[
            ['label' => __('Check status'), 'command' => 'dply sites:ssl:status '.$site->slug],
            ['label' => __('Issue cert'), 'command' => 'dply sites:ssl:issue '.$site->slug],
            ['label' => __('Force renew'), 'command' => 'dply sites:ssl:renew '.$site->slug],
        ]" />
    </div>
</section>
