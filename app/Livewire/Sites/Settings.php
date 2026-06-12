<?php

namespace App\Livewire\Sites;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Livewire\Concerns\ManagesContainerSite;
use App\Livewire\Concerns\ManagesSiteBindings;
use App\Livewire\Concerns\ManagesSiteLogging;
use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Livewire\Sites\Concerns\ManagesErrorsNotifications;
use App\Livewire\Sites\Concerns\ManagesSiteAccessGate;
use App\Livewire\Sites\Concerns\ManagesSiteAliases;
use App\Livewire\Sites\Concerns\ManagesSiteCertificates;
use App\Livewire\Sites\Concerns\ManagesSiteDeploySync;
use App\Livewire\Sites\Concerns\ManagesSiteDomainsGeneral;
use App\Livewire\Sites\Concerns\ManagesSiteLaravelRuntime;
use App\Livewire\Sites\Concerns\ManagesSiteLogo;
use App\Livewire\Sites\Concerns\ManagesSiteNotificationsTab;
use App\Livewire\Sites\Concerns\ManagesSiteRuntimeHealth;
use App\Livewire\Sites\Concerns\ManagesSiteSettingsView;
use App\Livewire\Sites\Concerns\ManagesSiteSystemUsers;
use App\Livewire\Sites\Concerns\ManagesSiteTenantDomains;
use App\Livewire\Sites\Concerns\ManagesSiteWorkerPool;
use App\Models\ErrorEvent;
use App\Models\NotificationWebhookDestination;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\Workspace;
use App\Services\Deploy\DeploymentContractBuilder;
use App\Services\Deploy\DeploymentPreflightValidator;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\Servers\ServerPhpManager;
use App\Services\Sites\SiteDeploySyncGroupManager;
use App\Support\SiteErrorsNotificationKeys;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class Settings extends Show
{
    use CreatesNotificationChannelInline;
    use DismissesConsoleActionRun;
    use ManagesContainerSite;
    use ManagesSiteAccessGate;
    use ManagesSiteAliases;
    use ManagesSiteBindings;
    use ManagesSiteCertificates;
    use ManagesSiteDeploySync;
    use ManagesSiteDomainsGeneral;
    use ManagesSiteLaravelRuntime;
    use ManagesSiteLogging;
    use ManagesSiteLogo;
    use ManagesSiteNotificationsTab;
    use ManagesSiteRuntimeHealth;
    use ManagesSiteSettingsView;
    use ManagesSiteSystemUsers;
    use ManagesSiteTenantDomains;
    use ManagesSiteWorkerPool;
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

    /**
     * Site events routable from the central Settings → Notifications matrix. The
     * site.errors.* keys are ALSO editable from the Errors → Notifications tab
     * ({@see ManagesErrorsNotifications}); both
     * surfaces write the same Site subscriptions, so they stay in sync. Keep this
     * list aligned with {@see SiteErrorsNotificationKeys::eventKeys()}.
     *
     * @var list<string>
     */
    private const SITE_NOTIFICATION_EVENT_KEYS = [
        'site.deployments',
        'site.deployment_started',
        'site.uptime.down',
        'site.uptime.degraded',
        'site.ssl.expiring',
        'site.errors.deploy_failed',
        'site.errors.operation_failed',
    ];

    public string $section = 'general';

    public string $routingTab = 'domains';

    public string $runtimeTab = 'overview';

    /** @var list<string> */
    public const NOTIF_TABS = ['subscriptions', 'webhooks'];

    /** Soft cap on buffer size (chars) so a chatty log doesn't OOM Livewire state. */
    public const PAIL_BUFFER_MAX_CHARS = 64_000;

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
        $this->notifTab = in_array((string) request()->query('tab'), self::NOTIF_TABS, true)
            ? (string) request()->query('tab')
            : 'subscriptions';

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

        if ($this->section === 'system-user') {
            $this->seedSystemUsersFromStore();
        }
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

        if ($value === 'system-user') {
            $this->seedSystemUsersFromStore();
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
        $this->worker_mode = $this->site->isWorkerSite();
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $this->worker_page_html = (string) ($meta['worker_page_html'] ?? '');
    }

    private function resolveRoutingTab(mixed $tab): string
    {
        return is_string($tab) && in_array($tab, self::ROUTING_TABS, true)
            ? $tab
            : self::ROUTING_TABS[0];
    }

    // seedQueuedConsoleAction lives on Show.php and is inherited — see
    // {@see \App\Livewire\Sites\Show::seedQueuedConsoleAction()}.

    // The webserver-apply banner is now read in settings.blade as a `console_actions`
    // query and rendered through `livewire.partials.console-action-banner-static`,
    // which lives in the parent component's render tree (no nested Livewire component).
    // The banner's Dismiss button posts to {@see dismissConsoleActionRun()} below.

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
        // Reuse the contract just built — validate() rebuilds it otherwise,
        // doubling the most expensive piece of the render.
        $deploymentPreflight = $needsDeploymentSurface
            ? app(DeploymentPreflightValidator::class)->validate($this->site, $deploymentContract)
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
            // The per-channel matrix groups the site's own events and the error-stream
            // events (the latter also editable from the Errors → Notifications tab).
            $viewData['notificationEventGroups'] = [
                [
                    'label' => __('Deploys & uptime'),
                    'events' => (array) config('notification_events.categories.site.events', []),
                ],
                [
                    'label' => __('Error stream'),
                    'events' => (array) config('notification_events.categories.site_errors.events', []),
                ],
            ];
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

        if ($section === 'runtime' && $this->runtimeTab === 'overview') {
            // Cheap indexed read (site_id, occurred_at) — safe on the render path.
            // The Overview shows a short tail; the full stream lives on the Errors tab.
            $viewData['runtimeRecentErrors'] = ErrorEvent::query()
                ->where('site_id', (string) $this->site->id)
                ->whereNull('dismissed_at')
                ->orderByDesc('occurred_at')
                ->limit(5)
                ->get();
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
}
