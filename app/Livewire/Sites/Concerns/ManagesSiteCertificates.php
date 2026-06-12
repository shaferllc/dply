<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\ExecuteSiteCertificateJob;
use App\Jobs\ScanServerLiveCertsJob;
use App\Models\SiteCertificate;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Services\Certificates\CertificateRepairService;
use App\Services\Certificates\CertificateRequestService;
use App\Services\Servers\WebserverCertsAggregator;
use App\Services\Sites\SiteReachabilityChecker;
use App\Support\HostnameValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteCertificates
{
    public string $new_certificate_scope = SiteCertificate::SCOPE_CUSTOMER;

    public string $new_certificate_provider_type = SiteCertificate::PROVIDER_LETSENCRYPT;

    public string $new_certificate_challenge_type = SiteCertificate::CHALLENGE_HTTP;

    public string $new_certificate_domains = '';

    public ?string $new_certificate_preview_domain_id = null;

    public ?string $new_certificate_provider_credential_id = null;

    public string $new_certificate_dns_provider = 'digitalocean';

    public bool $new_certificate_force_skip_dns_checks = false;

    public bool $new_certificate_enable_http3 = false;

    public string $new_certificate_certificate_pem = '';

    public string $new_certificate_private_key_pem = '';

    public string $new_certificate_chain_pem = '';

    public ?string $quick_ssl_domain_hostname = null;

    public string $quick_ssl_provider_type = SiteCertificate::PROVIDER_LETSENCRYPT;

    /** Last reachability result for the hostname in the quick-SSL modal (null = not checked). */
    public ?array $quick_ssl_reachability = null;

    /** Operator override to request SSL anyway when the domain isn't reachable yet (DNS propagating). */
    public bool $quick_ssl_force = false;

    /**
     * Live Caddy-managed certificates read from the box. Caddy obtains and
     * renews these itself (automatic HTTPS), so they never flow through
     * certbot and have no SiteCertificate paths — we surface the real
     * issuer/expiry by scanning Caddy's data dir over SSH.
     *
     * @var list<array<string, mixed>>
     */
    public array $caddy_managed_certs = [];

    public bool $caddy_managed_certs_loaded = false;

    public bool $caddy_managed_certs_scanning = false;

    public bool $caddy_managed_certs_unreadable = false;

    public ?string $caddy_managed_certs_error = null;

    public ?string $caddy_managed_certs_scanned_at_iso = null;

    /** Captured scan progress frames (shared sweep); replayed on completion. @var list<array{t:int,line:string}> */
    public array $caddy_managed_certs_progress = [];

    public function openQuickDomainSslModal(string $hostname): void
    {
        $this->authorize('update', $this->site);
        $this->site->loadMissing(['domains', 'domainAliases']);

        $normalized = strtolower(trim($hostname));
        $inDomain = $this->site->domains->contains(fn (SiteDomain $domain): bool => strtolower($domain->hostname) === $normalized);
        $inAlias = $this->site->domainAliases->contains(fn (SiteDomainAlias $alias): bool => strtolower($alias->hostname) === $normalized);
        if (! $inDomain && ! $inAlias) {
            abort(404);
        }

        $this->quick_ssl_domain_hostname = $normalized;
        $this->quick_ssl_provider_type = SiteCertificate::PROVIDER_LETSENCRYPT;
        $this->quick_ssl_force = false;
        $this->quick_ssl_reachability = app(SiteReachabilityChecker::class)->checkHostname($this->site, $normalized);
        $this->dispatch('open-modal', 'quick-domain-ssl-modal');
    }

    public function recheckQuickDomainSslReachability(): void
    {
        $this->authorize('update', $this->site);

        $hostname = strtolower(trim((string) $this->quick_ssl_domain_hostname));
        if ($hostname === '') {
            return;
        }

        $this->quick_ssl_reachability = app(SiteReachabilityChecker::class)->checkHostname($this->site, $hostname);
    }

    public function closeQuickDomainSslModal(): void
    {
        $this->quick_ssl_reachability = null;
        $this->quick_ssl_force = false;
        $this->dispatch('close-modal', 'quick-domain-ssl-modal');
    }

    public function quickAddDomainSsl(CertificateRequestService $certificateRequestService): void
    {
        $this->authorize('update', $this->site);
        $this->site->loadMissing(['domains', 'domainAliases', 'certificates']);

        $validated = $this->validate([
            'quick_ssl_domain_hostname' => ['required', 'string'],
            'quick_ssl_provider_type' => ['required', Rule::in([
                SiteCertificate::PROVIDER_LETSENCRYPT,
                SiteCertificate::PROVIDER_ZEROSSL,
            ])],
        ]);

        $hostname = strtolower(trim($validated['quick_ssl_domain_hostname']));
        $inDomain = $this->site->domains->contains(fn (SiteDomain $domain): bool => strtolower($domain->hostname) === $hostname);
        $inAlias = $this->site->domainAliases->contains(fn (SiteDomainAlias $alias): bool => strtolower($alias->hostname) === $hostname);
        if (! $inDomain && ! $inAlias) {
            $this->addError('quick_ssl_domain_hostname', __('Choose a domain or alias that belongs to this site.'));

            return;
        }

        $existing = $this->site->certificates->contains(function (SiteCertificate $certificate) use ($hostname): bool {
            return in_array($certificate->status, [
                SiteCertificate::STATUS_PENDING,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_INSTALLING,
                SiteCertificate::STATUS_ACTIVE,
            ], true) && in_array($hostname, $certificate->domainHostnames(), true);
        });

        if ($existing) {
            $this->toastError(__('SSL is already configured or in progress for :domain.', ['domain' => $hostname]));
            $this->closeQuickDomainSslModal();

            return;
        }

        // An HTTP-01 challenge only succeeds if the domain already resolves to
        // this server and answers on port 80 — otherwise the CA can't reach the
        // challenge file. Gate the request on a live reachability check so we
        // don't queue a cert that's guaranteed to fail. The operator can still
        // override (e.g. DNS is mid-propagation) via the modal checkbox.
        $reachability = app(SiteReachabilityChecker::class)->checkHostname($this->site, $hostname);
        $this->quick_ssl_reachability = $reachability;
        if (! $reachability['ok'] && ! $this->quick_ssl_force) {
            $this->addError('quick_ssl_domain_hostname', $reachability['error']
                ?? __('This domain is not reachable here yet — point it at this server before requesting SSL.'));

            return;
        }

        $certificate = $certificateRequestService->create([
            'site_id' => $this->site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => $validated['quick_ssl_provider_type'],
            'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
            'domains_json' => [$hostname],
            'status' => SiteCertificate::STATUS_PENDING,
            'requested_settings' => [
                'source' => 'quick_domain_ssl_modal',
            ],
        ]);

        ExecuteSiteCertificateJob::dispatch($certificate->id);
        $providerLabel = $validated['quick_ssl_provider_type'] === SiteCertificate::PROVIDER_ZEROSSL
            ? 'ZeroSSL'
            : 'Let\'s Encrypt';
        $this->toastSuccess(__('SSL request queued for :domain via :provider.', [
            'domain' => $hostname,
            'provider' => $providerLabel,
        ]));

        $this->site->load('certificates');
        $this->closeQuickDomainSslModal();
    }

    public function createCertificateRequest(CertificateRequestService $certificateRequestService): void
    {
        $this->authorize('update', $this->site);

        $validated = $this->validate([
            'new_certificate_scope' => ['required', Rule::in([SiteCertificate::SCOPE_CUSTOMER, SiteCertificate::SCOPE_PREVIEW])],
            'new_certificate_provider_type' => ['required', Rule::in([
                SiteCertificate::PROVIDER_LETSENCRYPT,
                SiteCertificate::PROVIDER_IMPORTED,
                SiteCertificate::PROVIDER_CSR,
                SiteCertificate::PROVIDER_ZEROSSL,
            ])],
            'new_certificate_challenge_type' => ['required', Rule::in([
                SiteCertificate::CHALLENGE_HTTP,
                SiteCertificate::CHALLENGE_DNS,
                SiteCertificate::CHALLENGE_IMPORTED,
                SiteCertificate::CHALLENGE_MANUAL,
            ])],
            'new_certificate_domains' => ['nullable', 'string'],
            'new_certificate_preview_domain_id' => ['nullable', 'string', Rule::exists('site_preview_domains', 'id')],
            'new_certificate_provider_credential_id' => ['nullable', 'string', Rule::exists('provider_credentials', 'id')],
            'new_certificate_dns_provider' => ['nullable', 'string', 'max:255'],
            'new_certificate_force_skip_dns_checks' => ['boolean'],
            'new_certificate_enable_http3' => ['boolean'],
            'new_certificate_certificate_pem' => ['nullable', 'string'],
            'new_certificate_private_key_pem' => ['nullable', 'string'],
            'new_certificate_chain_pem' => ['nullable', 'string'],
        ]);

        $domains = $this->normalizeCertificateDomains($validated);
        if ($domains === []) {
            $this->addError('new_certificate_domains', __('Add at least one hostname for this certificate request.'));

            return;
        }

        if (
            $validated['new_certificate_provider_type'] === SiteCertificate::PROVIDER_ZEROSSL
            && $validated['new_certificate_challenge_type'] !== SiteCertificate::CHALLENGE_HTTP
        ) {
            $this->toastError('ZeroSSL currently supports the HTTP challenge flow only.');

            return;
        }

        $providerCredentialId = $validated['new_certificate_provider_credential_id'] ?: null;
        $autoDnsCred = $this->site->dnsAutomationCredential();
        if (
            $providerCredentialId === null
            && $validated['new_certificate_challenge_type'] === SiteCertificate::CHALLENGE_DNS
            && ($validated['new_certificate_dns_provider'] ?: 'digitalocean') === 'digitalocean'
            && $autoDnsCred?->provider === 'digitalocean'
        ) {
            $providerCredentialId = $autoDnsCred->id;
        }

        $certificate = $certificateRequestService->create([
            'site_id' => $this->site->id,
            'preview_domain_id' => $validated['new_certificate_scope'] === SiteCertificate::SCOPE_PREVIEW
                ? $validated['new_certificate_preview_domain_id']
                : null,
            'provider_credential_id' => $providerCredentialId,
            'scope_type' => $validated['new_certificate_scope'],
            'provider_type' => $validated['new_certificate_provider_type'],
            'challenge_type' => $validated['new_certificate_challenge_type'],
            'dns_provider' => $validated['new_certificate_challenge_type'] === SiteCertificate::CHALLENGE_DNS
                ? ($validated['new_certificate_dns_provider'] ?: null)
                : null,
            'domains_json' => $domains,
            'status' => SiteCertificate::STATUS_PENDING,
            'force_skip_dns_checks' => (bool) $validated['new_certificate_force_skip_dns_checks'],
            'enable_http3' => $this->server->hostCapabilities()->supportsHttp3Certificates()
                ? (bool) $validated['new_certificate_enable_http3']
                : false,
            'certificate_pem' => $validated['new_certificate_certificate_pem'] ?: null,
            'private_key_pem' => $validated['new_certificate_private_key_pem'] ?: null,
            'chain_pem' => $validated['new_certificate_chain_pem'] ?: null,
            'requested_settings' => [
                'skip_dns_checks' => (bool) $validated['new_certificate_force_skip_dns_checks'],
                'http3_requested' => (bool) $validated['new_certificate_enable_http3'],
            ],
        ]);

        try {
            if (in_array($certificate->provider_type, [SiteCertificate::PROVIDER_IMPORTED, SiteCertificate::PROVIDER_CSR], true)) {
                // Imported / CSR-backed certs are processed inline because there's no
                // long-running ACME or remote install step — the cert material is
                // already in hand and the service just persists/installs it.
                $certificateRequestService->execute($certificate);
            } else {
                ExecuteSiteCertificateJob::dispatch($certificate->id);
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
            $this->site->load('certificates');

            return;
        }

        $this->resetCertificateRequestForm();
        $this->toastSuccess(__('Certificate request saved.'));
        $this->site->load('certificates');
    }

    public function removeCertificate(string $certificateId, CertificateRequestService $certificateRequestService): void
    {
        $this->authorize('update', $this->site);

        $certificate = $this->site->certificates()->findOrFail($certificateId);
        $certificateRequestService->removeArtifacts($certificate);
        $certificate->delete();

        $this->toastSuccess('Certificate removed.');
        $this->site->load('certificates');
    }

    /**
     * Whether this site is fronted by Caddy terminating TLS with its built-in
     * automatic HTTPS — i.e. Caddy owns the certificate, not certbot. Edge-proxy
     * layouts (Envoy/HAProxy/Traefik in front of a Caddy backend) terminate TLS
     * at the front, so they don't count.
     */
    public function siteUsesCaddyAutoHttps(): bool
    {
        return $this->site->webserver() === 'caddy' && ! $this->server->hasEdgeProxy();
    }

    /**
     * Surface the live Caddy-managed certificate(s) for this site's hostnames.
     * The cross-engine SSH sweep runs async in {@see ScanServerLiveCertsJob}
     * (shared with the server cert surfaces); this reads the cached result and
     * filters it to this site, polling via {@see pollCaddyManagedCerts()} while a
     * scan is in flight — SSH never runs in the request.
     */
    public function loadCaddyManagedCerts(bool $forceFresh = false): void
    {
        $this->authorize('view', $this->site);

        if (! $this->siteUsesCaddyAutoHttps()) {
            $this->caddy_managed_certs = [];
            $this->caddy_managed_certs_loaded = true;
            $this->caddy_managed_certs_scanning = false;

            return;
        }

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->caddy_managed_certs_error = __('Provisioning and SSH must be ready before reading Caddy certificates.');
            $this->caddy_managed_certs_loaded = true;
            $this->caddy_managed_certs_scanning = false;

            return;
        }

        $aggregator = app(WebserverCertsAggregator::class);
        $cached = $forceFresh ? null : $aggregator->cached($this->server);
        if ($cached !== null) {
            $this->applyCaddyManagedCerts($cached);

            return;
        }

        $aggregator->dispatchScan($this->server, $forceFresh);
        $this->caddy_managed_certs_scanning = true;
        $this->caddy_managed_certs_loaded = false;
        $this->caddy_managed_certs_error = null;
        $this->caddy_managed_certs_progress = $aggregator->progress($this->server);
    }

    /** Driven by wire:poll while a scan is in flight; resolves once the job caches a result. */
    public function pollCaddyManagedCerts(): void
    {
        if (! $this->caddy_managed_certs_scanning) {
            return;
        }

        $aggregator = app(WebserverCertsAggregator::class);
        $this->caddy_managed_certs_progress = $aggregator->progress($this->server);
        $cached = $aggregator->cached($this->server);
        if ($cached !== null) {
            $this->applyCaddyManagedCerts($cached);
        }
    }

    public function refreshCaddyManagedCerts(): void
    {
        $this->loadCaddyManagedCerts(forceFresh: true);
    }

    /**
     * Filter the cross-engine sweep down to this site's Caddy-managed certs.
     *
     * @param  array{certs: list<array<string, mixed>>, scanned_at: ?CarbonImmutable, unreadable: bool}  $result
     */
    private function applyCaddyManagedCerts(array $result): void
    {
        $hostnames = collect($this->site->webserverHostnames())
            ->filter()
            ->map(fn (string $host): string => strtolower(trim($host)))
            ->filter()
            ->values()
            ->all();

        $certs = array_values(array_filter($result['certs'], function (array $row) use ($hostnames): bool {
            if (($row['engine_hint'] ?? null) !== 'caddy') {
                return false;
            }

            $haystack = strtolower(($row['path'] ?? '').' '.($row['subject'] ?? ''));
            foreach ($hostnames as $host) {
                if (str_contains($haystack, $host)) {
                    return true;
                }
            }

            return false;
        }));

        $this->caddy_managed_certs = array_map(function (array $row): array {
            $row['expires_at'] = $row['expires_at'] instanceof CarbonImmutable
                ? $row['expires_at']->toIso8601String()
                : null;

            return $row;
        }, $certs);
        $this->caddy_managed_certs_scanned_at_iso = $result['scanned_at'] instanceof CarbonImmutable
            ? $result['scanned_at']->toIso8601String()
            : null;
        $this->caddy_managed_certs_unreadable = (bool) $result['unreadable'];
        $this->caddy_managed_certs_loaded = true;
        $this->caddy_managed_certs_scanning = false;
        $this->caddy_managed_certs_error = null;
    }

    public function retryCertificate(string $certificateId, CertificateRepairService $repairService): void
    {
        $this->repairCertificate($certificateId, $repairService);
    }

    public function repairCertificate(string $certificateId, CertificateRepairService $repairService): void
    {
        $this->authorize('update', $this->site);

        $certificate = $this->site->certificates()->findOrFail($certificateId);

        try {
            // Seed before dispatch so the certificates-section SSL banner appears
            // on this re-render; ExecuteSiteCertificateJob::beginConsoleAction()
            // reuses the row instead of waiting for the worker to create one.
            $run = $this->seedQueuedConsoleAction('ssl');

            $repairService->repair($this->site, $certificate, auth()->id(), (string) $run->id);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
            $this->site->load('certificates');

            return;
        }

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('Certificate repair finished.'),
            __('Certificate repair did not finish.'),
        );
        $this->toastConsoleActionQueued();
        $this->site->load('certificates');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    private function normalizeCertificateDomains(array $validated): array
    {
        if (($validated['new_certificate_scope'] ?? null) === SiteCertificate::SCOPE_PREVIEW) {
            $previewDomain = $this->site->previewDomains()->find($validated['new_certificate_preview_domain_id']);

            return $previewDomain ? [$previewDomain->hostname] : [];
        }

        $typedDomains = collect(preg_split('/[\s,]+/', (string) ($validated['new_certificate_domains'] ?? '')) ?: [])
            ->filter(fn (mixed $hostname): bool => is_string($hostname) && HostnameValidator::isValid($hostname))
            ->map(fn (string $hostname): string => strtolower(trim($hostname)))
            ->unique()
            ->values()
            ->all();

        if ($typedDomains !== []) {
            return $typedDomains;
        }

        return $this->site->sslIssuanceHostnames();
    }

    private function resetCertificateRequestForm(): void
    {
        $this->new_certificate_scope = SiteCertificate::SCOPE_CUSTOMER;
        $this->new_certificate_provider_type = SiteCertificate::PROVIDER_LETSENCRYPT;
        $this->new_certificate_challenge_type = SiteCertificate::CHALLENGE_HTTP;
        $this->new_certificate_domains = '';
        $this->new_certificate_preview_domain_id = null;
        $this->new_certificate_provider_credential_id = null;
        $this->new_certificate_dns_provider = 'digitalocean';
        $this->new_certificate_force_skip_dns_checks = false;
        $this->new_certificate_enable_http3 = false;
        $this->new_certificate_certificate_pem = '';
        $this->new_certificate_private_key_pem = '';
        $this->new_certificate_chain_pem = '';
    }
}
