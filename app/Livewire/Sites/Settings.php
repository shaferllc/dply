<?php

namespace App\Livewire\Sites;

use App\Enums\SiteType;
use App\Jobs\DeleteServerSystemUserJob;
use App\Jobs\ExecuteSiteCertificateJob;
use App\Jobs\SiteSystemUserMutationJob;
use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\SiteTenantDomain;
use App\Models\Workspace;
use App\Services\Certificates\CertificateRequestService;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\Deploy\DeploymentContractBuilder;
use App\Services\Deploy\DeploymentPreflightValidator;
use App\Services\DigitalOceanService;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerPhpManager;
use App\Services\Servers\ServerSystemUserService;
use App\Services\Sites\LaravelSiteSshSetupRunner;
use App\Services\Sites\SiteScopedCommandWrapper;
use App\Services\SshConnection;
use App\Support\HostnameValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;

class Settings extends Show
{
    use StreamsRemoteSshLivewire;

    private const ROUTING_TABS = ['domains', 'aliases', 'redirects', 'preview', 'tenants'];

    private const LEGACY_ROUTING_SECTIONS = [
        'domains' => 'domains',
        'aliases' => 'aliases',
        'redirects' => 'redirects',
        'preview' => 'preview',
        'tenants' => 'tenants',
    ];

    public string $section = 'general';

    public string $routingTab = 'domains';

    public string $settings_primary_domain = '';

    public string $settings_document_root = '';

    public ?string $project_workspace_id = null;

    public string $site_notes = '';

    public string $new_alias_hostname = '';

    public string $new_alias_label = '';

    public string $new_tenant_hostname = '';

    public string $new_tenant_key = '';

    public string $new_tenant_label = '';

    public string $new_tenant_notes = '';

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

    /** Selected org DigitalOcean credential for DNS automation; empty string = organization default. */
    public string $settings_dns_provider_credential_id = '';

    /** DNS zone (apex) at the provider, e.g. example.com. Empty = use app default testing-domain pool. */
    public string $settings_dns_zone = '';

    public string $new_certificate_certificate_pem = '';

    public string $new_certificate_private_key_pem = '';

    public string $new_certificate_chain_pem = '';

    public ?string $quick_ssl_domain_hostname = null;

    public string $quick_ssl_provider_type = SiteCertificate::PROVIDER_LETSENCRYPT;

    public ?string $laravel_ssh_setup_pending_action = null;

    public ?string $laravel_ssh_setup_error = null;

    public string $system_user_panel_mode = 'existing';

    public string $system_user_new_username = '';

    public bool $system_user_new_sudo = false;

    public string $system_user_assign_username = '';

    public string $system_user_remove_username = '';

    public string $system_user_remove_confirm = '';

    /** @var list<array{username: string, site_count: int}> */
    public array $system_user_remote_rows = [];

    public ?string $system_user_list_error = null;

