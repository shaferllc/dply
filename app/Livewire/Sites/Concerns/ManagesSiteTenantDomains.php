<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\ProvisionTenantTestingHostnameJob;
use App\Models\SiteCertificate;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\SiteTenantDomain;
use App\Modules\Certificates\Jobs\ExecuteSiteCertificateJob;
use App\Modules\Certificates\Services\CertificateRequestService;
use App\Services\Sites\SiteReachabilityChecker;
use App\Services\Sites\TenantDnsProvisioner;
use App\Support\HostnameValidator;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteTenantDomains
{
    public string $new_tenant_hostname = '';

    public string $new_tenant_key = '';

    public string $new_tenant_label = '';

    /**
     * Free-text comment for tenant rows. Replaces the legacy `notes` field
     * (column dropped in the routing-tables migration); existing notes were
     * backfilled into `comment`.
     */
    public string $new_tenant_comment = '';

    /** Multi-line bulk paste — `hostname,key,label` per line. */
    public string $bulk_tenant_input = '';

    /** When non-null, the tenants list shows an inline edit form for this row. */
    public ?string $editing_tenant_id = null;

    public string $editing_tenant_hostname = '';

    public string $editing_tenant_key = '';

    public string $editing_tenant_label = '';

    public string $editing_tenant_comment = '';

    public function addTenantDomain(): void
    {
        $this->authorize('update', $this->site);

        $validated = $this->validate([
            'new_tenant_hostname' => [
                'required',
                'string',
                'max:255',
                Rule::unique('site_tenant_domains', 'hostname'),
                Rule::unique('site_domains', 'hostname'),
                Rule::unique('site_domain_aliases', 'hostname'),
                Rule::unique('site_preview_domains', 'hostname'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid tenant domain like customer.example.com.');
                    }
                },
            ],
            'new_tenant_key' => ['nullable', 'string', 'max:255'],
            'new_tenant_label' => ['nullable', 'string', 'max:255'],
            'new_tenant_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $tenant = SiteTenantDomain::query()->create([
            'site_id' => $this->site->id,
            'hostname' => strtolower(trim($validated['new_tenant_hostname'])),
            'tenant_key' => trim((string) ($validated['new_tenant_key'] ?? '')) ?: null,
            'label' => trim((string) ($validated['new_tenant_label'] ?? '')) ?: null,
            'comment' => trim((string) ($validated['new_tenant_comment'] ?? '')) ?: null,
            'sort_order' => (int) ($this->site->tenantDomains()->max('sort_order') ?? 0) + 1,
        ]);

        $this->new_tenant_hostname = '';
        $this->new_tenant_key = '';
        $this->new_tenant_label = '';
        $this->new_tenant_comment = '';
        $this->site->load('tenantDomains');
        $this->finalizeRoutingMutation('Tenant domain added.');

        // If a connected DNS credential controls this hostname's zone, point it at
        // the server automatically — so the tenant "just works" without the
        // operator hand-creating the A record. Silent when no credential covers it.
        $this->provisionTenantCustomDns($tenant, quietWhenNoCredential: true);
    }

    public function confirmRemoveTenantDomain(string $tenantDomainId): void
    {
        $this->authorize('update', $this->site);
        $this->openConfirmActionModal(
            'removeTenantDomain',
            [$tenantDomainId],
            __('Remove tenant domain'),
            __('Remove this tenant domain? Your application is responsible for resolving traffic for it; that traffic stops being routed after the next webserver apply.'),
            __('Remove tenant'),
            true,
        );
    }

    public function removeTenantDomain(string $tenantDomainId): void
    {
        $this->authorize('update', $this->site);

        $tenant = $this->site->tenantDomains()->findOrFail($tenantDomainId);

        // If the tenant has a managed testing hostname, tear its DNS record down
        // and delete the row from a queued job (DNS API + webserver re-apply both
        // belong off the web request); otherwise delete inline as before.
        if ($tenant->testingHostname() !== null) {
            ProvisionTenantTestingHostnameJob::dispatch(
                (string) $this->site->id,
                (string) $tenant->id,
                remove: true,
                userId: (string) (auth()->id() ?? ''),
                deleteTenantRow: true,
            );
            $this->toastSuccess(__('Removing tenant domain and its testing hostname…'));

            return;
        }

        $tenant->delete();
        $this->site->load('tenantDomains');
        $this->finalizeRoutingMutation('Tenant domain removed.');
    }

    /**
     * Provision a managed testing-domain hostname for this tenant so the app can
     * be reached as the tenant on a dply testing zone before the customer's DNS
     * is pointed. Queued: it makes a DNS API call then re-applies the vhost.
     */
    public function provisionTenantTestingHostname(string $tenantDomainId): void
    {
        $this->authorize('update', $this->site);

        $tenant = $this->site->tenantDomains()->findOrFail($tenantDomainId);

        ProvisionTenantTestingHostnameJob::dispatch(
            (string) $this->site->id,
            (string) $tenant->id,
            remove: false,
            userId: (string) (auth()->id() ?? ''),
        );

        $this->toastSuccess(__('Creating a testing URL for this tenant… DNS and the webserver update in the background.'));
    }

    public function removeTenantTestingHostname(string $tenantDomainId): void
    {
        $this->authorize('update', $this->site);

        $tenant = $this->site->tenantDomains()->findOrFail($tenantDomainId);

        ProvisionTenantTestingHostnameJob::dispatch(
            (string) $this->site->id,
            (string) $tenant->id,
            remove: true,
            userId: (string) (auth()->id() ?? ''),
        );

        $this->toastSuccess(__('Removing this tenant’s testing URL…'));
    }

    public function editTenantDomain(string $tenantDomainId): void
    {
        $this->authorize('update', $this->site);
        $tenant = $this->site->tenantDomains()->findOrFail($tenantDomainId);
        $this->editing_tenant_id = (string) $tenant->id;
        $this->editing_tenant_hostname = (string) $tenant->hostname;
        $this->editing_tenant_key = (string) ($tenant->tenant_key ?? '');
        $this->editing_tenant_label = (string) ($tenant->label ?? '');
        $this->editing_tenant_comment = (string) ($tenant->comment ?? '');
    }

    public function cancelEditTenantDomain(): void
    {
        $this->editing_tenant_id = null;
        $this->editing_tenant_hostname = '';
        $this->editing_tenant_key = '';
        $this->editing_tenant_label = '';
        $this->editing_tenant_comment = '';
    }

    public function saveEditedTenantDomain(): void
    {
        $this->authorize('update', $this->site);
        if ($this->editing_tenant_id === null) {
            return;
        }
        $tenant = $this->site->tenantDomains()->findOrFail($this->editing_tenant_id);
        $this->validate([
            'editing_tenant_hostname' => [
                'required',
                'string',
                'max:255',
                Rule::unique('site_tenant_domains', 'hostname')->ignore($tenant->id),
                Rule::unique('site_domains', 'hostname'),
                Rule::unique('site_domain_aliases', 'hostname'),
                Rule::unique('site_preview_domains', 'hostname'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid tenant domain like customer.example.com.');
                    }
                },
            ],
            'editing_tenant_key' => ['nullable', 'string', 'max:255'],
            'editing_tenant_label' => ['nullable', 'string', 'max:255'],
            'editing_tenant_comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $tenant->forceFill([
            'hostname' => strtolower(trim($this->editing_tenant_hostname)),
            'tenant_key' => trim($this->editing_tenant_key) ?: null,
            'label' => trim($this->editing_tenant_label) ?: null,
            'comment' => trim($this->editing_tenant_comment) ?: null,
        ])->save();

        $this->cancelEditTenantDomain();
        $this->site->load('tenantDomains');
        $this->finalizeRoutingMutation('Tenant domain updated.');

        // Point the (possibly changed) hostname at the server when a connected
        // credential owns its zone — so editing a tenant "just works" like adding.
        $this->provisionTenantCustomDns($tenant->fresh(), quietWhenNoCredential: true);
    }

    /**
     * Bulk paste tenants — `hostname,key,label` per line; key/label optional.
     * Hostnames already present anywhere in the routing namespace are skipped
     * (same convention as the alias bulk import).
     */
    public function bulkImportTenantDomains(): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['bulk_tenant_input' => 'required|string|max:65535']);

        $lines = preg_split('/\r\n|\r|\n/', trim($this->bulk_tenant_input)) ?: [];
        $rows = [];
        foreach ($lines as $i => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode(',', $line, 3));
            $hostname = strtolower($parts[0] ?? '');
            $key = $parts[1] ?? null;
            $label = $parts[2] ?? null;
            if ($hostname === '' || ! HostnameValidator::isValid($hostname)) {
                $this->addError('bulk_tenant_input', sprintf('Line %d: "%s" is not a valid hostname.', $i + 1, $hostname));

                return;
            }
            $rows[] = [
                'hostname' => $hostname,
                'key' => $key !== null && $key !== '' ? $key : null,
                'label' => $label !== null && $label !== '' ? $label : null,
            ];
        }

        $taken = collect()
            ->merge(SiteDomain::query()->pluck('hostname'))
            ->merge(SiteDomainAlias::query()->pluck('hostname'))
            ->merge(SitePreviewDomain::query()->pluck('hostname'))
            ->merge(SiteTenantDomain::query()->pluck('hostname'))
            ->map(fn ($h) => strtolower((string) $h))
            ->unique()
            ->all();

        $sortBase = (int) ($this->site->tenantDomains()->max('sort_order') ?? 0);
        $imported = 0;
        foreach ($rows as $row) {
            if (in_array($row['hostname'], $taken, true)) {
                continue;
            }
            SiteTenantDomain::query()->create([
                'site_id' => $this->site->id,
                'hostname' => $row['hostname'],
                'tenant_key' => $row['key'],
                'label' => $row['label'],
                'sort_order' => ++$sortBase,
            ]);
            $imported++;
        }

        $this->bulk_tenant_input = '';
        $this->site->load('tenantDomains');
        $this->finalizeRoutingMutation(__(':count tenant(s) imported.', ['count' => $imported]));
    }

    /**
     * Issue a per-tenant HTTP-01 certificate for a tenant's CUSTOM domain. Each
     * tenant onboards over time at its own arbitrary hostname, so they get their
     * own cert (rather than one ever-growing SAN cert where a single tenant's bad
     * DNS would break everyone). Gated on reachability so we don't queue a cert
     * that's guaranteed to fail at the CA; a Cloudflare-proxied tenant still
     * passes (the HTTP-01 challenge routes through the proxy to this origin).
     */
    public function issueTenantCertificate(string $tenantDomainId): void
    {
        $this->authorize('update', $this->site);

        $tenant = $this->site->tenantDomains()->find($tenantDomainId);
        if ($tenant === null) {
            return;
        }

        $hostname = strtolower(trim((string) $tenant->hostname));
        if ($hostname === '') {
            return;
        }

        $alreadyCovered = $this->site->certificates()
            ->whereIn('status', [
                SiteCertificate::STATUS_PENDING,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_INSTALLING,
                SiteCertificate::STATUS_ACTIVE,
            ])
            ->get()
            ->contains(fn (SiteCertificate $certificate): bool => in_array($hostname, $certificate->domainHostnames(), true));
        if ($alreadyCovered) {
            $this->toastError(__('SSL is already configured or in progress for :host.', ['host' => $hostname]));

            return;
        }

        $reachability = app(SiteReachabilityChecker::class)->checkHostname($this->site, $hostname);
        if (! ($reachability['ok'] ?? false) && empty($reachability['behind_cloudflare'])) {
            $this->toastError($reachability['error']
                ?? __('“:host” isn’t pointed at this server yet — point its DNS here, then request SSL.', ['host' => $hostname]));

            return;
        }

        $certificate = app(CertificateRequestService::class)->create([
            'site_id' => $this->site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
            'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
            'domains_json' => [$hostname],
            'status' => SiteCertificate::STATUS_PENDING,
            'requested_settings' => [
                'source' => 'tenant_ssl',
                'tenant_domain_id' => (string) $tenant->id,
            ],
        ]);

        ExecuteSiteCertificateJob::dispatch((string) $certificate->id);
        $this->toastSuccess(__('SSL requested for :host.', ['host' => $hostname]));
        $this->site->load('certificates');
    }

    /**
     * Operator-triggered "Point DNS here" for a tenant's custom domain — creates
     * the A record at whichever connected provider hosts its zone.
     */
    public function provisionTenantDns(string $tenantDomainId): void
    {
        $this->authorize('update', $this->site);

        $tenant = $this->site->tenantDomains()->find($tenantDomainId);
        if ($tenant === null) {
            return;
        }

        $this->provisionTenantCustomDns($tenant, quietWhenNoCredential: false);
    }

    /**
     * Auto-point a tenant's custom domain at this server: resolve the connected
     * DNS credential that owns the hostname's zone and upsert an A record → the
     * server IP. No-ops (optionally quietly) when no connected credential covers
     * the zone — then the operator points DNS themselves.
     */
    private function provisionTenantCustomDns(SiteTenantDomain $tenant, bool $quietWhenNoCredential = false): void
    {
        $result = app(TenantDnsProvisioner::class)->ensure($this->site, $tenant);
        $host = (string) $tenant->hostname;

        if ($result['status'] === 'created') {
            $this->toastSuccess(__('Pointed “:host” at this server in :zone — DNS may take a few minutes to propagate, then add SSL.', ['host' => $host, 'zone' => $result['zone']]));
        } elseif ($result['status'] === 'no_credential' && ! $quietWhenNoCredential) {
            $this->toastWarning(__('No connected DNS credential controls “:host”’s zone — connect the provider that hosts it (e.g. Cloudflare), or point its DNS at this server manually.', ['host' => $host]));
        } elseif ($result['status'] === 'error') {
            $this->toastError(__('Could not create the DNS record for “:host”: :err', ['host' => $host, 'err' => $result['message']]));
        }
        // 'no_server_ip' / 'invalid' → silent (nothing actionable for the operator here).
    }
}
