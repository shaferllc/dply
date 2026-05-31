<?php

namespace App\Livewire\Sites;

use App\Enums\SiteType;
use App\Jobs\AssignSystemUserToSiteJob;
use App\Jobs\ExecuteSiteCertificateJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Jobs\SiteResetPermissionsJob;
use App\Jobs\SyncBasicAuthFromServerJob;
use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Livewire\Concerns\ManagesContainerSite;
use App\Livewire\Concerns\ManagesSiteBindings;
use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\NotificationWebhookDestination;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAccessGate;
use App\Models\SiteAccessGatePassword;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteCertificate;
use App\Models\SiteDeployment;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\SiteTenantDomain;
use App\Models\Snapshot;
use App\Models\Workspace;
use App\Services\Certificates\CertificateRepairService;
use App\Services\Certificates\CertificateRequestService;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\Deploy\DeploymentContractBuilder;
use App\Services\Deploy\DeploymentPreflightValidator;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\RemoteCli\Artisan;
use App\Services\RemoteCli\RemoteCliPermissionDeniedException;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerPhpManager;
use App\Services\Servers\ServerSystemUserService;
use App\Services\Sites\LaravelConsoleExecutor;
use App\Services\Sites\LaravelSiteSshSetupRunner;
use App\Services\Sites\SiteAccessGateLoginLogReader;
use App\Services\Sites\SiteAccessGateService;
use App\Services\Sites\SiteDeploySyncGroupManager;
use App\Services\Sites\SiteScopedCommandWrapper;
use App\Services\Snapshots\LocalDiskDestination;
use App\Services\Snapshots\SnapshotService;
use App\Services\SshConnection;
use App\Services\VultrService;
use App\Support\HostnameValidator;
use App\Support\Sites\SiteSettingsViewData;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

class Settings extends Show
{
    use DismissesConsoleActionRun;
    use ManagesContainerSite;
    use ManagesSiteBindings;
    use StreamsRemoteSshLivewire;

    protected function consoleActionSubject(): Model
    {
        return $this->site;
    }

    private const ROUTING_TABS = ['domains', 'dns', 'aliases', 'redirects', 'preview', 'tenants'];

    private const LEGACY_ROUTING_SECTIONS = [
        'domains' => 'domains',
        'aliases' => 'aliases',
        'redirects' => 'redirects',
        'preview' => 'preview',
        'tenants' => 'tenants',
    ];

    private const LEGACY_RUNTIME_SECTIONS = [
        'runtime-php' => 'php',
        'runtime-ruby' => 'ruby',
        'runtime-static' => 'static',
    ];

    /** @var list<string> */
    private const SITE_NOTIFICATION_EVENT_KEYS = [
        'site.deployments',
        'site.deployment_started',
        'site.uptime',
    ];

    public string $section = 'general';

    public string $routingTab = 'domains';

    public string $runtimeTab = 'overview';

    public string $settings_primary_domain = '';

    public string $settings_document_root = '';

    public string $settings_site_name = '';

    public string $settings_site_slug = '';

    public ?string $project_workspace_id = null;

    public string $site_notes = '';

    public string $new_alias_hostname = '';

    public string $new_alias_label = '';

    public string $new_alias_comment = '';

    /** Multi-line bulk paste — `hostname` or `hostname,label` per line. */
    public string $bulk_alias_input = '';

    /** When non-null, the aliases list shows an inline edit form for this row. */
    public ?string $editing_alias_id = null;

    public string $editing_alias_hostname = '';

    public string $editing_alias_label = '';

    public string $editing_alias_comment = '';

    public string $new_basic_auth_username = '';

    public string $new_basic_auth_password = '';

    public string $new_basic_auth_path = '/';

    public string $bulk_basic_auth_input = '';

    public string $bulk_basic_auth_path = '/';

    public string $access_gate_method = '';

    public string $form_gate_password = '';

    public string $new_form_gate_label = '';

    /** @var list<array{at: string, label: string, credential_id: string, hostname: string, ip: string|null, user_agent: string|null}> */
    public array $form_gate_login_log = [];

    public bool $form_gate_login_log_loaded = false;

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

    public string $system_user_assign_username = '';

    /**
     * Cached list of usernames present on the server. Populated by
     * {@see loadSystemUsersForPanel()} on first load of the system-user section
     * and used as both the picker's option list and the validation allow-list
     * for {@see queueAssignSystemUser()}. Site-count metadata lives only on the
     * server-level /system-users page now; here we just need the usernames.
     *
     * @var list<array{username: string, site_count: int}>
     */
    public array $system_user_remote_rows = [];

    public ?string $system_user_list_error = null;

    /** @var 'commands'|'octane'|'reverb'|'logs'|'setup'|'schedule'|'migrations'|'pail' */
    public string $laravel_tab = 'commands';

    /**
     * Schedule sub-tab: parsed `php artisan schedule:list --json` rows.
     * Empty until the operator clicks Load.
     *
     * @var list<array{command?: string, description?: string, expression?: string, next_due?: string}>
     */
    public array $laravelScheduleEntries = [];

    public bool $laravelScheduleLoaded = false;

    /**
     * Migrations sub-tab: parsed `php artisan migrate:status --json`
     * rows ordered as the framework returns them (oldest → newest).
     *
     * @var list<array{migration?: string, batch?: int|string|null, ran?: bool}>
     */
    public array $laravelMigrationEntries = [];

    public bool $laravelMigrationsLoaded = false;

    /** UI flash flag set after a successful pre-rollback snapshot+rollback. */
    public ?string $laravelMigrationsFlash = null;

    /**
     * Pail sub-tab buffer — recent log entries from storage/logs/laravel.log.
     *
     * v1 (PR 11c) shipped operator-driven "Tail logs" button.
     * v1.1 (this slice) adds wire:poll-driven live tail with byte-offset
     * tracking: every 2s we fetch only the bytes appended since the
     * last poll, append to the buffer client-side, and trim to a
     * cap so memory stays bounded. Real WebSocket/SSE streaming is
     * still a v2 concern but for typical Laravel sites with
     * single-line-per-event JSON logs this looks "live enough".
     */
    public string $laravelPailBuffer = '';

    public bool $laravelPailLoaded = false;

    /**
     * Byte offset into the log file we've already streamed up to.
     * Sent to `tail -c +<offset>` on each poll so we only ship new
     * bytes. Reset to 0 on Refresh so the user can re-baseline.
     */
    public int $laravelPailOffset = 0;

    /** Live-poll on/off — operator toggles on the sub-tab. */
    public bool $laravelPailLive = false;

    /** Soft cap on buffer size (chars) so a chatty log doesn't OOM Livewire state. */
    public const PAIL_BUFFER_MAX_CHARS = 64_000;

    public string $laravel_custom_commands_text = '';

    /**
     * @var array{ok?: bool, commands?: list<array{name: string, description?: string|null}>, error?: string|null, raw?: string}
     */
    public array $laravel_artisan_discovery = [];

    public ?string $laravel_console_error = null;

    public int $laravel_log_tail_lines = 500;

    /** @var list<string> */
    public array $site_notification_channel_ids = [];

    /** @var list<string> */
    public array $site_notification_event_keys = [];

    public string $site_int_hook_name = '';

    public string $site_int_hook_driver = NotificationWebhookDestination::DRIVER_SLACK;

    public string $site_int_hook_url = '';

    public bool $site_int_evt_success = true;

    public bool $site_int_evt_failed = true;

    public bool $site_int_evt_skipped = true;

    public bool $site_int_evt_deploy_started = false;

    public bool $site_int_evt_uptime_down = true;

    public bool $site_int_evt_uptime_recovered = true;

    public string $sync_group_name_input = '';

    public string $sync_group_add_site_id = '';

    public string $sync_group_leader_site_id = '';