    public function mount(Server $server, Site $site, ?string $section = null): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }

        if ($server->organization_id !== auth()->user()->currentOrganization()?->id) {
            abort(404);
        }

        $requestedSection = request()->query('section');

        if (is_string($requestedSection) && $requestedSection !== '') {
            $section = $requestedSection;
        }

        if ($section === null) {
            $this->redirect(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']), navigate: true);

            return;
        }

        if (array_key_exists($section, self::LEGACY_ROUTING_SECTIONS)) {
            $this->redirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => 'routing',
                'tab' => self::LEGACY_ROUTING_SECTIONS[$section],
            ]), navigate: true);

            return;
        }

        $allowed = array_keys(config('site_settings.workspace_tabs', []));

        if (! in_array($section, $allowed, true)) {
            abort(404);
        }

        $this->section = $section;
        $this->routingTab = $this->resolveRoutingTab(request()->query('tab'));

        parent::mount($server, $site);
        $this->syncGeneralSettingsForm();
        $this->syncPreviewSettingsForm();
        if ($this->section === 'dns') {
            $this->syncDnsSettingsForm();
        }
    }

    public function updatedSection(string $value): void
    {
        if ($value === 'dns') {
            $this->syncDnsSettingsForm();
        }
        if ($value === 'runtime' || $value === 'laravel-stack' || $value === 'system-user') {
            $this->syncGeneralSettingsForm();
            $this->syncFormFromSite();
        }
    }

    public function saveLaravelStackSettings(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->flash_error = __('Laravel stack settings apply to VM and container sites that use SSH deploy and managed web server config.');
            $this->flash_success = null;

            return;
        }

        if (! $this->site->shouldShowPhpOctaneRolloutSettings()) {
            return;
        }

        $this->validate([
            'laravel_reverb_port' => 'nullable|integer|min:1|max:65535',
            'laravel_reverb_ws_path' => ['nullable', 'string', 'max:128'],
            'laravel_horizon_path' => ['nullable', 'string', 'max:128'],
            'laravel_horizon_notes' => ['nullable', 'string', 'max:2000'],
            'laravel_pulse_path' => ['nullable', 'string', 'max:128'],
            'laravel_pulse_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];

        if ($this->site->shouldShowLaravelReverbRuntimeUi() || $this->site->shouldProxyReverbInWebserver()) {
            $rv = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
            $rv['port'] = $this->laravel_reverb_port !== '' ? (int) $this->laravel_reverb_port : 8080;
            $ws = trim($this->laravel_reverb_ws_path);
            $rv['ws_path'] = $ws !== '' ? $ws : '/app';
            $meta['laravel_reverb'] = $rv;
        }

        if ($this->site->resolvedLaravelPackageFlag('horizon')) {
            $meta['laravel_horizon'] = [
                'path' => trim($this->laravel_horizon_path) !== '' ? trim($this->laravel_horizon_path) : '/horizon',
                'notes' => trim($this->laravel_horizon_notes),
            ];
        }

        if ($this->site->resolvedLaravelPackageFlag('pulse')) {
            $meta['laravel_pulse'] = [
                'path' => trim($this->laravel_pulse_path) !== '' ? trim($this->laravel_pulse_path) : '/pulse',
                'notes' => trim($this->laravel_pulse_notes),
            ];
        }

        $this->site->update(['meta' => $meta]);
        $this->syncFormFromSite();
        $this->finalizeRoutingMutation(__('Laravel stack settings saved.'));
    }

    public function saveRuntimePreferences(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->flash_error = __('Runtime preferences apply to VM and container sites. Use Deploy for function and serverless targets.');

            return;
        }

        $rules = [
            'deployment_environment' => 'required|string|max:32',
        ];

        if ($this->shouldShowRuntimePhpRolloutFields()) {
            $rules['laravel_scheduler'] = 'boolean';
            $rules['restart_supervisor_programs_after_deploy'] = 'boolean';
            if (! $this->shouldShowSystemUserPanel()) {
                $rules['php_fpm_user'] = 'nullable|string|max:64';
            }
        }

        if ($this->shouldShowRuntimePhpRolloutFields() && $this->site->shouldShowOctaneRuntimeUi()) {
            $rules['octane_port'] = 'nullable|integer|min:1|max:65535';
            $rules['octane_server'] = ['required', Rule::in(Site::OCTANE_SERVERS)];
        }

        if ($this->shouldShowRuntimeAppPortField()) {
            $rules['runtime_app_port'] = 'nullable|integer|min:1|max:65535';
        }

        if ($this->site->type === SiteType::Static) {
            $rules['settings_document_root'] = ['required', 'string', 'max:500'];
        }

        if ($this->shouldShowRailsRuntimeFields()) {
            $rules['rails_env'] = 'nullable|string|max:32';
        }

        $this->validate($rules);

        $update = [
            'deployment_environment' => $this->deployment_environment,
        ];

        if ($this->shouldShowRuntimePhpRolloutFields()) {
            $update['laravel_scheduler'] = $this->laravel_scheduler;
            $update['restart_supervisor_programs_after_deploy'] = $this->restart_supervisor_programs_after_deploy;
            if (! $this->shouldShowSystemUserPanel()) {
                $update['php_fpm_user'] = $this->php_fpm_user !== '' ? $this->php_fpm_user : null;
            }
        }

        if ($this->shouldShowRuntimePhpRolloutFields() && $this->site->shouldShowOctaneRuntimeUi()) {
            $update['octane_port'] = $this->octane_port !== '' ? (int) $this->octane_port : null;
        }

        if ($this->shouldShowRuntimeAppPortField()) {
            $update['app_port'] = $this->runtime_app_port !== '' ? (int) $this->runtime_app_port : null;
        }

        if ($this->site->type === SiteType::Static) {
            $update['document_root'] = trim($this->settings_document_root);
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $metaTouched = false;

        if ($this->shouldShowRailsRuntimeFields()) {
            $railsRuntime = is_array($meta['rails_runtime'] ?? null) ? $meta['rails_runtime'] : [];
            $env = trim($this->rails_env);
            $railsRuntime['env'] = $env !== '' ? $env : 'production';
            $meta['rails_runtime'] = $railsRuntime;
            $metaTouched = true;
        }

        if ($this->shouldShowRuntimePhpRolloutFields() && $this->site->shouldShowOctaneRuntimeUi()) {
            $lo = is_array($meta['laravel_octane'] ?? null) ? $meta['laravel_octane'] : [];
            $lo['server'] = $this->octane_server;
            $meta['laravel_octane'] = $lo;
            $metaTouched = true;
        }

        if ($metaTouched) {
            $update['meta'] = $meta;
        }

        $this->site->update($update);
        $this->syncFormFromSite();
        $this->syncGeneralSettingsForm();

        $this->finalizeRoutingMutation(__('Runtime preferences saved.'));
    }

    public function saveSystemUserSettings(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->flash_error = __('System user settings apply to VM-backed sites with managed PHP.');

            return;
        }

        if (! $this->shouldShowSystemUserPanel()) {
            return;
        }

        $this->validate([
            'php_fpm_user' => 'nullable|string|max:64',
        ]);

        $this->site->update([
            'php_fpm_user' => $this->php_fpm_user !== '' ? $this->php_fpm_user : null,
        ]);
        $this->site->refresh();
        $this->syncFormFromSite();
        $this->flash_success = __('System user settings saved.');
        $this->flash_error = null;
    }

    private function shouldShowRuntimePhpRolloutFields(): bool
    {
        return $this->site->shouldShowPhpOctaneRolloutSettings();
    }

    private function shouldShowRuntimeAppPortField(): bool
    {
        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            return false;
        }

        $resolved = $this->site->resolvedRuntimeAppDetection();
        $fw = strtolower((string) ($resolved['framework'] ?? ''));

        return $this->site->type === SiteType::Node
            || $this->site->usesDockerRuntime()
            || $this->site->usesKubernetesRuntime()
            || in_array($fw, [
                'rails',
                'nextjs',
                'nuxt',
                'node_generic',
                'vite_static',
                'django',
                'flask',
                'fastapi',
                'python_generic',
            ], true);
    }

    private function shouldShowRailsRuntimeFields(): bool
    {
        return $this->site->shouldShowRailsRuntimeSettings();
    }

    private function resolveRoutingTab(mixed $tab): string
    {
        return is_string($tab) && in_array($tab, self::ROUTING_TABS, true)
            ? $tab
            : self::ROUTING_TABS[0];
    }

    private function syncGeneralSettingsForm(): void
    {
        $this->site->refresh();
        $this->settings_primary_domain = (string) optional($this->site->primaryDomain())->hostname;
        $this->settings_document_root = (string) ($this->site->document_root ?? '');
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
        $this->site->refresh();
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
                $this->addError('settings_dns_zone', __('Add a DNS provider credential under Server providers (DigitalOcean or Cloudflare), or configure an app-level DigitalOcean token, to use a custom DNS zone.'));

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
        $this->flash_success = __('DNS settings saved.');
        $this->flash_error = null;
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

        $this->site->load('domains');
        $this->syncGeneralSettingsForm();
        $this->finalizeRoutingMutation('Site settings saved.');
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

        $this->flash_success = 'Site notes saved.';
        $this->flash_error = null;
        $this->syncGeneralSettingsForm();
    }

    public function addAlias(): void
    {
        $this->authorize('update', $this->site);

        $validated = $this->validate([
            'new_alias_hostname' => [
                'required',
                'string',
                'max:255',
                Rule::unique('site_domain_aliases', 'hostname'),
                Rule::unique('site_domains', 'hostname'),
                Rule::unique('site_preview_domains', 'hostname'),
                Rule::unique('site_tenant_domains', 'hostname'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid alias like www.example.com.');
                    }
                },
            ],
            'new_alias_label' => ['nullable', 'string', 'max:255'],
        ]);

        SiteDomainAlias::query()->create([
            'site_id' => $this->site->id,
            'hostname' => strtolower(trim($validated['new_alias_hostname'])),
            'label' => trim((string) ($validated['new_alias_label'] ?? '')) ?: null,
            'sort_order' => (int) ($this->site->domainAliases()->max('sort_order') ?? 0) + 1,
        ]);

        $this->new_alias_hostname = '';
        $this->new_alias_label = '';
        $this->site->load('domainAliases');
        $this->finalizeRoutingMutation('Alias added.');
    }

    public function removeAlias(string $aliasId): void
    {
        $this->authorize('update', $this->site);

        $this->site->domainAliases()->findOrFail($aliasId)->delete();
        $this->site->load('domainAliases');
        $this->finalizeRoutingMutation('Alias removed.');
    }

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
            'new_tenant_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        SiteTenantDomain::query()->create([
            'site_id' => $this->site->id,
            'hostname' => strtolower(trim($validated['new_tenant_hostname'])),
            'tenant_key' => trim((string) ($validated['new_tenant_key'] ?? '')) ?: null,
            'label' => trim((string) ($validated['new_tenant_label'] ?? '')) ?: null,
            'notes' => trim((string) ($validated['new_tenant_notes'] ?? '')) ?: null,
            'sort_order' => (int) ($this->site->tenantDomains()->max('sort_order') ?? 0) + 1,
        ]);

        $this->new_tenant_hostname = '';
        $this->new_tenant_key = '';
        $this->new_tenant_label = '';
        $this->new_tenant_notes = '';
        $this->site->load('tenantDomains');
        $this->finalizeRoutingMutation('Tenant domain added.');
    }

    public function removeTenantDomain(string $tenantDomainId): void
    {
        $this->authorize('update', $this->site);

        $this->site->tenantDomains()->findOrFail($tenantDomainId)->delete();
        $this->site->load('tenantDomains');
        $this->finalizeRoutingMutation('Tenant domain removed.');
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

        $this->site->load('previewDomains');
        $this->syncPreviewSettingsForm();
        $this->finalizeRoutingMutation('Preview settings saved.');
    }

    public function removePreviewDomain(string $previewDomainId): void
    {
        $this->authorize('update', $this->site);

        $previewDomain = $this->site->previewDomains()->findOrFail($previewDomainId);
        $previewDomain->delete();

        $this->site->load('previewDomains');
        $this->syncPreviewSettingsForm();
        $this->finalizeRoutingMutation('Preview domain removed.');
    }

    public function openQuickDomainSslModal(string $hostname): void
    {
        $this->authorize('update', $this->site);
        $this->site->loadMissing('domains');

        $normalized = strtolower(trim($hostname));
        if (! $this->site->domains->contains(fn (SiteDomain $domain): bool => strtolower($domain->hostname) === $normalized)) {
            abort(404);
        }

        $this->quick_ssl_domain_hostname = $normalized;
        $this->quick_ssl_provider_type = SiteCertificate::PROVIDER_LETSENCRYPT;
        $this->dispatch('open-modal', 'quick-domain-ssl-modal');
    }

    public function closeQuickDomainSslModal(): void
    {
        $this->dispatch('close-modal', 'quick-domain-ssl-modal');
    }

    public function quickAddDomainSsl(CertificateRequestService $certificateRequestService): void
    {
        $this->authorize('update', $this->site);
        $this->site->loadMissing(['domains', 'certificates']);

        $validated = $this->validate([
            'quick_ssl_domain_hostname' => ['required', 'string'],
            'quick_ssl_provider_type' => ['required', Rule::in([
                SiteCertificate::PROVIDER_LETSENCRYPT,
                SiteCertificate::PROVIDER_ZEROSSL,
            ])],
        ]);

        $hostname = strtolower(trim($validated['quick_ssl_domain_hostname']));
        if (! $this->site->domains->contains(fn (SiteDomain $domain): bool => strtolower($domain->hostname) === $hostname)) {
            $this->addError('quick_ssl_domain_hostname', __('Choose a domain that belongs to this site.'));

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
            $this->flash_error = __('SSL is already configured or in progress for :domain.', ['domain' => $hostname]);
            $this->flash_success = null;
            $this->closeQuickDomainSslModal();

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

        try {
            ExecuteSiteCertificateJob::dispatchSync($certificate->id);
            $providerLabel = $validated['quick_ssl_provider_type'] === SiteCertificate::PROVIDER_ZEROSSL
                ? 'ZeroSSL'
                : 'Let\'s Encrypt';
            $this->flash_success = __('SSL request started for :domain via :provider.', [
                'domain' => $hostname,
                'provider' => $providerLabel,
            ]);
            $this->flash_error = null;
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
            $this->flash_success = null;
        }

        $this->site->load('certificates');
        $this->closeQuickDomainSslModal();
    }

    public function openLaravelSshSetupModal(string $action, LaravelSiteSshSetupRunner $runner): void
    {
        $this->authorize('update', $this->site);
        $this->laravel_ssh_setup_error = null;

        try {
            $runner->assertActionAllowed($this->site, $action);
        } catch (\InvalidArgumentException $e) {
            $this->laravel_ssh_setup_error = $e->getMessage();

            return;
        }

        $this->laravel_ssh_setup_pending_action = $action;
        $this->dispatch('open-modal', 'laravel-ssh-setup-modal');
    }

    public function closeLaravelSshSetupModal(): void
    {
        $this->laravel_ssh_setup_pending_action = null;
        $this->dispatch('close-modal', 'laravel-ssh-setup-modal');
    }

    public function confirmLaravelSshSetup(LaravelSiteSshSetupRunner $runner, SiteScopedCommandWrapper $commandWrapper): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->laravel_ssh_setup_error = __('Deployers cannot run remote setup commands on servers.');
            $this->closeLaravelSshSetupModal();

            return;
        }

        $action = $this->laravel_ssh_setup_pending_action;
        if ($action === null) {
            return;
        }

        $this->laravel_ssh_setup_error = null;
        $this->flash_error = null;

        try {
            $runner->assertActionAllowed($this->site, $action);
            $rawCmd = $runner->commandFor($this->site, $action);
            $cmd = $commandWrapper->wrapRemoteExec($this->site, $rawCmd);
            $timeout = $runner->timeoutSecondsFor($action);
            $this->resetRemoteSshStreamTargets();
            $server = $this->site->server;
            if ($server === null) {
                throw new \RuntimeException(__('Server is not available.'));
            }
            $this->remoteSshStreamSetMeta(
                __('Laravel setup'),
                $commandWrapper->executionSummary($this->site).' @ '.$server->ip_address.'  '.$cmd
            );
            $ssh = new SshConnection($server);
            $ssh->execWithCallback(
                $cmd,
                fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk),
                $timeout
            );
            $exit = $ssh->lastExecExitCode();
            if ($exit !== null && $exit !== 0) {
                $this->laravel_ssh_setup_error = __('Command exited with code :code.', ['code' => $exit]);
                $this->flash_success = null;
            } else {
                $this->flash_success = __('Setup command finished.');
                $this->flash_error = null;
            }
        } catch (\Throwable $e) {
            $this->laravel_ssh_setup_error = $e->getMessage();
            $this->flash_success = null;
        }

        $this->laravel_ssh_setup_pending_action = null;
        $this->dispatch('close-modal', 'laravel-ssh-setup-modal');
    }

    public function laravelSshSetupPendingCommandPreview(): ?string
    {
        if ($this->laravel_ssh_setup_pending_action === null) {
            return null;
        }

        $runner = app(LaravelSiteSshSetupRunner::class);

        try {
            return $runner->commandFor($this->site, $this->laravel_ssh_setup_pending_action);
        } catch (\InvalidArgumentException) {
            return null;
        }
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
            $this->flash_error = 'ZeroSSL currently supports the HTTP challenge flow only.';
            $this->flash_success = null;

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
                $certificateRequestService->execute($certificate);
            } else {
                ExecuteSiteCertificateJob::dispatchSync($certificate->id);
            }
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
            $this->flash_success = null;
            $this->site->load('certificates');

            return;
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

    public function loadSystemUsersForPanel(ServerPasswdUserLister $lister, ServerSystemUserService $service): void
    {
        $this->authorize('update', $this->site);
        $this->system_user_list_error = null;
        $this->flash_success = null;
        $this->flash_error = null;

        if (! $this->shouldShowSystemUserPanel()) {
            return;
        }

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->system_user_list_error = __('The server must be ready with SSH before loading system users.');

            return;
        }

        try {
            $this->system_user_remote_rows = $service->listPasswdUsersWithSiteCounts($this->server->fresh(), $lister);
        } catch (\Throwable $e) {
            $this->system_user_list_error = $e->getMessage();
            $this->system_user_remote_rows = [];
        }
    }

    public function openSystemUserCreateModal(): void
    {
        $this->authorize('update', $this->site);
        $this->resetErrorBag();
        $this->system_user_new_username = '';
        $this->system_user_new_sudo = false;
        $this->dispatch('open-modal', 'site-system-user-create-modal');
    }

    public function openSystemUserAssignModal(): void
    {
        $this->authorize('update', $this->site);
        $this->resetErrorBag();

        $allowed = collect($this->system_user_remote_rows)->pluck('username')->filter()->all();
        $this->validate([
            'system_user_assign_username' => ['required', 'string', 'max:64', Rule::in($allowed)],
        ]);

        $this->dispatch('open-modal', 'site-system-user-assign-modal');
    }

    public function openSystemUserRemoveModal(): void
    {
        $this->authorize('update', $this->server);
        $this->resetErrorBag();
        $this->system_user_remove_username = '';
        $this->system_user_remove_confirm = '';
        $this->dispatch('open-modal', 'site-system-user-remove-modal');
    }

    public function closeSystemUserCreateModal(): void
    {
        $this->dispatch('close-modal', 'site-system-user-create-modal');
    }

    public function closeSystemUserAssignModal(): void
    {
        $this->dispatch('close-modal', 'site-system-user-assign-modal');
    }

    public function closeSystemUserRemoveModal(): void
    {
        $this->dispatch('close-modal', 'site-system-user-remove-modal');
    }

    public function queueCreateSystemUser(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldShowSystemUserPanel()) {
            return;
        }

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->flash_error = __('The server must be ready with SSH.');

            return;
        }

        $this->validate([
            'system_user_new_username' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'system_user_new_sudo' => ['boolean'],
        ]);

        SiteSystemUserMutationJob::dispatch(
            $this->site->id,
            'create',
            $this->system_user_new_username,
            $this->system_user_new_sudo,
        );

        $this->closeSystemUserCreateModal();
        $this->flash_success = __('System user operation queued. Refresh in a moment to see updates.');
        $this->flash_error = null;
    }

    public function queueAssignSystemUser(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldShowSystemUserPanel()) {
            return;
        }

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->flash_error = __('The server must be ready with SSH.');

            return;
        }

        $allowed = collect($this->system_user_remote_rows)->pluck('username')->filter()->all();
        $this->validate([
            'system_user_assign_username' => ['required', 'string', 'max:64', Rule::in($allowed)],
        ]);

        SiteSystemUserMutationJob::dispatch(
            $this->site->id,
            'assign',
            $this->system_user_assign_username,
            false,
        );

        $this->closeSystemUserAssignModal();
        $this->flash_success = __('System user operation queued. Refresh in a moment to see updates.');
        $this->flash_error = null;
    }

    public function queueRemoveSystemUser(): void
    {
        $this->authorize('update', $this->server);

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->flash_error = __('The server must be ready with SSH.');
            $this->closeSystemUserRemoveModal();

            return;
        }

        $allowed = collect($this->system_user_remote_rows)->pluck('username')->filter()->all();
        $this->validate([
            'system_user_remove_username' => ['required', 'string', 'max:64', Rule::in($allowed)],
            'system_user_remove_confirm' => ['required', 'same:system_user_remove_username'],
        ]);

        DeleteServerSystemUserJob::dispatch($this->server->id, $this->system_user_remove_username);

        $this->closeSystemUserRemoveModal();
        $this->flash_success = __('User removal queued. Refresh server and site lists shortly.');
        $this->flash_error = null;
    }

    public function dismissSystemUserOperationBanner(): void
    {
        $this->authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        unset($meta['system_user_operation']);
        $this->site->update(['meta' => $meta]);
        $this->site->refresh();
        $this->syncFormFromSite();
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
        if (! $this->site->isReadyForWorkspace()) {
            return parent::render();
        }

        $this->site->load([
            'domains',
            'domainAliases',
            'previewDomains',
            'dnsProviderCredential',
            'certificates.previewDomain',
            'deployments',
            'environmentVariables',
            'redirects',
            'tenantDomains',
            'deployHooks',
            'deploySteps',
            'webhookDeliveryLogs',
            'workspace.variables',
        ]);

        return view('livewire.sites.settings', [
            'tabs' => config('site_settings.workspace_tabs', []),
            'routingTabs' => self::ROUTING_TABS,
            'deployHookUrl' => $this->site->deployHookUrl(),
            'deploymentContract' => app(DeploymentContractBuilder::class)->build($this->site),
            'deploymentPreflight' => app(DeploymentPreflightValidator::class)->validate($this->site),
            'availableWorkspaces' => Workspace::query()
                ->where('organization_id', $this->site->organization_id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'providerCredentials' => ProviderCredential::query()
                ->where('organization_id', $this->site->organization_id)
                ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
                ->orderBy('name')
                ->get(['id', 'name', 'provider']),
            'sitePhpData' => $this->server->hostCapabilities()->supportsMachinePhpManagement()
                ? app(ServerPhpManager::class)->sitePhpData($this->server, $this->site)
                : null,
        ]);
    }
}
