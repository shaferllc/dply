<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SitePreviewDomain;
use App\Models\Workspace;
use App\Modules\Certificates\Jobs\ExecuteSiteCertificateJob;
use App\Modules\Certificates\Services\CertificateRequestService;
use App\Services\Sites\TestingHostnameProvisioner;
use App\Modules\Cloud\Services\AzureDnsService;
use App\Modules\Cloud\Cloudflare\CloudflareDnsService;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Modules\Cloud\Services\GcpDnsService;
use App\Modules\Cloud\Services\HetznerService;
use App\Modules\Cloud\Services\LinodeService;
use App\Modules\Cloud\Services\Route53Service;
use App\Support\HostnameValidator;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteDomainsGeneral
{
    public string $settings_primary_domain = '';

    public string $settings_document_root = '';

    public string $settings_site_name = '';

    public string $settings_site_slug = '';

    public ?string $project_workspace_id = null;

    public string $site_notes = '';

    public string $preview_primary_hostname = '';

    public string $preview_label = 'Managed preview';

    public bool $preview_auto_ssl = true;

    public bool $preview_https_redirect = true;

    /** Optional label captured in the "Add preview URL" popover. */
    public string $newPreviewLabel = '';

    /** Selected org DigitalOcean credential for DNS automation; empty string = organization default. */
    public string $settings_dns_provider_credential_id = '';

    /** DNS zone (apex) at the provider, e.g. example.com. Empty = use app default testing-domain pool. */
    public string $settings_dns_zone = '';

    private function syncGeneralSettingsForm(bool $skipRefresh = false): void
    {
        // Skip the refresh when the caller has just refreshed (mount path —
        // parent::mount → syncFormFromSite already pulled a fresh site, and after
        // $this->site->update() / load() the in-memory model is current).
        if (! $skipRefresh) {
            $this->site->refresh();
        }
        $this->settings_primary_domain = (string) optional($this->site->primaryDomain())->hostname;
        $this->settings_document_root = (string) ($this->site->document_root ?? '');
        $this->settings_site_name = (string) $this->site->name;
        $this->settings_site_slug = (string) $this->site->slug;
        $this->project_workspace_id = $this->site->workspace_id;
        $this->site_notes = (string) data_get($this->site->meta, 'notes', '');
    }

    private function syncPreviewSettingsForm(): void
    {
        $this->site->loadMissing('previewDomains');
        $previewDomain = $this->site->primaryPreviewDomain();
        $this->preview_primary_hostname = (string) ($previewDomain?->hostname ?? $this->site->testingHostname());
        $this->preview_label = (string) ($previewDomain?->label ?? 'Managed preview');
        $this->preview_auto_ssl = (bool) ($previewDomain?->auto_ssl ?? true);
        $this->preview_https_redirect = (bool) ($previewDomain?->https_redirect ?? true);
    }

    private function syncDnsSettingsForm(): void
    {
        $this->settings_dns_provider_credential_id = (string) ($this->site->dns_provider_credential_id ?? '');
        $savedZone = trim((string) ($this->site->dns_zone ?? ''));
        $this->settings_dns_zone = $savedZone !== '' ? strtolower($savedZone) : '';
        if ($this->settings_dns_zone === '') {
            $guess = $this->site->guessDnsZoneFromPrimaryHostname();
            if ($guess !== null) {
                $this->settings_dns_zone = $guess;
            }
        }
    }

    public function saveDnsSettings(): void
    {
        $this->authorize('update', $this->site);

        $this->validate([
            'settings_dns_provider_credential_id' => ['nullable', 'string', 'max:26'],
            'settings_dns_zone' => ['nullable', 'string', 'max:255'],
        ]);

        $rawCred = $this->settings_dns_provider_credential_id;
        $credentialId = is_string($rawCred) && $rawCred !== '' ? $rawCred : null;

        if ($credentialId !== null) {
            $ok = ProviderCredential::query()
                ->whereKey($credentialId)
                ->where('organization_id', $this->site->organization_id)
                ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
                ->exists();

            if (! $ok) {
                $this->addError('settings_dns_provider_credential_id', __('Choose a DNS provider credential that belongs to this organization.'));

                return;
            }
        }

        $zoneRaw = trim($this->settings_dns_zone);
        $zone = $zoneRaw !== '' ? strtolower($zoneRaw) : null;

        if ($zone !== null && ! HostnameValidator::isValid($zone)) {
            $this->addError('settings_dns_zone', __('Enter a valid DNS zone name like example.com.'));

            return;
        }

        if ($zone !== null) {
            $credForApi = $credentialId !== null
                ? ProviderCredential::query()
                    ->whereKey($credentialId)
                    ->where('organization_id', $this->site->organization_id)
                    ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
                    ->first()
                : ProviderCredential::query()
                    ->where('organization_id', $this->site->organization_id)
                    ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
                    ->latest('updated_at')
                    ->first();

            $appDoToken = trim((string) config('services.digitalocean.token'));

            if ($credForApi === null && $appDoToken === '') {
                $this->addError('settings_dns_zone', __('Add a DNS provider credential under Server providers (DigitalOcean, Hetzner, Linode, Vultr, AWS, Google Cloud, Azure, or Cloudflare), or configure an app-level DigitalOcean token, to use a custom DNS zone.'));

                return;
            }

            try {
                if ($credForApi !== null) {
                    if ($credForApi->provider === 'digitalocean') {
                        $service = new DigitalOceanService($credForApi);
                        if (! $service->domainExistsInAccount($zone)) {
                            $this->addError('settings_dns_zone', __('That domain was not found in this DigitalOcean account. Add it under DigitalOcean Networking → Domains first.'));

                            return;
                        }
                    } elseif ($credForApi->provider === 'hetzner') {
                        $hetzner = new HetznerService($credForApi);
                        if (! $hetzner->zoneExists($zone)) {
                            $this->addError('settings_dns_zone', __('That zone was not found in this Hetzner Cloud project. Add it under Hetzner Console → DNS first.'));

                            return;
                        }
                    } elseif ($credForApi->provider === 'linode') {
                        $linode = new LinodeService($credForApi);
                        if (! $linode->domainExists($zone)) {
                            $this->addError('settings_dns_zone', __('That domain was not found in this Linode account. Add it under Linode → Domains first.'));

                            return;
                        }
                    } elseif ($credForApi->provider === 'vultr') {
                        $vultr = new VultrService($credForApi);
                        if (! $vultr->domainExists($zone)) {
                            $this->addError('settings_dns_zone', __('That domain was not found in this Vultr account. Add it under Vultr → DNS first.'));

                            return;
                        }
                    } elseif ($credForApi->provider === 'aws') {
                        $route53 = new Route53Service($credForApi);
                        if (! $route53->hostedZoneExists($zone)) {
                            $this->addError('settings_dns_zone', __('That hosted zone was not found in this AWS account. Create it in Route 53 first.'));

                            return;
                        }
                    } elseif ($credForApi->provider === 'gcp') {
                        $gcpDns = new GcpDnsService($credForApi);
                        if (! $gcpDns->zoneExists($zone)) {
                            $this->addError('settings_dns_zone', __('That zone was not found in this Google Cloud project. Add a Cloud DNS managed zone first.'));

                            return;
                        }
                    } elseif ($credForApi->provider === 'azure') {
                        $azure = new AzureDnsService($credForApi);
                        if (! $azure->zoneExists($zone)) {
                            $this->addError('settings_dns_zone', __('That zone was not found in this Azure account. Add it under Azure DNS first.'));

                            return;
                        }
                    } elseif ($credForApi->provider === 'cloudflare') {
                        $cf = new CloudflareDnsService($credForApi);
                        $cf->verifyToken();
                        if (! $cf->zoneExists($zone)) {
                            $this->addError('settings_dns_zone', __('That zone was not found in this Cloudflare account. Add the site to Cloudflare DNS first.'));

                            return;
                        }
                    }
                } else {
                    $service = new DigitalOceanService($appDoToken);
                    if (! $service->domainExistsInAccount($zone)) {
                        $this->addError('settings_dns_zone', __('That domain was not found for the app-level DigitalOcean token.'));

                        return;
                    }
                }
            } catch (\Throwable $e) {
                $this->addError('settings_dns_zone', $e->getMessage());

                return;
            }
        }

        $this->site->update([
            'dns_provider_credential_id' => $credentialId,
            'dns_zone' => $zone,
        ]);
        $this->syncDnsSettingsForm();
        $this->toastSuccess(__('DNS settings saved.'));
    }

    /**
     * Update the site's web directory (document_root). The primary hostname is
     * intentionally edited from Routing > Domains now — keeping the cascade
     * (cert re-issue, container backend cycle) next to its trigger.
     */
    /**
     * Re-apply the site's nginx vhost without changing any setting. The recovery
     * for "site deploys but 502s" — a missing/stale vhost falling through to the
     * default server. Streams the apply over the page's console banner.
     */
    public function rebuildWebserverConfig(): void
    {
        $this->authorize('update', $this->site);

        $this->finalizeRoutingMutation(
            __('Webserver config rebuilt — the site vhost was re-applied.'),
            __('Rebuilding webserver config …'),
        );
    }

    public function saveWebDirectory(): void
    {
        $this->authorize('update', $this->site);

        $validated = $this->validate([
            'settings_document_root' => ['required', 'string', 'max:500'],
        ]);

        $this->site->update([
            'document_root' => trim($validated['settings_document_root']),
        ]);

        $this->syncGeneralSettingsForm();
        $this->finalizeRoutingMutation('Web directory saved.');
    }

    /**
     * Update the site display name and slug. Mirrors `dply:site:rename` semantics
     * (the CLI command at app/Console/Commands/RenameSiteCommand.php): updates
     * the row only — the on-disk path under `/home/dply/<domain>` is intentionally
     * left untouched, since that affects deployments mid-flight.
     */
    public function saveSiteIdentity(): void
    {
        $this->authorize('update', $this->site);

        $validated = $this->validate([
            'settings_site_name' => ['required', 'string', 'max:255'],
            'settings_site_slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::unique('sites', 'slug')->ignore($this->site->id),
            ],
        ]);

        $this->site->update([
            'name' => trim($validated['settings_site_name']),
            'slug' => strtolower(trim($validated['settings_site_slug'])),
        ]);

        $this->syncGeneralSettingsForm();
        $this->toastSuccess(__('Site identity saved.'));
    }

    public function saveProjectSettings(): void
    {
        $this->authorize('update', $this->site);

        $validated = $this->validate([
            'project_workspace_id' => ['nullable', 'string', Rule::exists('workspaces', 'id')],
        ]);

        $workspaceId = $validated['project_workspace_id'] ?? null;

        if ($workspaceId !== null) {
            $workspace = Workspace::query()->findOrFail($workspaceId);

            if ($workspace->organization_id !== $this->site->organization_id) {
                abort(403);
            }
        }

        $this->site->update([
            'workspace_id' => $workspaceId,
        ]);

        $this->toastSuccess($workspaceId === null
            ? 'Project assignment removed.'
            : 'Project settings saved.');
        $this->syncGeneralSettingsForm();
    }

    public function saveSiteNotes(): void
    {
        $this->authorize('update', $this->site);

        $validated = $this->validate([
            'site_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['notes'] = trim((string) ($validated['site_notes'] ?? '')) ?: null;

        if ($meta['notes'] === null) {
            unset($meta['notes']);
        }

        $this->site->update([
            'meta' => $meta,
        ]);

        $this->toastSuccess('Site notes saved.');
        $this->syncGeneralSettingsForm();
    }

    /**
     * The primary preview hostname is a dply-managed, auto-provisioned subdomain
     * — there's a real DNS record + Let's Encrypt cert tied to that exact name on
     * the testing domain. It can't be freely renamed from this form (doing so
     * wouldn't re-provision DNS/SSL, it would just relabel a row and orphan the
     * live record), so the hostname field is locked and only the label / auto-SSL
     * / HTTPS-redirect options are editable.
     */
    public function previewHostnameLocked(): bool
    {
        $this->site->loadMissing('previewDomains');
        $primary = $this->site->primaryPreviewDomain();

        return $primary !== null && (bool) $primary->managed_by_dply;
    }

    public function savePreviewSettings(): void
    {
        $this->authorize('update', $this->site);

        // Pin a managed preview's hostname to its provisioned value before
        // validating/saving — the form can only change the label and toggles.
        if ($this->previewHostnameLocked()) {
            $this->preview_primary_hostname = (string) $this->site->primaryPreviewDomain()->hostname;
        }

        $validated = $this->validate([
            'preview_primary_hostname' => [
                'required',
                'string',
                'max:255',
                Rule::unique('site_preview_domains', 'hostname')->ignore($this->site->primaryPreviewDomain()?->id),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid preview domain like preview.example.com.');
                    }
                },
            ],
            'preview_label' => ['required', 'string', 'max:255'],
            'preview_auto_ssl' => ['boolean'],
            'preview_https_redirect' => ['boolean'],
        ]);

        SitePreviewDomain::query()
            ->where('site_id', $this->site->id)
            ->update(['is_primary' => false]);

        SitePreviewDomain::query()->updateOrCreate([
            'site_id' => $this->site->id,
            'hostname' => strtolower(trim($validated['preview_primary_hostname'])),
        ], [
            'label' => trim($validated['preview_label']),
            'dns_status' => $this->site->testingHostnameStatus() ?? 'pending',
            'ssl_status' => $this->site->ssl_status,
            'is_primary' => true,
            'auto_ssl' => (bool) $validated['preview_auto_ssl'],
            'https_redirect' => (bool) $validated['preview_https_redirect'],
            'managed_by_dply' => true,
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['testing_hostname'] = array_merge(is_array($meta['testing_hostname'] ?? null) ? $meta['testing_hostname'] : [], [
            'hostname' => strtolower(trim($validated['preview_primary_hostname'])),
            'status' => $this->site->testingHostnameStatus() ?? 'pending',
        ]);
        $this->site->update(['meta' => $meta]);

        $this->site->load('previewDomains');
        $this->syncPreviewSettingsForm();
        $this->finalizeRoutingMutation('Preview settings saved.');

        // Secure the primary preview host if auto-SSL is on and it isn't covered
        // yet — otherwise it serves whatever per-host cert is the vhost default.
        $this->queuePreviewCertificate($this->site->primaryPreviewDomain());
    }

    public function confirmRemovePreviewDomain(string $previewDomainId): void
    {
        $this->authorize('update', $this->site);
        $this->openConfirmActionModal(
            'removePreviewDomain',
            [$previewDomainId],
            __('Remove preview domain'),
            __('Remove this preview hostname? Any pending preview certificate is dropped after the next webserver apply.'),
            __('Remove preview'),
            true,
        );
    }

    public function removePreviewDomain(string $previewDomainId): void
    {
        $this->authorize('update', $this->site);

        $previewDomain = $this->site->previewDomains()->findOrFail($previewDomainId);

        // Drop the managed provider DNS record too, so removing an added preview
        // URL doesn't leave an orphaned A record behind.
        app(TestingHostnameProvisioner::class)->deleteManagedPreviewRecord($this->site, $previewDomain);

        $previewDomain->delete();

        $this->site->load('previewDomains');
        $this->syncPreviewSettingsForm();
        $this->finalizeRoutingMutation('Preview domain removed.');
    }

    /**
     * Whether dply can mint another managed preview URL here — managed testing
     * hostnames must be enabled and the server must have an IP to point at.
     */
    public function canAddManagedPreview(): bool
    {
        return trim((string) ($this->site->server?->ip_address ?? '')) !== ''
            && app(TestingHostnameProvisioner::class)->isEnabledForSite($this->site);
    }

    /**
     * "Add preview URL" — provision an additional dply-managed hostname on the
     * site's testing zone (its own DNS record) and wire it into the live vhost.
     *
     * TLS is NOT per-host here: every `*.<testing-zone>` hostname is secured by
     * the shared per-server wildcard certificate, so a new preview URL is covered
     * the moment that wildcard is installed — no per-host cert to queue. If the
     * wildcard isn't up yet, the managed-testing-host card's "Issue TLS" brings it
     * up and this (and every) preview host inherits it.
     */
    public function addManagedPreviewDomain(): void
    {
        $this->authorize('update', $this->site);

        $domain = app(TestingHostnameProvisioner::class)->provisionAdditional($this->site, $this->newPreviewLabel);
        if ($domain === null) {
            $this->toastError(__('Could not provision a preview URL — make sure managed DNS is connected and the server has an IP address.'));

            return;
        }

        $this->newPreviewLabel = '';

        // Add the hostname to the live webserver config (server_name). Managed
        // hosts ride the shared *.zone wildcard for TLS; if that wildcard isn't
        // installed yet they have no HTTPS until it's issued.
        $this->site->load('previewDomains');
        $this->syncPreviewSettingsForm();
        $this->finalizeRoutingMutation(__('Preview URL added: :host', ['host' => $domain->hostname]));

        $this->queuePreviewCertificate($domain->fresh());

        if (! $this->site->isCoveredByServerWildcard()) {
            $this->toastWarning(__('Added — but the :zone wildcard certificate isn’t installed yet, so HTTPS won’t work until it’s issued (use “Issue TLS” on the managed testing host).', ['zone' => $this->site->testingZone() ?? 'testing']));
        }
    }

    /**
     * Issue a preview host's certificate when it isn't already covered. Hosts on
     * a server that carries the shared *.testing-zone wildcard need nothing
     * (the wildcard secures them); everywhere else each preview host gets its own
     * HTTP-01 certificate, or its testing cert never matches and the browser
     * serves whichever per-host cert happens to be the vhost default.
     */
    private function queuePreviewCertificate(?SitePreviewDomain $domain): void
    {
        if ($domain === null || ! $domain->auto_ssl || trim((string) $domain->hostname) === '') {
            return;
        }

        // dply-managed hosts live on the testing zone and are secured by the
        // shared *.zone wildcard — issuing a per-host cert here is not just
        // redundant, it shadows the wildcard as the block's ssl_certificate and
        // breaks sibling hostnames. Only genuinely custom preview domains (BYO,
        // off the testing zone) take a per-host certificate.
        if ($domain->managed_by_dply || $this->site->isCoveredByServerWildcard()) {
            return;
        }

        $cert = app(CertificateRequestService::class)->queuePrimaryPreviewAutoSsl(
            $this->site->fresh(['previewDomains']) ?? $this->site,
            $domain,
        );
        if ($cert !== null) {
            // Brief delay so the webserver apply (server_name) and the DNS record
            // settle before the HTTP-01 challenge; failures self-heal on retry.
            ExecuteSiteCertificateJob::dispatch((string) $cert->id)->delay(now()->addSeconds(15));
        }
    }
}
