<?php

namespace App\Livewire\Sites;

use App\Jobs\ExecuteSiteCertificateJob;
use App\Models\ProviderCredential;
use App\Models\SiteCertificate;
use App\Models\SiteDomain;
use App\Models\SitePreviewDomain;
use App\Models\Server;
use App\Models\Site;
use App\Models\Workspace;
use App\Services\Certificates\CertificateRequestService;
use App\Support\HostnameValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;

class Settings extends Show
{
    public string $section = 'general';

    public string $settings_primary_domain = '';

    public string $settings_document_root = '';

    public ?string $project_workspace_id = null;

    public string $preview_primary_hostname = '';

    public string $preview_label = 'Managed preview';

    public bool $preview_auto_ssl = true;

    public bool $preview_https_redirect = true;

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

    public function mount(Server $server, Site $site, ?string $section = null): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }

        if ($server->organization_id !== auth()->user()->currentOrganization()?->id) {
            abort(404);
        }

        if ($section === null) {
            $this->redirect(route('sites.settings', ['server' => $server, 'site' => $site, 'section' => 'general']), navigate: true);

            return;
        }

        $allowed = array_keys(config('site_settings.workspace_tabs', []));

        if (! in_array($section, $allowed, true)) {
            abort(404);
        }

        $this->section = $section;

        parent::mount($server, $site);
        $this->syncGeneralSettingsForm();
        $this->syncPreviewSettingsForm();
    }

    private function syncGeneralSettingsForm(): void
    {
        $this->site->refresh();
        $this->settings_primary_domain = (string) optional($this->site->primaryDomain())->hostname;
        $this->settings_document_root = (string) ($this->site->document_root ?? '');
        $this->project_workspace_id = $this->site->workspace_id;
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

    public function saveGeneralSettings(): void
    {
        $this->authorize('update', $this->site);

        $primaryDomain = $this->site->primaryDomain();

        $validated = $this->validate([
            'settings_primary_domain' => [
                'required',
                'string',
                'max:255',
                Rule::unique('site_domains', 'hostname')->ignore($primaryDomain?->id),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid domain name like app.example.com.');
                    }
                },
            ],
            'settings_document_root' => ['required', 'string', 'max:500'],
        ]);

        $this->site->update([
            'document_root' => trim($validated['settings_document_root']),
        ]);

        if ($primaryDomain) {
            $primaryDomain->update([
                'hostname' => strtolower(trim($validated['settings_primary_domain'])),
            ]);
        } else {
            SiteDomain::query()->create([
                'site_id' => $this->site->id,
                'hostname' => strtolower(trim($validated['settings_primary_domain'])),
                'is_primary' => true,
                'www_redirect' => false,
            ]);
        }

        $this->flash_success = 'Site settings saved.';
        $this->flash_error = null;
        $this->site->load('domains');
        $this->syncGeneralSettingsForm();
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

        $this->flash_success = $workspaceId === null
            ? 'Project assignment removed.'
            : 'Project settings saved.';
        $this->flash_error = null;
        $this->syncGeneralSettingsForm();
    }

    public function savePreviewSettings(): void
    {
        $this->authorize('update', $this->site);

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

        $this->flash_success = 'Preview settings saved.';
        $this->flash_error = null;
        $this->site->load('previewDomains');
        $this->syncPreviewSettingsForm();
    }

    public function removePreviewDomain(string $previewDomainId): void
    {
        $this->authorize('update', $this->site);

        $previewDomain = $this->site->previewDomains()->findOrFail($previewDomainId);
        $previewDomain->delete();

        $this->flash_success = 'Preview domain removed.';
        $this->flash_error = null;
        $this->site->load('previewDomains');
        $this->syncPreviewSettingsForm();
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

        if ($validated['new_certificate_provider_type'] === SiteCertificate::PROVIDER_ZEROSSL) {
            $this->flash_error = 'ZeroSSL is modeled in the new certificate system, but execution is not enabled yet.';
            $this->flash_success = null;

            return;
        }

        $certificate = $certificateRequestService->create([
            'site_id' => $this->site->id,
            'preview_domain_id' => $validated['new_certificate_scope'] === SiteCertificate::SCOPE_PREVIEW
                ? $validated['new_certificate_preview_domain_id']
                : null,
            'provider_credential_id' => $validated['new_certificate_provider_credential_id'],
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

        if (in_array($certificate->provider_type, [SiteCertificate::PROVIDER_IMPORTED, SiteCertificate::PROVIDER_CSR], true)) {
            $certificateRequestService->execute($certificate);
        } else {
            ExecuteSiteCertificateJob::dispatchSync($certificate->id);
        }

        $this->resetCertificateRequestForm();
        $this->flash_success = 'Certificate request saved.';
        $this->flash_error = null;
        $this->site->load('certificates');
    }

    public function removeCertificate(string $certificateId, CertificateRequestService $certificateRequestService): void
    {
        $this->authorize('update', $this->site);

        $certificate = $this->site->certificates()->findOrFail($certificateId);
        $certificateRequestService->removeArtifacts($certificate);
        $certificate->delete();

        $this->flash_success = 'Certificate removed.';
        $this->flash_error = null;
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

        return $this->site->customerDomainHostnames();
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

    public function render(): View
    {
        $this->site->load([
            'domains',
            'previewDomains',
            'certificates.previewDomain',
            'environmentVariables',
            'redirects',
            'deployHooks',
            'deploySteps',
            'workspace.variables',
        ]);

        return view('livewire.sites.settings', [
            'tabs' => config('site_settings.workspace_tabs', []),
            'deployHookUrl' => $this->site->deployHookUrl(),
            'availableWorkspaces' => Workspace::query()
                ->where('organization_id', $this->site->organization_id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'providerCredentials' => ProviderCredential::query()
                ->where('organization_id', $this->site->organization_id)
                ->whereIn('provider', ['digitalocean'])
                ->orderBy('name')
                ->get(['id', 'name', 'provider']),
            'sitePhpData' => $this->server->hostCapabilities()->supportsMachinePhpManagement()
                ? app(\App\Services\Servers\ServerPhpManager::class)->sitePhpData($this->server, $this->site)
                : null,
        ]);
    }
}