    public function mount(Server $server, Site $site, ?string $section = null): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }

        if ($site->usesEdgeRuntime()) {
            abort(404);
        }

        // Section is a path segment (servers/{server}/sites/{site}/{section}).
        // Default to 'general' so /sites/{site} (no trailing segment) still
        // resolves.
        if ($section === null || $section === '') {
            $section = 'general';
        }

        // Backward-compat for old ?section=X bookmarks. Translate the
        // query param into the path segment and redirect, preserving
        // every other query param (?tab=, ?laravel_tab=, etc.).
        $querySection = request()->query('section');
        if (is_string($querySection) && $querySection !== '') {
            $rest = collect(request()->query())->except('section')->all();

            $this->redirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => $querySection,
                ...$rest,
            ]), navigate: true);

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

        if ($section === 'dns') {
            $this->redirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => 'routing',
                'tab' => 'dns',
                ...collect(request()->query())->except('section')->all(),
            ]), navigate: true);

            return;
        }

        if (array_key_exists($section, self::LEGACY_RUNTIME_SECTIONS)) {
            $this->redirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => 'runtime',
                'tab' => self::LEGACY_RUNTIME_SECTIONS[$section],
                ...collect(request()->query())->except('section')->all(),
            ]), navigate: true);

            return;
        }

        if ($section === 'webhooks') {
            $this->redirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => 'notifications',
            ]), navigate: true);

            return;
        }

        // Serverless workspaces use the dedicated `sites.repository` Livewire
        // page (file browser, branch picker, connection panel) instead of
        // the legacy section-router config form. Catch back-compat links
        // and the sidebar pre-route-update before they hit this component.
        if ($section === 'repository' && in_array($site->runtimeTargetMode(), ['docker', 'kubernetes', 'serverless'], true)) {
            $this->redirect(route('sites.repository', [
                'server' => $server,
                'site' => $site,
            ]), navigate: true);

            return;
        }

        $allowed = array_keys(config('site_settings.workspace_tabs', []));

        if (! in_array($section, $allowed, true)) {
            abort(404);
        }

        $this->section = $section;
        $this->routingTab = $this->resolveRoutingTab(request()->query('tab'));
        $this->runtimeTab = $this->resolveRuntimeTabForSite($site, request()->query('tab'));

        $laravelTabQuery = request()->query('laravel_tab');
        if (is_string($laravelTabQuery) && in_array($laravelTabQuery, ['commands', 'octane', 'reverb', 'logs', 'setup', 'schedule', 'migrations', 'pail'], true)) {
            $this->laravel_tab = $laravelTabQuery;
        }

        parent::mount($server, $site);
        // parent::mount → syncFormFromSite already refreshed the site; skip the
        // redundant refresh here.
        $this->syncGeneralSettingsForm(skipRefresh: true);
        $this->syncPreviewSettingsForm();
        if ($this->section === 'basic-auth') {
            $this->site->loadMissing(['accessGate', 'accessGatePasswords', 'basicAuthUsers']);
            $this->access_gate_method = $this->site->resolvedAccessGateMethod();
        }
        if ($this->section === 'routing' && $this->routingTab === 'dns') {
            $this->syncDnsSettingsForm();
        }

        if ($this->section === 'laravel-stack' && $this->site->isLaravelFrameworkDetected() && $this->laravel_tab === 'commands') {
            $this->loadLaravelArtisanDiscovery(false);
        }

        if ($this->section === 'notifications') {
            $this->loadSiteNotificationPreferences();
        }

        if ($this->section === 'repository') {
            $this->syncRepositorySyncUiState();
        }
    }

    protected function syncRepositorySyncUiState(): void
    {
        $manager = app(SiteDeploySyncGroupManager::class);
        $group = $manager->findGroupForSite($this->site);
        $this->sync_group_leader_site_id = $group?->leader_site_id ? (string) $group->leader_site_id : '';
    }

    public function createDeploySyncGroup(SiteDeploySyncGroupManager $manager): void
    {
        $this->authorize('update', $this->site);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage sync groups.'));

            return;
        }

        $this->validate(['sync_group_name_input' => 'required|string|max:120']);
        $manager->createGroup($this->site->fresh(), $this->sync_group_name_input);
        $this->sync_group_name_input = '';
        $this->toastSuccess(__('Synchronized deployment group created.'));
        $this->syncRepositorySyncUiState();
    }

    public function addSiteToDeploySyncGroup(SiteDeploySyncGroupManager $manager): void
    {
        $this->authorize('update', $this->site);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage sync groups.'));

            return;
        }

        $this->validate(['sync_group_add_site_id' => 'required|string']);
        $group = $manager->findGroupForSite($this->site);
        if ($group === null) {
            $this->addError('sync_group_add_site_id', __('Create a group first.'));

            return;
        }
        $other = Site::query()
            ->where('organization_id', $this->site->organization_id)
            ->findOrFail($this->sync_group_add_site_id);
        $manager->addSite($group, $other);
        $this->sync_group_add_site_id = '';
        $this->toastSuccess(__('Site added to the sync group.'));
        $this->syncRepositorySyncUiState();
    }

    public function setDeploySyncGroupLeader(SiteDeploySyncGroupManager $manager): void
    {
        $this->authorize('update', $this->site);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage sync groups.'));

            return;
        }

        $this->validate(['sync_group_leader_site_id' => 'required|string']);
        $group = $manager->findGroupForSite($this->site);
        if ($group === null) {
            return;
        }
        $leader = Site::query()
            ->where('organization_id', $this->site->organization_id)
            ->findOrFail($this->sync_group_leader_site_id);
        $manager->setLeader($group, $leader);
        $this->toastSuccess(__('Leader updated.'));
        $this->syncRepositorySyncUiState();
    }

    public function leaveDeploySyncGroup(SiteDeploySyncGroupManager $manager): void
    {
        $this->authorize('update', $this->site);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage sync groups.'));

            return;
        }

        $manager->removeSite($this->site->fresh());
        $this->toastSuccess(__('Removed from sync group.'));
        $this->syncRepositorySyncUiState();
    }

    /**
     * Trigger a (re)deploy of a serverless function from the General-section
     * dashboard, then send the operator to the journey page to watch it run.
     */
    public function redeployServerlessFunction(): void
    {
        $this->authorize('update', $this->site);

        if (! ($this->site->server?->isDigitalOceanFunctionsHost() ?? false)) {
            $this->toastError(__('This site is not a serverless function.'));

            return;
        }

        RunSiteDeploymentJob::dispatch($this->site, SiteDeployment::TRIGGER_MANUAL);

        $this->redirect(route('serverless.journey', [
            'server' => $this->server,
            'site' => $this->site,
        ]), navigate: true);
    }

    protected function loadSiteNotificationPreferences(): void
    {
        $this->site->loadMissing('notificationSubscriptions');
        $subs = $this->site->notificationSubscriptions
            ->whereIn('event_key', self::SITE_NOTIFICATION_EVENT_KEYS);

        $this->site_notification_channel_ids = $subs->pluck('notification_channel_id')->unique()->values()->map(fn ($id) => (string) $id)->all();
        $this->site_notification_event_keys = $subs->pluck('event_key')->unique()->values()->all();
    }

    public function saveSiteNotificationSubscriptions(): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot change notification subscriptions.'));

            return;
        }

        $inRule = implode(',', self::SITE_NOTIFICATION_EVENT_KEYS);

        $this->validate([
            'site_notification_channel_ids' => ['array'],
            'site_notification_channel_ids.*' => ['string', 'exists:notification_channels,id'],
            'site_notification_event_keys' => ['array'],
            'site_notification_event_keys.*' => ['string', 'in:'.$inRule],
        ], [], [
            'site_notification_channel_ids' => __('channels'),
            'site_notification_event_keys' => __('events'),
        ]);

        $org = auth()->user()?->currentOrganization();
        $allowedChannelIds = AssignableNotificationChannels::forUser(auth()->user(), $org)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        foreach ($this->site_notification_channel_ids as $channelId) {
            if (! in_array((string) $channelId, $allowedChannelIds, true)) {
                $this->addError('site_notification_channel_ids', __('Invalid channel selected.'));

                return;
            }
        }

        NotificationSubscription::query()
            ->where('subscribable_type', Site::class)
            ->where('subscribable_id', $this->site->id)
            ->whereIn('event_key', self::SITE_NOTIFICATION_EVENT_KEYS)
            ->delete();

        if ($this->site_notification_channel_ids === [] || $this->site_notification_event_keys === []) {
            $this->dispatch('notify', message: __('Site notification subscriptions updated.'));
            $this->loadSiteNotificationPreferences();

            return;
        }

        foreach ($this->site_notification_channel_ids as $channelId) {
            $channel = NotificationChannel::query()->findOrFail((string) $channelId);
            Gate::authorize('manageNotificationChannels', $channel->owner);

            foreach ($this->site_notification_event_keys as $eventKey) {
                NotificationSubscription::query()->create([
                    'notification_channel_id' => $channel->id,
                    'subscribable_type' => Site::class,
                    'subscribable_id' => $this->site->id,
                    'event_key' => $eventKey,
                ]);
            }
        }

        $this->loadSiteNotificationPreferences();

        $auditOrg = $this->site->server?->organization ?? auth()->user()?->currentOrganization();
        if ($auditOrg) {
            audit_log($auditOrg, auth()->user(), 'site.notifications.subscriptions_updated', $this->site, null, [
                'channel_ids' => array_values(array_map('strval', $this->site_notification_channel_ids)),
                'event_keys' => array_values($this->site_notification_event_keys),
            ]);
        }

        $this->dispatch('notify', message: __('Site notification subscriptions saved.'));
    }

    public function saveSiteIntegrationWebhookDestination(): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage integration webhooks.'));

            return;
        }

        $this->validate([
            'site_int_hook_name' => 'required|string|max:120',
            'site_int_hook_driver' => 'required|string|in:slack,discord,teams',
            'site_int_hook_url' => 'required|string|url|max:2000',
        ]);

        $events = [];
        if ($this->site_int_evt_success) {
            $events[] = 'deploy_success';
        }
        if ($this->site_int_evt_failed) {
            $events[] = 'deploy_failed';
        }
        if ($this->site_int_evt_skipped) {
            $events[] = 'deploy_skipped';
        }
        if ($this->site_int_evt_deploy_started) {
            $events[] = 'deploy_started';
        }
        if ($this->site_int_evt_uptime_down) {
            $events[] = 'uptime_down';
        }
        if ($this->site_int_evt_uptime_recovered) {
            $events[] = 'uptime_recovered';
        }

        $created = NotificationWebhookDestination::query()->create([
            'organization_id' => $this->site->organization_id,
            'site_id' => $this->site->id,
            'name' => $this->site_int_hook_name,
            'driver' => $this->site_int_hook_driver,
            'webhook_url' => $this->site_int_hook_url,
            'events' => $events !== [] ? $events : null,
            'enabled' => true,
        ]);

        $org = $this->site->server?->organization ?? auth()->user()?->currentOrganization();
        if ($org) {
            audit_log($org, auth()->user(), 'site.integration_webhook.created', $this->site, null, [
                'destination_id' => (string) $created->id,
                'name' => $this->site_int_hook_name,
                'driver' => $this->site_int_hook_driver,
                'events' => $events,
            ]);
        }

        $this->reset([
            'site_int_hook_name',
            'site_int_hook_url',
        ]);
        $this->site_int_hook_driver = NotificationWebhookDestination::DRIVER_SLACK;
        $this->site_int_evt_success = true;
        $this->site_int_evt_failed = true;
        $this->site_int_evt_skipped = true;
        $this->site_int_evt_deploy_started = false;
        $this->site_int_evt_uptime_down = true;
        $this->site_int_evt_uptime_recovered = true;

        $this->dispatch('notify', message: __('Webhook destination saved.'));
    }

    public function deleteSiteIntegrationWebhookDestination(string $id): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage integration webhooks.'));

            return;
        }

        $hook = NotificationWebhookDestination::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('site_id', $this->site->id)
            ->whereKey($id)
            ->firstOrFail();
        $snapshot = [
            'destination_id' => (string) $hook->id,
            'name' => $hook->name,
            'driver' => $hook->driver,
            'events' => $hook->events,
        ];
        $hook->delete();

        $org = $this->site->server?->organization ?? auth()->user()?->currentOrganization();
        if ($org) {
            audit_log($org, auth()->user(), 'site.integration_webhook.deleted', $this->site, $snapshot, null);
        }

        $this->dispatch('notify', message: __('Webhook destination removed.'));
    }

    public function toggleSiteIntegrationWebhookDestination(string $id): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage integration webhooks.'));

            return;
        }

        $hook = NotificationWebhookDestination::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('site_id', $this->site->id)
            ->whereKey($id)
            ->firstOrFail();
        $hook->update(['enabled' => ! $hook->enabled]);

        $this->dispatch('notify', message: __('Webhook destination updated.'));
    }

    public function updatedSection(string $value): void
    {
        if ($value === 'routing' && $this->routingTab === 'dns') {
            $this->syncDnsSettingsForm();
        }
        if ($value === 'runtime' || $value === 'laravel-stack' || $value === 'system-user') {
            $this->syncGeneralSettingsForm();
            $this->syncFormFromSite();
        }

        if ($value === 'laravel-stack' && $this->site->isLaravelFrameworkDetected() && $this->laravel_tab === 'commands') {
            $this->loadLaravelArtisanDiscovery(false);
        }

        if ($value === 'notifications') {
            $this->loadSiteNotificationPreferences();
        }
    }

    protected function syncFormFromSite(): void
    {
        parent::syncFormFromSite();
        $this->syncLaravelConsoleForm();
    }

    protected function syncLaravelConsoleForm(): void
    {
        $executor = app(LaravelConsoleExecutor::class);
        $this->laravel_custom_commands_text = implode("\n", $executor->customCommands($this->site));
    }

    public function updatedLaravelTab(string $value): void
    {
        if ($value === 'commands') {
            $this->loadLaravelArtisanDiscovery(false);
        }
    }

    public function loadLaravelArtisanDiscovery(bool $force = false): void
    {
        $this->authorize('view', $this->site);
        $executor = app(LaravelConsoleExecutor::class);
        $this->laravel_artisan_discovery = $executor->listArtisanCommands($this->site->fresh(), $force);
    }

    /**
     * Schedule sub-tab loader (PR 11).
     *
     * Runs `php artisan schedule:list --json` via the new Artisan
     * service (PR 1+2) — sync execution because schedule:list is on
     * the INSTANT allowlist. Parses the JSON output into a flat array
     * the schedule-tab partial renders. Failures land as inline errors
     * rather than throwing; broken parsing leaves the entry list empty
     * with a friendly message.
     */
    public function loadLaravelSchedule(Artisan $artisan): void
    {
        $this->authorize('view', $this->site);

        try {
            $result = $artisan->run(
                site: $this->site,
                command: 'schedule:list',
                args: ['--json'],
                queuedBy: auth()->user(),
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('laravel_schedule', __('Your role can\'t inspect the Laravel schedule.'));

            return;
        }

        $stdout = trim($result->stdout());
        $rows = $stdout !== '' ? json_decode($stdout, associative: true) : [];

        $this->laravelScheduleEntries = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        $this->laravelScheduleLoaded = true;
    }

    /**
     * Migrations sub-tab loader (PR 11b).
     *
     * Runs `php artisan migrate:status --json` via the Artisan service.
     * The command is INSTANT-allowlisted so it returns inline. Parsed
     * rows feed the migrations-tab partial.
     */
    public function loadLaravelMigrations(Artisan $artisan): void
    {
        $this->authorize('view', $this->site);

        try {
            $result = $artisan->run(
                site: $this->site,
                command: 'migrate:status',
                args: ['--json'],
                queuedBy: auth()->user(),
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('laravel_migrations', __('Your role can\'t inspect migrations.'));

            return;
        }

        $stdout = trim($result->stdout());
        $rows = $stdout !== '' ? json_decode($stdout, associative: true) : [];

        $this->laravelMigrationEntries = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        $this->laravelMigrationsLoaded = true;
    }

    /**
     * Rollback the most-recent N migration batches, optionally taking
     * a pre-rollback snapshot via SnapshotService for the safety net
     * (Q9 + Q19). Admin/owner only because this is destructive — losing
     * data without a snapshot to restore from is a real possibility.
     */
    public function rollbackLastMigrationBatch(
        Artisan $artisan,
        SnapshotService $snapshots,
        ExecuteRemoteTaskOnServer $executor,
    ): void {
        $this->authorize('update', $this->site);

        $org = $this->site->organization;
        if ($org === null || ! $org->hasAdminAccess(auth()->user())) {
            $this->addError('laravel_migrations', __('Admin or owner role required to roll back migrations.'));

            return;
        }

        // Pre-rollback safety-net snapshot to local disk (Q19 transient).
        try {
            $snapshot = $snapshots->take(
                site: $this->site,
                destination: new LocalDiskDestination($executor),
                reason: Snapshot::REASON_PRE_MIGRATION_ROLLBACK,
                userId: auth()->id(),
            );
        } catch (\Throwable $e) {
            $this->addError('laravel_migrations', __('Pre-rollback snapshot failed; aborting rollback. :err', ['err' => $e->getMessage()]));

            return;
        }

        try {
            $artisan->run(
                site: $this->site,
                command: 'migrate:rollback',
                args: ['--force', '--step=1'],
                queuedBy: auth()->user(),
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('laravel_migrations', __('Permission denied: :err', ['err' => $e->getMessage()]));

            return;
        }

        $this->laravelMigrationsFlash = __('Rolled back last migration batch. Pre-rollback snapshot saved as snap-:id.', ['id' => $snapshot->id]);
        $this->laravelMigrationsLoaded = false; // Force reload to refresh status table
    }

    /**
     * Pail sub-tab loader.
     *
     * Initial load: fetches the last 200 lines + records the file's
     * current size as the byte offset to stream from. Subsequent calls
     * (driven by wire:poll when laravelPailLive is true OR by manual
     * Refresh) emit `tail -c +<offset+1>` so only the bytes appended
     * since last poll come back; appended to the buffer + trimmed at
     * PAIL_BUFFER_MAX_CHARS so chatty logs don't blow Livewire state.
     *
     * Real WebSocket/SSE streaming is still a v2 concern — wire:poll
     * is the right tradeoff for a logs panel where 2s latency is fine
     * and dply already has the polling primitive plumbed everywhere
     * (no new infra to maintain).
     */
    public function loadLaravelPail(ExecuteRemoteTaskOnServer $executor, int $lines = 200): void
    {
        $this->authorize('view', $this->site);

        $logPath = $this->laravelLogPath();
        $script = $this->laravelPailLoaded
            // Incremental fetch: send bytes after current offset, plus
            // the new file size so we can advance the offset locally.
            ? sprintf(
                'if [ -r %1$s ]; then SIZE=$(stat -c %%s %1$s 2>/dev/null || stat -f %%z %1$s); echo "DPLY-PAIL-SIZE:$SIZE"; tail -c +%2$d %1$s 2>/dev/null; else echo "DPLY-PAIL-MISSING"; fi',
                escapeshellarg($logPath),
                $this->laravelPailOffset + 1,
            )
            // First fetch: tail the last N lines AND record the file's
            // total size as the new offset so the next poll continues
            // from there.
            : sprintf(
                'if [ -r %1$s ]; then SIZE=$(stat -c %%s %1$s 2>/dev/null || stat -f %%z %1$s); echo "DPLY-PAIL-SIZE:$SIZE"; tail -n %2$d %1$s 2>/dev/null; else echo "DPLY-PAIL-MISSING"; fi',
                escapeshellarg($logPath),
                max(10, min(1000, $lines)),
            );

        try {
            $out = $executor->runInlineBash(
                server: $this->site->server,
                name: 'laravel:pail-tail',
                inlineBash: $script,
                timeoutSeconds: 15,
            );
        } catch (\Throwable $e) {
            $this->addError('laravel_pail', __('Pail tail failed: :err', ['err' => $e->getMessage()]));

            return;
        }

        $raw = (string) $out->getBuffer();

        if (str_starts_with(trim($raw), 'DPLY-PAIL-MISSING')) {
            $this->laravelPailBuffer = '(no log file at '.$logPath.')';
            $this->laravelPailLoaded = true;

            return;
        }

        // Strip the size header line and parse the new offset.
        if (preg_match('/^DPLY-PAIL-SIZE:(\d+)\n?/', $raw, $matches)) {
            $newOffset = (int) $matches[1];
            $body = substr($raw, strlen($matches[0]));
        } else {
            $newOffset = $this->laravelPailOffset;
            $body = $raw;
        }

        if ($this->laravelPailLoaded) {
            $this->laravelPailBuffer .= $body;
        } else {
            $this->laravelPailBuffer = $body;
            $this->laravelPailLoaded = true;
        }

        // Cap buffer so a chatty log doesn't blow up Livewire payload.
        if (strlen($this->laravelPailBuffer) > self::PAIL_BUFFER_MAX_CHARS) {
            $this->laravelPailBuffer = '… (older lines trimmed) …'."\n".substr($this->laravelPailBuffer, -self::PAIL_BUFFER_MAX_CHARS);
        }

        $this->laravelPailOffset = $newOffset;
    }

    /**
     * Operator-toggled live mode (wire:poll firing every 2s in the view).
     */
    public function toggleLaravelPailLive(): void
    {
        $this->laravelPailLive = ! $this->laravelPailLive;
    }

    /**
     * Manual reset — clears the buffer and re-baselines offset so
     * Refresh fetches the last 200 lines again instead of incremental.
     */
    public function resetLaravelPail(): void
    {
        $this->laravelPailBuffer = '';
        $this->laravelPailOffset = 0;
        $this->laravelPailLoaded = false;
    }

    private function laravelLogPath(): string
    {
        $deployPath = $this->site->document_root ?: $this->site->repository_path ?: '/home/dply/'.$this->site->slug;
        // strip "/public" suffix if document_root points at the public/ dir
        $deployBase = preg_replace('#/public/?$#', '', $deployPath);

        return rtrim((string) $deployBase, '/').'/storage/logs/laravel.log';
    }

    public function saveLaravelCustomCommands(LaravelConsoleExecutor $executor): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->toastError(__('Deployers cannot edit custom Artisan commands.'));

            return;
        }

        $lines = preg_split('/\R/', $this->laravel_custom_commands_text) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $t = trim((string) $line);
            if ($t === '') {
                continue;
            }
            $executor->assertSafeArtisanArgv($t);
            $clean[] = $t;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $lc = is_array($meta['laravel_console'] ?? null) ? $meta['laravel_console'] : [];
        $lc['custom_commands'] = $clean;
        $meta['laravel_console'] = $lc;
        $this->site->update(['meta' => $meta]);
        $this->site->refresh();
        $this->syncLaravelConsoleForm();
        $this->toastSuccess(__('Custom Artisan commands saved.'));
    }

    public function runLaravelArtisanPreset(string $argvTail, LaravelConsoleExecutor $executor): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->laravel_console_error = __('Deployers cannot run Artisan commands on servers.');
            $this->resetRemoteSshStreamTargets();

            return;
        }

        $this->laravel_console_error = null;
        $timeout = 600;

        try {
            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta(
                __('Artisan'),
                'php artisan '.trim($argvTail)
            );
            $executor->runArtisan(
                $this->site->fresh(),
                $argvTail,
                $timeout,
                fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk)
            );
        } catch (\Throwable $e) {
            $this->laravel_console_error = $e->getMessage();
        }
    }

    public function runLaravelApplicationLogTail(LaravelConsoleExecutor $executor): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->laravel_console_error = __('Deployers cannot tail Laravel logs.');
            $this->resetRemoteSshStreamTargets();

            return;
        }

        $this->validate([
            'laravel_log_tail_lines' => 'required|integer|min:50|max:5000',
        ]);

        $this->laravel_console_error = null;

        try {
            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta(
                __('Laravel log'),
                'tail -n '.(int) $this->laravel_log_tail_lines.' storage/logs/laravel.log'
            );
            $executor->tailLaravelLog(
                $this->site->fresh(),
                (int) $this->laravel_log_tail_lines,
                fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk)
            );
        } catch (\Throwable $e) {
            $this->laravel_console_error = $e->getMessage();
        }
    }

    public function saveLaravelOctaneTab(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->toastError(__('Octane settings apply to VM and container sites.'));

            return;
        }

        if (! $this->site->shouldShowPhpOctaneRolloutSettings() || ! $this->site->shouldShowOctaneRuntimeUi()) {
            return;
        }

        $this->validate([
            'octane_port' => 'nullable|integer|min:1|max:65535',
            'octane_server' => ['required', Rule::in(Site::OCTANE_SERVERS)],
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $lo = is_array($meta['laravel_octane'] ?? null) ? $meta['laravel_octane'] : [];
        $lo['server'] = $this->octane_server;
        $meta['laravel_octane'] = $lo;

        $this->site->update([
            'octane_port' => $this->octane_port !== '' ? (int) $this->octane_port : null,
            'meta' => $meta,
        ]);
        $this->site->refresh();
        $this->syncFormFromSite();
        $this->toastSuccess(__('Octane settings saved.'));
    }

    public function saveLaravelReverbTab(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->toastError(__('Reverb settings apply to VM and container sites.'));

            return;
        }

        if (! $this->site->shouldShowPhpOctaneRolloutSettings()) {
            return;
        }

        if (! $this->site->shouldShowLaravelReverbRuntimeUi() && ! $this->site->shouldProxyReverbInWebserver()) {
            return;
        }

        $this->validate([
            'laravel_reverb_port' => 'nullable|integer|min:1|max:65535',
            'laravel_reverb_ws_path' => ['nullable', 'string', 'max:128'],
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $rv = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
        $rv['port'] = $this->laravel_reverb_port !== '' ? (int) $this->laravel_reverb_port : 8080;
        $ws = trim($this->laravel_reverb_ws_path);
        $rv['ws_path'] = $ws !== '' ? $ws : '/app';
        $meta['laravel_reverb'] = $rv;

        $this->site->update(['meta' => $meta]);
        $this->site->refresh();
        $this->syncFormFromSite();
        $this->toastSuccess(__('Reverb settings saved.'));
    }

    public function saveLaravelSetupTab(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->toastError(__('These settings apply to VM and container sites.'));

            return;
        }

        if (! $this->site->shouldShowPhpOctaneRolloutSettings()) {
            return;
        }

        $this->validate([
            'laravel_horizon_path' => ['nullable', 'string', 'max:128'],
            'laravel_horizon_notes' => ['nullable', 'string', 'max:2000'],
            'laravel_pulse_path' => ['nullable', 'string', 'max:128'],
            'laravel_pulse_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];

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
        $this->site->refresh();
        $this->syncFormFromSite();
        $this->toastSuccess(__('Laravel setup notes saved.'));
    }

    public function saveLaravelStackSettings(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->toastError(__('Laravel stack settings apply to VM and container sites that use SSH deploy and managed web server config.'));

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
            $this->toastError(__('Runtime preferences apply to VM and container sites. Use Deploy for function and serverless targets.'));

            return;
        }

        $rules = [];

        if ($this->shouldShowRuntimePhpRolloutFields()) {
            $rules['laravel_scheduler'] = 'boolean';
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

        $update = [];

        if ($this->shouldShowRuntimePhpRolloutFields()) {
            $update['laravel_scheduler'] = $this->laravel_scheduler;
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
            $this->toastError(__('System user settings apply to VM-backed sites with managed PHP.'));

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
        $this->toastSuccess(__('System user settings saved.'));
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

    private function resolveRuntimeTabForSite(Site $site, mixed $tab): string
    {
        $allowed = array_keys(SiteSettingsSidebar::runtimeTabsFor($site));

        return is_string($tab) && in_array($tab, $allowed, true)
            ? $tab
            : 'overview';
    }

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
                $this->addError('settings_dns_zone', __('Add a DNS provider credential under Server providers (DigitalOcean, Hetzner, Linode, Vultr, or Cloudflare), or configure an app-level DigitalOcean token, to use a custom DNS zone.'));

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
                    } elseif (in_array($credForApi->provider, ['linode', 'akamai'], true)) {
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
     * the row only — the on-disk path under `/var/www/<slug>` is intentionally
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
            'new_alias_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        SiteDomainAlias::query()->create([
            'site_id' => $this->site->id,
            'hostname' => strtolower(trim($validated['new_alias_hostname'])),
            'label' => trim((string) ($validated['new_alias_label'] ?? '')) ?: null,
            'comment' => trim((string) ($validated['new_alias_comment'] ?? '')) ?: null,
            'sort_order' => (int) ($this->site->domainAliases()->max('sort_order') ?? 0) + 1,
        ]);

        $this->new_alias_hostname = '';
        $this->new_alias_label = '';
        $this->new_alias_comment = '';
        $this->site->load('domainAliases');
        $this->finalizeRoutingMutation('Alias added.');
    }

    public function confirmRemoveAlias(string $aliasId): void
    {
        $this->authorize('update', $this->site);
        $this->openConfirmActionModal(
            'removeAlias',
            [$aliasId],
            __('Remove alias'),
            __('Remove this alias from the webserver server_name list?'),
            __('Remove alias'),
            true,
        );
    }

    public function removeAlias(string $aliasId): void
    {
        $this->authorize('update', $this->site);

        $this->site->domainAliases()->findOrFail($aliasId)->delete();
        $this->site->load('domainAliases');
        $this->finalizeRoutingMutation('Alias removed.');
    }

    public function editAlias(string $aliasId): void
    {
        $this->authorize('update', $this->site);
        $alias = $this->site->domainAliases()->findOrFail($aliasId);
        $this->editing_alias_id = (string) $alias->id;
        $this->editing_alias_hostname = (string) $alias->hostname;
        $this->editing_alias_label = (string) ($alias->label ?? '');
        $this->editing_alias_comment = (string) ($alias->comment ?? '');
    }

    public function cancelEditAlias(): void
    {
        $this->editing_alias_id = null;
        $this->editing_alias_hostname = '';
        $this->editing_alias_label = '';
        $this->editing_alias_comment = '';
    }

    public function saveEditedAlias(): void
    {
        $this->authorize('update', $this->site);
        if ($this->editing_alias_id === null) {
            return;
        }
        $alias = $this->site->domainAliases()->findOrFail($this->editing_alias_id);
        $this->validate([
            'editing_alias_hostname' => [
                'required',
                'string',
                'max:255',
                Rule::unique('site_domain_aliases', 'hostname')->ignore($alias->id),
                Rule::unique('site_domains', 'hostname'),
                Rule::unique('site_preview_domains', 'hostname'),
                Rule::unique('site_tenant_domains', 'hostname'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid alias like www.example.com.');
                    }
                },
            ],
            'editing_alias_label' => ['nullable', 'string', 'max:255'],
            'editing_alias_comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $alias->forceFill([
            'hostname' => strtolower(trim($this->editing_alias_hostname)),
            'label' => trim($this->editing_alias_label) ?: null,
            'comment' => trim($this->editing_alias_comment) ?: null,
        ])->save();

        $this->cancelEditAlias();
        $this->site->load('domainAliases');
        $this->finalizeRoutingMutation('Alias updated.');
    }

    /**
     * Bulk paste aliases — `hostname` or `hostname,label` per line. Existing
     * hostnames (in any routing table) are silently skipped to make repeated
     * pastes safe.
     */
    public function bulkImportAliases(): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['bulk_alias_input' => 'required|string|max:65535']);

        $lines = preg_split('/\r\n|\r|\n/', trim($this->bulk_alias_input)) ?: [];
        $rows = [];
        foreach ($lines as $i => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode(',', $line, 2));
            $hostname = strtolower($parts[0] ?? '');
            $label = $parts[1] ?? null;
            if ($hostname === '' || ! HostnameValidator::isValid($hostname)) {
                $this->addError('bulk_alias_input', sprintf('Line %d: "%s" is not a valid hostname.', $i + 1, $hostname));

                return;
            }
            $rows[] = ['hostname' => $hostname, 'label' => $label];
        }

        // Filter out hostnames already present anywhere in the routing
        // namespace (domains, aliases, preview, tenants). Skipping silently
        // keeps `paste a snapshot from prod` ergonomic.
        $taken = collect()
            ->merge(SiteDomain::query()->pluck('hostname'))
            ->merge(SiteDomainAlias::query()->pluck('hostname'))
            ->merge(SitePreviewDomain::query()->pluck('hostname'))
            ->merge(SiteTenantDomain::query()->pluck('hostname'))
            ->map(fn ($h) => strtolower((string) $h))
            ->unique()
            ->all();

        $sortBase = (int) ($this->site->domainAliases()->max('sort_order') ?? 0);
        $imported = 0;
        foreach ($rows as $row) {
            if (in_array($row['hostname'], $taken, true)) {
                continue;
            }
            SiteDomainAlias::query()->create([
                'site_id' => $this->site->id,
                'hostname' => $row['hostname'],
                'label' => $row['label'] !== null && $row['label'] !== '' ? $row['label'] : null,
                'sort_order' => ++$sortBase,
            ]);
            $imported++;
        }

        $this->bulk_alias_input = '';
        $this->site->load('domainAliases');
        $this->finalizeRoutingMutation(__(':count alias(es) imported.', ['count' => $imported]));
    }

    public function addBasicAuthUser(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsBasicAuthProvisioning()) {
            $this->toastError(__('Basic authentication is not available for this site runtime.'));

            return;
        }

        app(SiteAccessGateService::class)->ensureBasicAuthMethod($this->site);
        $this->access_gate_method = SiteAccessGate::METHOD_BASIC_AUTH;

        $pathRules = ['required', 'string', 'max:512'];
        if (! $this->site->basicAuthSupportsPathPrefixes()) {
            $pathRules[] = Rule::in(['/', '']);
        }

        $validated = $this->validate([
            'new_basic_auth_username' => [
                'required',
                'string',
                'max:128',
                Rule::unique('site_basic_auth_users', 'username')
                    ->where(fn ($query) => $query
                        ->where('site_id', $this->site->id)
                        ->whereNull('pending_removal_at')),
            ],
            'new_basic_auth_password' => ['required', 'string', 'min:8', 'max:255'],
            'new_basic_auth_path' => $pathRules,
        ]);

        $path = SiteBasicAuthUser::normalizePath($validated['new_basic_auth_path'] ?? '/');
        if (! $this->site->basicAuthSupportsPathPrefixes() && $path !== '/') {
            $this->addError('new_basic_auth_path', __('Use path / for this site type.'));

            return;
        }

        if (! preg_match('#^(/|/[a-zA-Z0-9/_-]*)$#', $path)) {
            $this->addError('new_basic_auth_path', __('Enter a path like / or /wp-admin.'));

            return;
        }

        $username = trim($validated['new_basic_auth_username']);
        $passwordHash = $this->site->hashBasicAuthPassword($validated['new_basic_auth_password']);

        $pendingRow = $this->site->basicAuthUsers()
            ->where('username', $username)
            ->whereNotNull('pending_removal_at')
            ->first();

        if ($pendingRow !== null) {
            $pendingRow->forceFill([
                'password_hash' => $passwordHash,
                'path' => $path,
                'pending_removal_at' => null,
            ])->save();
            $savedMessage = __('Basic authentication user restored.');
        } else {
            SiteBasicAuthUser::query()->create([
                'site_id' => $this->site->id,
                'username' => $username,
                'password_hash' => $passwordHash,
                'path' => $path,
                'sort_order' => (int) ($this->site->basicAuthUsers()->max('sort_order') ?? 0) + 1,
            ]);
            $savedMessage = __('Basic authentication user saved.');
        }

        $this->new_basic_auth_username = '';
        $this->new_basic_auth_password = '';
        $this->new_basic_auth_path = '/';
        $this->site->load('basicAuthUsers');
        $this->dispatch('close-modal', 'add-basic-auth-modal');
        $this->finalizeRoutingMutation($savedMessage, __('Adding credential to :host …', ['host' => $this->site->server?->name ?? $this->site->name]));
    }

    /**
     * Open a confirm-modal before removing a basic-auth credential. The actual
     * mark-and-apply happens in {@see removeBasicAuthUser()} only after the
     * operator clicks through the modal.
     */
    public function confirmRemoveBasicAuthUser(string $userId): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsBasicAuthProvisioning()) {
            return;
        }

        $user = $this->site->basicAuthUsers()->findOrFail($userId);

        $this->openConfirmActionModal(
            'removeBasicAuthUser',
            [$userId],
            __('Remove credential?'),
            __('Stops :username from passing the basic-auth gate. The credential is marked Removing while the webserver config rewrites; we hard-delete the row only after the apply succeeds.', ['username' => $user->username]),
            __('Remove credential'),
            true,
        );
    }

    public function removeBasicAuthUser(string $userId): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsBasicAuthProvisioning()) {
            return;
        }

        // Stamp pending_removal_at instead of hard-deleting. The htpasswd-sync
        // step in the apply skips pending rows, so this row stops authenticating
        // the moment the apply succeeds. ApplySiteWebserverConfigJob hard-deletes
        // the row after a clean run — that way the UI never claims the credential
        // is gone before the webserver actually agrees.
        $user = $this->site->basicAuthUsers()->findOrFail($userId);
        if ($user->pending_removal_at === null) {
            $user->forceFill(['pending_removal_at' => now()])->save();
        }

        $this->site->load('basicAuthUsers');
        $this->finalizeRoutingMutation(
            __('Basic auth credential marked for removal — track the apply in the banner.'),
            __('Removing credential from :host …', ['host' => $this->site->server?->name ?? $this->site->name]),
        );
    }

    public function generateBasicAuthPassword(): void
    {
        $this->authorize('update', $this->site);
        $this->new_basic_auth_password = Str::password(20);
    }

    public function generateFormGatePassword(): void
    {
        $this->authorize('update', $this->site);
        $this->form_gate_password = Str::password(20);
    }

    public function selectAccessGateMethod(string $method): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsAccessGateProvisioning()) {
            return;
        }

        if (! in_array($method, [
            SiteAccessGate::METHOD_OFF,
            SiteAccessGate::METHOD_BASIC_AUTH,
            SiteAccessGate::METHOD_FORM_PASSWORD,
        ], true)) {
            return;
        }

        if ($method === SiteAccessGate::METHOD_FORM_PASSWORD && ! $this->site->webserverSupportsFormPasswordGate()) {
            $this->toastError(__('Password gate is not available for OpenLiteSpeed in this release.'));

            return;
        }

        $live = $this->site->resolvedAccessGateMethod();
        if ($method === $live && $method !== SiteAccessGate::METHOD_FORM_PASSWORD) {
            $this->access_gate_method = $method;

            return;
        }

        if ($method === SiteAccessGate::METHOD_FORM_PASSWORD && $live !== SiteAccessGate::METHOD_FORM_PASSWORD) {
            if ($live === SiteAccessGate::METHOD_BASIC_AUTH && $this->site->enforceableBasicAuthUsers()->isNotEmpty()) {
                $this->openConfirmActionModal(
                    'prepareFormPasswordGate',
                    [],
                    __('Switch to password gate?'),
                    __('HTTP basic auth credentials will be removed on the next webserver apply. Enter a new shared password below, then save.'),
                    __('Switch method'),
                    true,
                );

                return;
            }

            $this->access_gate_method = SiteAccessGate::METHOD_FORM_PASSWORD;

            return;
        }

        if ($live === SiteAccessGate::METHOD_FORM_PASSWORD && $method !== SiteAccessGate::METHOD_FORM_PASSWORD) {
            $this->openConfirmActionModal(
                'applyAccessGateMethod',
                [$method],
                __('Switch access method?'),
                __('The password gate will be removed on the next webserver apply.'),
                __('Switch method'),
                $method === SiteAccessGate::METHOD_OFF,
            );

            return;
        }

        if ($live === SiteAccessGate::METHOD_BASIC_AUTH && $this->site->enforceableBasicAuthUsers()->isNotEmpty() && $method === SiteAccessGate::METHOD_OFF) {
            $this->openConfirmActionModal(
                'applyAccessGateMethod',
                [$method],
                __('Turn off access protection?'),
                __('All basic auth credentials will be removed on the next webserver apply.'),
                __('Turn off protection'),
                true,
            );

            return;
        }

        $this->applyAccessGateMethod($method);
    }

    public function prepareFormPasswordGate(): void
    {
        $this->authorize('update', $this->site);
        app(SiteAccessGateService::class)->markAllBasicAuthUsersForRemoval($this->site);
        $this->access_gate_method = SiteAccessGate::METHOD_FORM_PASSWORD;
        $this->site->load('basicAuthUsers');
    }

    public function applyAccessGateMethod(string $method): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsAccessGateProvisioning()) {
            return;
        }

        $service = app(SiteAccessGateService::class);

        if ($method === SiteAccessGate::METHOD_OFF) {
            $service->markAllBasicAuthUsersForRemoval($this->site);
            $service->disable($this->site);
            $this->access_gate_method = SiteAccessGate::METHOD_OFF;
            $this->site->load(['accessGate', 'basicAuthUsers']);
            $this->finalizeRoutingMutation(__('Access protection turned off.'));

            return;
        }

        if ($method === SiteAccessGate::METHOD_BASIC_AUTH) {
            $wasForm = $this->site->usesFormPasswordGate();
            app(SiteAccessGateService::class)->markAllFormGatePasswordsForRemoval($this->site);
            $gate = SiteAccessGate::query()->firstOrNew(['site_id' => $this->site->id]);
            if (! $gate->exists) {
                $gate->cookie_secret = Str::random(48);
            }
            $gate->method = SiteAccessGate::METHOD_BASIC_AUTH;
            $gate->password_salt = null;
            $gate->password_verifier = null;
            $gate->save();
            $this->access_gate_method = SiteAccessGate::METHOD_BASIC_AUTH;
            $this->site->load(['accessGate', 'basicAuthUsers']);

            if ($wasForm) {
                $this->finalizeRoutingMutation(__('Switched to HTTP basic auth.'));
            }

            return;
        }

        $this->access_gate_method = SiteAccessGate::METHOD_FORM_PASSWORD;
    }

    public function saveFormGatePassword(): void
    {
        $this->addFormGatePassword();
    }

    public function addFormGatePassword(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsAccessGateProvisioning()) {
            $this->toastError(__('Access protection is not available for this site runtime.'));

            return;
        }

        if (! $this->site->webserverSupportsFormPasswordGate()) {
            $this->toastError(__('Password gate is not available for OpenLiteSpeed in this release.'));

            return;
        }

        $validated = $this->validate([
            'new_form_gate_label' => ['required', 'string', 'max:64'],
            'form_gate_password' => ['required', 'string', 'min:8', 'max:255'],
        ], [], [
            'new_form_gate_label' => __('label'),
            'form_gate_password' => __('password'),
        ]);

        app(SiteAccessGateService::class)->addFormGatePassword(
            $this->site,
            $validated['new_form_gate_label'],
            $validated['form_gate_password'],
        );

        $this->new_form_gate_label = '';
        $this->form_gate_password = '';
        $this->access_gate_method = SiteAccessGate::METHOD_FORM_PASSWORD;
        $this->form_gate_login_log_loaded = false;
        $this->dispatch('close-modal', 'add-form-gate-modal');
        $this->site->load(['accessGate', 'accessGatePasswords', 'basicAuthUsers']);
        $this->finalizeRoutingMutation(
            __('Password gate credential saved.'),
            __('Applying password gate on :host …', ['host' => $this->site->server?->name ?? $this->site->name]),
        );
    }

    public function loadFormGateLoginLog(): void
    {
        $this->authorize('view', $this->site);

        if (! $this->site->usesFormPasswordGate()) {
            $this->form_gate_login_log = [];
            $this->form_gate_login_log_loaded = true;

            return;
        }

        $this->form_gate_login_log = app(SiteAccessGateLoginLogReader::class)->recent($this->site);
        $this->form_gate_login_log_loaded = true;
    }

    public function confirmRemoveFormGatePassword(string $passwordId): void
    {
        $this->authorize('update', $this->site);

        $row = SiteAccessGatePassword::query()
            ->where('site_id', $this->site->id)
            ->where('id', $passwordId)
            ->first();

        if ($row === null || $row->isPendingRemoval()) {
            return;
        }

        $this->openConfirmActionModal(
            'removeFormGatePassword',
            [$passwordId],
            __('Remove password gate credential?'),
            __(':label will stop working after the next webserver apply.', ['label' => $row->label]),
            __('Remove credential'),
            true,
        );
    }

    public function removeFormGatePassword(string $passwordId): void
    {
        $this->authorize('update', $this->site);

        app(SiteAccessGateService::class)->markFormGatePasswordForRemoval($this->site, $passwordId);
        $this->site->load(['accessGate', 'accessGatePasswords']);

        if ($this->site->enforceableAccessGatePasswords()->isEmpty()) {
            $gate = $this->site->accessGate;
            if ($gate !== null) {
                $gate->method = SiteAccessGate::METHOD_FORM_PASSWORD;
                $gate->save();
            }
        }

        $this->finalizeRoutingMutation(__('Password gate credential marked for removal.'));
    }

    public function disableFormGatePassword(): void
    {
        $this->authorize('update', $this->site);

        $this->openConfirmActionModal(
            'applyAccessGateMethod',
            [SiteAccessGate::METHOD_OFF],
            __('Remove password gate?'),
            __('Visitors will reach the site without the login form after the next webserver apply.'),
            __('Remove gate'),
            true,
        );
    }

    /**
     * Dispatches a backgrounded job that walks the server, finds every .htpasswd
     * inside the site repo, and imports the user entries Dply doesn't already
     * track. Progress streams into a console_actions row whose banner is mounted
     * at the top of the settings page, so the operator can watch the scan happen
     * line-by-line instead of seeing a synchronous toast that hides the work.
     */
    public function syncBasicAuthFromServer(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsBasicAuthProvisioning()) {
            $this->toastError(__('Basic authentication is not available for this site runtime.'));

            return;
        }

        // Seed a queued ConsoleAction row BEFORE dispatch so the page-top banner
        // shows immediately on this re-render. Without this, the row only exists
        // once the worker calls beginConsoleAction(), which is async — the
        // banner reads from the DB on parent render and would stay empty until
        // the user navigated or another action triggered a re-render.
        $run = $this->seedQueuedConsoleAction('basic_auth_sync');

        SyncBasicAuthFromServerJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('Basic-auth sync finished.'),
            __('Basic-auth sync did not finish.'),
        );
        $this->toastConsoleActionQueued();
    }

    // seedQueuedConsoleAction lives on Show.php and is inherited — see
    // {@see \App\Livewire\Sites\Show::seedQueuedConsoleAction()}.

    // The webserver-apply banner is now read in settings.blade as a `console_actions`
    // query and rendered through `livewire.partials.console-action-banner-static`,
    // which lives in the parent component's render tree (no nested Livewire component).
    // The banner's Dismiss button posts to {@see dismissConsoleActionRun()} below.

    /**
     * @param  string  $customPassword  Operator-supplied plaintext from the rotate
     *                                  dialog. The dialog generates a random default and lets the operator copy
     *                                  it before submit, so by the time we hit this method the value is always
     *                                  present. Validated against the same min:8/max:255 rules used by the
     *                                  add-credential form.
     */
    public function rotateBasicAuthPassword(string $userId, string $customPassword): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsBasicAuthProvisioning()) {
            $this->toastError(__('Basic authentication is not available for this site runtime.'));

            return;
        }

        /** @var SiteBasicAuthUser $user */
        $user = $this->site->basicAuthUsers()->findOrFail($userId);

        $validator = Validator::make(
            ['password' => $customPassword],
            ['password' => ['required', 'string', 'min:8', 'max:255']],
        );
        if ($validator->fails()) {
            $this->toastError($validator->errors()->first('password') ?: __('Password must be 8–255 characters.'));

            return;
        }

        $user->password_hash = $this->site->hashBasicAuthPassword($customPassword);
        $user->save();

        $this->site->load('basicAuthUsers');
        $this->finalizeRoutingMutation(
            __('Password rotated.'),
            __('Rotating credential password on :host …', ['host' => $this->site->server?->name ?? $this->site->name]),
        );
    }

    public function bulkImportBasicAuth(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsBasicAuthProvisioning()) {
            $this->toastError(__('Basic authentication is not available for this site runtime.'));

            return;
        }

        $path = SiteBasicAuthUser::normalizePath($this->bulk_basic_auth_path ?: '/');
        if (! $this->site->basicAuthSupportsPathPrefixes() && $path !== '/') {
            $this->addError('bulk_basic_auth_path', __('Use path / for this site type.'));

            return;
        }
        if (! preg_match('#^(/|/[a-zA-Z0-9/_-]*)$#', $path)) {
            $this->addError('bulk_basic_auth_path', __('Enter a path like / or /wp-admin.'));

            return;
        }

        $raw = (string) $this->bulk_basic_auth_input;
        if (trim($raw) === '') {
            $this->addError('bulk_basic_auth_input', __('Paste at least one user:password line.'));

            return;
        }

        $existing = $this->site->basicAuthUsers()->notPendingRemoval()->pluck('username')->all();
        $seen = [];
        $created = 0;
        $skipped = 0;
        $invalid = 0;
        $sortBase = (int) ($this->site->basicAuthUsers()->max('sort_order') ?? 0);

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Split on the first colon — htpasswd-style lines for bcrypt/apr1 contain `$` chars
            // and additional colons in some encodings, so we never split more than once.
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0 || $colon === strlen($line) - 1) {
                $invalid++;

                continue;
            }
            $username = trim(substr($line, 0, $colon));
            $secret = substr($line, $colon + 1);

            if ($username === '' || strlen($username) > 128 || ! preg_match('/^[A-Za-z0-9._@\-]+$/', $username)) {
                $invalid++;

                continue;
            }
            if (in_array($username, $seen, true)) {
                $skipped++;

                continue;
            }

            // Accept already-hashed entries (bcrypt $2y / apr1 $apr1$ / sha $5$/$6$) verbatim;
            // otherwise treat the secret as plaintext and bcrypt-hash it server-side.
            $alreadyHashed = (bool) preg_match('/^\$(2[aby]|apr1|5|6)\$/', $secret);
            if (! $alreadyHashed && (strlen($secret) < 8 || strlen($secret) > 255)) {
                $invalid++;

                continue;
            }
            $hash = $alreadyHashed ? $secret : $this->site->hashBasicAuthPassword($secret);

            $pendingRow = $this->site->basicAuthUsers()
                ->where('username', $username)
                ->whereNotNull('pending_removal_at')
                ->first();

            if ($pendingRow !== null) {
                $pendingRow->forceFill([
                    'password_hash' => $hash,
                    'path' => $path,
                    'pending_removal_at' => null,
                ])->save();
                $seen[] = $username;
                $created++;

                continue;
            }

            if (in_array($username, $existing, true)) {
                $skipped++;

                continue;
            }

            SiteBasicAuthUser::query()->create([
                'site_id' => $this->site->id,
                'username' => $username,
                'password_hash' => $hash,
                'path' => $path,
                'sort_order' => ++$sortBase,
            ]);
            $seen[] = $username;
            $existing[] = $username;
            $created++;
        }

        if ($created === 0) {
            $this->addError('bulk_basic_auth_input', __('No valid user:password lines were found.'));

            return;
        }

        $this->bulk_basic_auth_input = '';
        $this->bulk_basic_auth_path = '/';
        $this->site->load('basicAuthUsers');
        $this->dispatch('close-modal', 'add-basic-auth-modal');

        $message = trans_choice(
            '{1} :count user imported.|[2,*] :count users imported.',
            $created,
            ['count' => $created],
        );
        if ($skipped > 0 || $invalid > 0) {
            $detail = [];
            if ($skipped > 0) {
                $detail[] = trans_choice('{1} :count duplicate skipped|[2,*] :count duplicates skipped', $skipped, ['count' => $skipped]);
            }
            if ($invalid > 0) {
                $detail[] = trans_choice('{1} :count invalid line|[2,*] :count invalid lines', $invalid, ['count' => $invalid]);
            }
            $message .= ' ('.implode(', ', $detail).')';
        }

        $this->finalizeRoutingMutation(
            $message,
            __('Importing credentials to :host …', ['host' => $this->site->server?->name ?? $this->site->name]),
        );
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
            'new_tenant_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        SiteTenantDomain::query()->create([
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

        $this->site->tenantDomains()->findOrFail($tenantDomainId)->delete();
        $this->site->load('tenantDomains');
        $this->finalizeRoutingMutation('Tenant domain removed.');
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
        $previewDomain->delete();

        $this->site->load('previewDomains');
        $this->syncPreviewSettingsForm();
        $this->finalizeRoutingMutation('Preview domain removed.');
    }

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
        $this->dispatch('open-modal', 'quick-domain-ssl-modal');
    }

    public function closeQuickDomainSslModal(): void
    {
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
            } else {
                $this->toastSuccess(__('Setup command finished.'));
            }
        } catch (\Throwable $e) {
            $this->laravel_ssh_setup_error = $e->getMessage();
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

    public function loadSystemUsersForPanel(ServerPasswdUserLister $lister, ServerSystemUserService $service): void
    {
        $this->authorize('update', $this->site);
        $this->system_user_list_error = null;

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

    public function openSystemUserResetPermissionsModal(): void
    {
        $this->authorize('update', $this->site);
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'site-reset-permissions-modal');
    }

    public function closeSystemUserResetPermissionsModal(): void
    {
        $this->dispatch('close-modal', 'site-reset-permissions-modal');
    }

    public function closeSystemUserAssignModal(): void
    {
        $this->dispatch('close-modal', 'site-system-user-assign-modal');
    }

    public function queueAssignSystemUser(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldShowSystemUserPanel()) {
            return;
        }

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->toastError(__('The server must be ready with SSH.'));

            return;
        }

        $allowed = collect($this->system_user_remote_rows)->pluck('username')->filter()->all();
        $this->validate([
            'system_user_assign_username' => ['required', 'string', 'max:64', Rule::in($allowed)],
        ]);

        AssignSystemUserToSiteJob::dispatch(
            $this->site->id,
            $this->system_user_assign_username,
            auth()->id(),
        );

        $this->closeSystemUserAssignModal();
        $this->toastSuccess(__('System user assignment queued. Refresh in a moment to see updates.'));
    }

    public function queueResetSitePermissions(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldShowSystemUserPanel()) {
            return;
        }

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->toastError(__('The server must be ready with SSH.'));
            $this->closeSystemUserResetPermissionsModal();

            return;
        }

        SiteResetPermissionsJob::dispatch($this->site->id);

        $this->closeSystemUserResetPermissionsModal();
        $this->toastSuccess(__('Reset permissions queued. Refresh in a moment for results.'));
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

    /**
     * Recent SiteDeployment rows that carry structured phase_results
     * (i.e. went through the new DeployPhaseRunner). Used by the
     * settings.blade.php "Recent deployments" panel so the view stays
     * free of inline @php(…) blocks that fight Blade's lexer when the
     * expression has nested parens / method chains.
     */
    public function getRecentDeploymentsWithPhasesProperty()
    {
        return $this->site->deployments()
            ->whereNotNull('phase_results')
            ->orderByDesc('started_at')
            ->limit(5)
            ->get();
    }

    /**
     * Most recent SiteDeployment for the general tab "Last deploy" badge.
     *
     * @property-read SiteDeployment|null $latestDeployment
     */
    #[Computed]
    public function latestDeployment(): ?SiteDeployment
    {
        return $this->site->latestDeployment();
    }

    public function render(): View
    {
        $this->resolveWatchedConsoleAction();

        if (! $this->site->isReadyForWorkspace()) {
            return parent::render();
        }

        $section = $this->section;

        $this->site->loadMissing($this->relationsForSettingsSection($section));
        $this->server->loadMissing('workspace');

        $org = $this->site->organization;
        $needsDeploymentSurface = $this->sectionNeedsDeploymentSurface($section);

        $deploymentContract = $needsDeploymentSurface
            ? app(DeploymentContractBuilder::class)->build($this->site)
            : null;
        $deploymentPreflight = $needsDeploymentSurface
            ? app(DeploymentPreflightValidator::class)->validate($this->site)
            : [];

        $viewData = [
            'tabs' => config('site_settings.workspace_tabs', []),
            'routingTabs' => self::ROUTING_TABS,
            'deployHookUrl' => $this->site->deployHookUrl(),
            'deploymentContract' => $deploymentContract,
            'deploymentPreflight' => $deploymentPreflight,
        ];

        if ($section === 'notifications') {
            $viewData['assignableNotificationChannels'] = AssignableNotificationChannels::forUser(auth()->user(), $org);
            $viewData['siteNotificationEventLabels'] = config('notification_events.categories.site.events', []);
            $viewData['siteIntegrationWebhookDestinations'] = NotificationWebhookDestination::query()
                ->where('organization_id', $this->site->organization_id)
                ->where('site_id', $this->site->id)
                ->orderBy('name')
                ->get();
        }

        if (in_array($section, ['settings', 'general'], true)) {
            $viewData['availableWorkspaces'] = Workspace::query()
                ->where('organization_id', $this->site->organization_id)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        if ($section === 'certificates' || ($section === 'routing' && $this->routingTab === 'dns')) {
            $viewData['providerCredentials'] = ProviderCredential::query()
                ->where('organization_id', $this->site->organization_id)
                ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
                ->orderBy('name')
                ->get(['id', 'name', 'provider']);
        }

        if (
            $section === 'runtime'
            && $this->runtimeTab === 'php'
            && $this->server->hostCapabilities()->supportsMachinePhpManagement()
        ) {
            $viewData['sitePhpData'] = app(ServerPhpManager::class)->sitePhpData($this->server, $this->site);
        }

        if (in_array($section, ['deploy', 'repository', 'pipeline'], true)) {
            $viewData['repositorySyncGroup'] = app(SiteDeploySyncGroupManager::class)->findGroupForSite($this->site)?->load(['sites.server', 'leader']);
            $viewData['organizationSites'] = Site::query()
                ->where('organization_id', $this->site->organization_id)
                ->with('server:id,name')
                ->orderBy('name')
                ->get();
        }

        return view('livewire.sites.settings', array_merge(
            SiteSettingsViewData::for(
                $this->server,
                $this->site,
                $section,
                $deploymentContract,
                $deploymentPreflight,
                auth()->user(),
            ),
            $viewData,
        ));
    }

    /**
     * @return list<string>
     */
    private function relationsForSettingsSection(string $section): array
    {
        $shared = ['certificates', 'certificates.previewDomain'];

        $sectionRelations = match ($section) {
            'general' => ['domains', 'domainAliases', 'deployments', 'previewDomains', 'workspace'],
            'settings' => ['workspace', 'workspace.variables'],
            'routing' => ['domains', 'domainAliases', 'redirects', 'tenantDomains', 'previewDomains', 'dnsProviderCredential'],
            'certificates' => ['previewDomains'],
            'repository' => ['deployments'],
            'pipeline' => ['deployHooks', 'deploySteps'],
            'deploy' => ['deployHooks', 'deploySteps', 'deployments'],
            'environment' => ['workspace', 'workspace.variables'],
            'logs' => ['deployments', 'webhookDeliveryLogs'],
            'notifications' => ['notificationSubscriptions'],
            'basic-auth' => ['basicAuthUsers', 'accessGate', 'accessGatePasswords'],
            'laravel-stack', 'rails-stack' => ['workspace', 'workspace.variables'],
            default => [],
        };

        return array_values(array_unique(array_merge($shared, $sectionRelations)));
    }

    private function sectionNeedsDeploymentSurface(string $section): bool
    {
        return in_array($section, [
            'general',
            'deploy',
            'repository',
            'pipeline',
            'runtime',
            'environment',
            'laravel-stack',
            'rails-stack',
        ], true);
    }
}
