<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Services\Billing\EdgeSiteAccessAnalytics;
use App\Services\Billing\EdgeSiteBillingAnalytics;
use App\Services\Billing\EdgeSiteTrafficAnalytics;
use App\Services\Billing\ManagedProductCostEstimator;
use App\Support\Deployment\DeploymentContract;
use App\Support\Docs\ContextualDocResolver;
use App\Support\SiteSettingsHeader;
use App\Support\SiteSettingsSidebar;
use Illuminate\Support\Collection;

/**
 * View-model for {@see resources/views/livewire/sites/settings.blade.php}. Keeps
 * catalog/setup out of the site settings blade tree.
 */
final class SiteSettingsViewData
{
    /**
     * @param  array<string, mixed>  $deploymentPreflight
     * @return array<string, mixed>
     */
    public static function for(
        Server $server,
        Site $site,
        string $section,
        ?DeploymentContract $deploymentContract,
        array $deploymentPreflight,
        ?User $user = null,
    ): array {
        if ($site->usesEdgeRuntime()) {
            return self::forEdgeWorkspace($server, $site, $section, $user);
        }

        $functionsHost = $server->hostCapabilities()->supportsFunctionDeploy();
        $supportsMachinePhp = $server->hostCapabilities()->supportsMachinePhpManagement();
        $supportsWebserverProvisioning = $server->hostCapabilities()->supportsWebserverProvisioning();
        $showWebserverConfigEditor = $server->hostCapabilities()->supportsSsh()
            && ! $site->usesFunctionsRuntime()
            && ! $site->usesDockerRuntime()
            && ! $site->usesKubernetesRuntime();
        $supportsHttp3Certificates = $server->hostCapabilities()->supportsHttp3Certificates();
        $supportsEnvPush = $server->hostCapabilities()->supportsEnvPushToHost();
        $supportsSshDeployHooks = $server->hostCapabilities()->supportsSshDeployHooks();
        $testingHostname = $site->testingHostname();
        $deployVariableReference = [
            '{SITE_NAME}' => __('Site display name.'),
            '{SITE_DOMAIN}' => __('Primary domain or testing hostname.'),
            '{SITE_PATH}' => __('Active deploy path on the host.'),
            '{BRANCH}' => __('Configured Git branch.'),
            '{DEPLOY_ENV}' => __('Selected environment group used for key/value vars.'),
            '{PHP_VERSION}' => __('Site PHP version when the runtime is PHP-backed.'),
            '{RAILS_ENV}' => __('Rails env from site settings (Settings → Runtime / Deploy); substituted in hook scripts before run.'),
            '{RELEASE_DIR}' => __('Release directory for this deploy (new release folder or live checkout path).'),
            '{BASE_DIR}' => __('Site root on the server (parent of releases/ and current for atomic deploys).'),
            '{REPO_URL}' => __('Configured Git repository URL.'),
            '{GIT_SSH_PREFIX}' => __('Export prefix for deploy-key SSH when a key is configured; empty otherwise.'),
            '{CURRENT_LINK}' => __('Path to the current symlink (atomic) or same as release dir (simple).'),
        ];
        $deployHookPhaseLabels = [
            SiteDeployHook::PHASE_BEFORE_CLONE => __('Before clone'),
            SiteDeployHook::PHASE_AFTER_CLONE => __('After clone'),
            SiteDeployHook::PHASE_AFTER_ACTIVATE => __('After activate'),
        ];
        $runtimeMode = $site->runtimeTargetMode();
        $runtimePlatform = $site->runtimeTargetPlatform();
        $runtimeFamily = $site->runtimeTargetFamily();
        $isContainerWorkspace = in_array($runtimeMode, ['docker', 'kubernetes', 'serverless'], true)
            || $site->usesContainerRuntime();
        $settingsSidebarItems = SiteSettingsSidebar::items($site, $server);
        $routingTabIcons = [
            'domains' => 'heroicon-o-globe-alt',
            'dns' => 'heroicon-o-signal',
            'aliases' => 'heroicon-o-link',
            'redirects' => 'heroicon-o-arrow-uturn-right',
            'preview' => 'heroicon-o-sparkles',
            'tenants' => 'heroicon-o-building-office-2',
        ];
        $routingTabLabels = [
            'dns' => __('DNS'),
        ];
        $runtimeTabs = SiteSettingsSidebar::runtimeTabsFor($site);
        $runtimeTabIcons = [
            'overview' => 'heroicon-o-cube-transparent',
            'php' => 'heroicon-o-command-line',
            'ruby' => 'heroicon-o-command-line',
            'static' => 'heroicon-o-document',
        ];
        $previewDomain = $site->primaryPreviewDomain();
        $activeCertificate = $site->certificates->firstWhere('status', SiteCertificate::STATUS_ACTIVE);
        $pendingCertificate = $activeCertificate
            ? null
            : $site->certificates->first(fn ($certificate) => in_array($certificate->status, [
                SiteCertificate::STATUS_PENDING,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_INSTALLING,
                SiteCertificate::STATUS_FAILED,
            ], true));
        $latestCertificate = $activeCertificate ?? $pendingCertificate ?? $site->certificates->first();
        $serverlessRuntime = $site->usesFunctionsRuntime() ? $site->serverlessConfig() : [];
        $dockerRuntime = $site->usesDockerRuntime() && is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [];
        $kubernetesRuntime = $site->usesKubernetesRuntime() && is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
        $runtimeTarget = $site->runtimeTarget();
        $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
        $foundationStatus = is_array($deploymentContract?->status ?? null) ? $deploymentContract->status : [];
        $foundationSecrets = collect($deploymentContract?->secretArrays() ?? [])->filter(fn ($entry) => is_array($entry))->values();
        $secretConfigEntries = $foundationSecrets
            ->reject(fn (array $entry): bool => str_starts_with((string) ($entry['key'] ?? ''), 'DPLY_'))
            ->sortBy('key')
            ->values();
        $secretEntries = $secretConfigEntries->where('is_secret', true)->values();
        $configEntries = $secretConfigEntries->where('is_secret', false)->values();
        $secretDeliveryLabel = match ($runtimeMode) {
            'docker' => __('Injected into the managed Docker runtime inputs Dply builds for this site.'),
            'kubernetes' => __('Injected into generated Kubernetes `Secret` and `ConfigMap` resources before apply.'),
            'serverless' => __('Injected into the provider runtime environment payload during publish.'),
            default => __('Injected into the site environment Dply manages on the host for deploys and runtime use.'),
        };
        $resourceBindings = collect($deploymentContract?->resourceBindingArrays() ?? [])->filter(fn ($entry) => is_array($entry))->values();
        $preflightChecks = collect($deploymentPreflight['checks'] ?? [])->filter(fn ($entry) => is_array($entry))->values();
        $preflightErrors = collect($deploymentPreflight['errors'] ?? [])->filter(fn ($entry) => is_string($entry))->values();
        $preflightWarnings = collect($deploymentPreflight['warnings'] ?? [])->filter(fn ($entry) => is_string($entry))->values();
        $preflightActionableChecks = PreflightIssueFixResolver::actionableChecks($site, $server, $preflightChecks);
        $dockerRuntimeDetails = $site->usesDockerRuntime() && is_array($dockerRuntime['runtime_details'] ?? null) ? $dockerRuntime['runtime_details'] : [];
        $dockerContainers = collect($dockerRuntimeDetails['containers'] ?? [])->filter(fn ($entry) => is_array($entry))->values();
        $runtimeLogs = collect($runtimeTarget['logs'] ?? [])->filter(fn ($entry) => is_array($entry))->reverse()->values();
        $runtimeOperationConsoles = SiteShowViewData::runtimeOperationConsoles($runtimeLogs);
        $runtimeErrorConsole = $runtimeOperationConsoles->first(fn (array $console): bool => in_array($console['action'], ['errors'], true) || $console['status'] === 'failed');
        $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
        $resourceNounLower = strtolower($resourceNoun);
        $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
        $isEdgeWorkspace = $site->usesEdgeRuntime();
        $workspacePrefix = match (true) {
            $isEdgeWorkspace => __('Edge'),
            str_contains($runtimeFamily, 'cloud') || str_contains($runtimePlatform, 'cloud') => __('Cloud'),
            in_array($runtimePlatform, ['aws', 'digitalocean'], true) => __('Cloud'),
            $runtimeMode === 'docker' => __('Container'),
            $runtimeMode === 'kubernetes' => __('Kubernetes'),
            $runtimeMode === 'serverless' => __('Function'),
            default => null,
        };
        $workspaceTitle = $workspacePrefix ? $workspacePrefix.' '.$resourceNounLower.' '.__('workspace') : $resourceNoun.' '.__('workspace');
        if ($isEdgeWorkspace) {
            $workspaceTitle = __('Edge site workspace');
        }
        $sectionHeader = SiteSettingsHeader::for($site, $server, $section);
        $headerUser = $user;
        $headerOrg = $headerUser?->currentOrganization();
        $headerCanUpdateSite = (bool) $headerUser?->can('update', $site);
        $headerCanDeleteSite = (bool) $headerUser?->can('delete', $site);
        $headerIsDeployer = (bool) $headerOrg?->userIsDeployer($headerUser);
        $headerIsAdmin = (bool) $headerOrg?->hasAdminAccess($headerUser);
        $headerRoleLabel = match (true) {
            $headerIsAdmin => null,
            $headerIsDeployer => __('Deployer'),
            $headerCanUpdateSite => __('Editor'),
            default => __('Read-only'),
        };
        $headerRoleTone = match (true) {
            $headerIsDeployer => 'bg-amber-100 text-amber-900 ring-amber-200/60',
            $headerCanUpdateSite => 'bg-emerald-100 text-emerald-900 ring-emerald-200/60',
            default => 'bg-slate-100 text-slate-700 ring-slate-200/60',
        };
        $sectionDescription = $headerCanUpdateSite
            ? $sectionHeader['description']
            : ($headerIsDeployer
                ? __('Review this section — settings are read-only for the Deployer role. Use Deploy actions to ship changes.')
                : __('You have read-only access to this section — settings cannot be changed from this account.'));
        $generalOverviewTitle = $runtimeMode === 'vm' ? __('Site domain') : __('Primary hostname');
        $generalOverviewDescription = $runtimeMode === 'vm'
            ? __('Update the primary domain and web directory for this site here. Changing the primary hostname updates the site record Dply uses for routing and future server automation.')
            : __('Update the primary hostname and working directory for this app here. Changing the primary hostname updates the routing and publication details Dply uses for future automation.');
        $workspaceDescription = $runtimeMode === 'docker'
            ? __('Operate this container app from one workspace tuned for runtime operations, environment, deployments, and networking.')
            : null;
        $projectSettingsTitle = $resourceNoun.' '.__('project settings');
        $projectSettingsDescription = __('Choose which project this :resource belongs to for grouped resources, shared variables, operations, and coordinated delivery.', ['resource' => strtolower($resourceNoun)]);
        $detailsTitle = $resourceNoun.' '.__('details');
        $detailsDescription = __('Use this reference block for the stable :resource metadata operators usually need when checking ownership, age, and basic inventory.', ['resource' => strtolower($resourceNoun)]);
        $primaryHostnameLabel = $runtimeMode === 'vm' ? __('Root domain') : __('Primary hostname');
        $documentRootLabel = $runtimeMode === 'vm' ? __('Web directory') : __('Published path');
        $documentRootPlaceholder = '/var/www/app/public';
        $summaryCards = $isContainerWorkspace
            ? [
                ['label' => __('Runtime status'), 'value' => $site->statusLabel()],
                ['label' => __('Published URL'), 'value' => $runtimePublication['url'] ?? $runtimePublication['hostname'] ?? __('Not published yet')],
                ['label' => __('Container service'), 'value' => $runtimePublication['docker_service'] ?? __('Not recorded yet')],
                ['label' => __('Working directory'), 'value' => $site->effectiveRepositoryPath()],
            ]
            : [
                ['label' => __('Provisioning'), 'value' => $site->statusLabel()],
                ['label' => __('SSL'), 'value' => $site->currentSslSummary()],
                ['label' => __('Deploy path'), 'value' => $site->effectiveRepositoryPath()],
                ['label' => __('Zero downtime'), 'value' => $site->deploy_strategy === 'atomic' ? __('Enabled') : __('Disabled')],
            ];
        $settingsBreadcrumbs = self::breadcrumbs($server, $site, $section, $sectionHeader);
        $edgeAnalytics = self::edgeAnalyticsForSection($site, $section);
        $edgeContext = $isEdgeWorkspace ? EdgeSiteViewData::context($site, $section) : [];
        $sectionConsoleActionKinds = (array) (config('console_actions.section_kinds.'.$section, []));
        $sectionConsoleActionRun = self::consoleActionRun($site, $sectionConsoleActionKinds);
        $generalRecentDeployments = $section === 'general'
            ? self::recentDeploymentsWithPhaseResults($site)
            : collect();
        $contextualDocSlug = app(ContextualDocResolver::class)->resolveForSiteSection($site, $section);

        return array_merge(
            compact(
                'functionsHost',
                'supportsMachinePhp',
                'supportsWebserverProvisioning',
                'showWebserverConfigEditor',
                'supportsHttp3Certificates',
                'supportsEnvPush',
                'supportsSshDeployHooks',
                'testingHostname',
                'deployVariableReference',
                'deployHookPhaseLabels',
                'runtimeMode',
                'runtimePlatform',
                'runtimeFamily',
                'isContainerWorkspace',
                'settingsSidebarItems',
                'routingTabIcons',
                'routingTabLabels',
                'runtimeTabs',
                'runtimeTabIcons',
                'previewDomain',
                'activeCertificate',
                'pendingCertificate',
                'latestCertificate',
                'serverlessRuntime',
                'dockerRuntime',
                'kubernetesRuntime',
                'runtimeTarget',
                'runtimePublication',
                'foundationStatus',
                'foundationSecrets',
                'secretConfigEntries',
                'secretEntries',
                'configEntries',
                'secretDeliveryLabel',
                'resourceBindings',
                'preflightChecks',
                'preflightErrors',
                'preflightWarnings',
                'preflightActionableChecks',
                'dockerRuntimeDetails',
                'dockerContainers',
                'runtimeLogs',
                'runtimeOperationConsoles',
                'runtimeErrorConsole',
                'resourceNoun',
                'resourceNounLower',
                'resourcePlural',
                'workspacePrefix',
                'workspaceTitle',
                'sectionHeader',
                'headerUser',
                'headerOrg',
                'headerCanUpdateSite',
                'headerCanDeleteSite',
                'headerIsDeployer',
                'headerIsAdmin',
                'headerRoleLabel',
                'headerRoleTone',
                'sectionDescription',
                'generalOverviewTitle',
                'generalOverviewDescription',
                'workspaceDescription',
                'projectSettingsTitle',
                'projectSettingsDescription',
                'detailsTitle',
                'detailsDescription',
                'primaryHostnameLabel',
                'documentRootLabel',
                'documentRootPlaceholder',
                'summaryCards',
                'settingsBreadcrumbs',
                'sectionConsoleActionKinds',
                'sectionConsoleActionRun',
                'generalRecentDeployments',
                'isEdgeWorkspace',
                'contextualDocSlug',
            ),
            $edgeAnalytics,
            $edgeContext,
        );
    }

    /**
     * Edge workspaces share the settings shell but skip BYO VM/container view-model work.
     *
     * @return array<string, mixed>
     */
    private static function forEdgeWorkspace(
        Server $server,
        Site $site,
        string $section,
        ?User $user = null,
    ): array {
        $runtimeTarget = $site->runtimeTarget();
        $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
        $resourceNoun = __('App');
        $resourceNounLower = strtolower($resourceNoun);
        $resourcePlural = __('apps');
        $workspaceTitle = __('Edge site workspace');
        $settingsSidebarItems = SiteSettingsSidebar::items($site, $server);
        $sectionHeader = SiteSettingsHeader::for($site, $server, $section);
        $header = self::headerContext($site, $sectionHeader, $section, $user);
        $settingsBreadcrumbs = self::breadcrumbs($server, $site, $section, $sectionHeader);
        $edgeAnalytics = self::edgeAnalyticsForSection($site, $section);
        $edgeContext = EdgeSiteViewData::context($site, $section);
        $sectionConsoleActionKinds = (array) (config('console_actions.section_kinds.'.$section, []));
        $sectionConsoleActionRun = self::consoleActionRun($site, $sectionConsoleActionKinds);
        $contextualDocSlug = app(ContextualDocResolver::class)->resolveForSiteSection($site, $section);

        return array_merge(
            compact(
                'runtimePublication',
                'resourceNoun',
                'resourceNounLower',
                'resourcePlural',
                'workspaceTitle',
                'settingsSidebarItems',
                'sectionHeader',
                'settingsBreadcrumbs',
                'sectionConsoleActionKinds',
                'sectionConsoleActionRun',
                'contextualDocSlug',
            ),
            $header,
            [
                'isEdgeWorkspace' => true,
                'generalRecentDeployments' => collect(),
            ],
            $edgeAnalytics,
            $edgeContext,
        );
    }

    /**
     * @param  array{title: string, description: string, icon: string}  $sectionHeader
     * @return array<string, mixed>
     */
    private static function headerContext(
        Site $site,
        array $sectionHeader,
        string $section,
        ?User $user,
    ): array {
        $headerUser = $user;
        $headerOrg = $headerUser?->currentOrganization();
        $headerCanUpdateSite = (bool) $headerUser?->can('update', $site);
        $headerCanDeleteSite = (bool) $headerUser?->can('delete', $site);
        $headerIsDeployer = (bool) $headerOrg?->userIsDeployer($headerUser);
        $headerIsAdmin = (bool) $headerOrg?->hasAdminAccess($headerUser);
        $headerRoleLabel = match (true) {
            $headerIsAdmin => null,
            $headerIsDeployer => __('Deployer'),
            $headerCanUpdateSite => __('Editor'),
            default => __('Read-only'),
        };
        $headerRoleTone = match (true) {
            $headerIsDeployer => 'bg-amber-100 text-amber-900 ring-amber-200/60',
            $headerCanUpdateSite => 'bg-emerald-100 text-emerald-900 ring-emerald-200/60',
            default => 'bg-slate-100 text-slate-700 ring-slate-200/60',
        };
        $sectionDescription = $headerCanUpdateSite
            ? $sectionHeader['description']
            : ($headerIsDeployer
                ? __('Review this section — settings are read-only for the Deployer role. Use Deploy actions to ship changes.')
                : __('You have read-only access to this section — settings cannot be changed from this account.'));

        return compact(
            'headerUser',
            'headerOrg',
            'headerCanUpdateSite',
            'headerCanDeleteSite',
            'headerIsDeployer',
            'headerIsAdmin',
            'headerRoleLabel',
            'headerRoleTone',
            'sectionDescription',
        );
    }

    /**
     * Billing + traffic cards for the overview observability child (lazy wire:init).
     *
     * @return array{
     *     edgeUsageBillingEnabled: bool,
     *     edgeManagedFee: float|null,
     *     edgeUsageRates: array<string, mixed>,
     *     edgeSiteBilling: array<string, mixed>|null,
     *     edgeSiteTraffic: array<string, mixed>|null,
     * }
     */
    public static function edgeOverviewObservability(Site $site): array
    {
        $edgeUsageBillingEnabled = (bool) config('dply.edge.usage_billing.enabled', false);
        $edgeManagedFee = ((int) config('subscription.standard.edge_cents', 0)) / 100;
        $edgeUsageRates = app(ManagedProductCostEstimator::class)->edgeUsageRates();
        $edgeSiteBilling = app(EdgeSiteBillingAnalytics::class)->forSite($site);
        $edgeSiteTraffic = app(EdgeSiteTrafficAnalytics::class)->forSite($site, billing: $edgeSiteBilling);

        return [
            'edgeUsageBillingEnabled' => $edgeUsageBillingEnabled,
            'edgeManagedFee' => $edgeManagedFee,
            'edgeUsageRates' => $edgeUsageRates,
            'edgeSiteBilling' => $edgeSiteBilling,
            'edgeSiteTraffic' => $edgeSiteTraffic,
        ];
    }

    /**
     * Edge billing/traffic/access snapshots are section-scoped — avoid running
     * usage queries on every workspace tab (Deploys, Build, Domains, etc.).
     *
     * @return array{
     *     edgeUsageBillingEnabled: bool,
     *     edgeManagedFee: float|null,
     *     edgeUsageRates: array<string, mixed>,
     *     edgeSiteBilling: array<string, mixed>|null,
     *     edgeSiteTraffic: array<string, mixed>|null,
     *     edgeSiteAccess: array<string, mixed>|null,
     * }
     */
    private static function edgeAnalyticsForSection(Site $site, string $section): array
    {
        $empty = [
            'edgeUsageBillingEnabled' => false,
            'edgeManagedFee' => null,
            'edgeUsageRates' => [],
            'edgeSiteBilling' => null,
            'edgeSiteTraffic' => null,
            'edgeSiteAccess' => null,
        ];

        if (! $site->usesEdgeRuntime()) {
            return $empty;
        }

        $edgeUsageBillingEnabled = (bool) config('dply.edge.usage_billing.enabled', false);
        $edgeManagedFee = ((int) config('subscription.standard.edge_cents', 0)) / 100;

        $needsBillingSnapshot = $section === 'edge-billing';
        $needsTrafficSnapshot = in_array($section, ['edge-traffic'], true);
        $needsAccessSnapshot = $section === 'edge-traffic';

        if (! $needsBillingSnapshot && ! $needsTrafficSnapshot && ! $needsAccessSnapshot) {
            return [
                'edgeUsageBillingEnabled' => $edgeUsageBillingEnabled,
                'edgeManagedFee' => $edgeManagedFee,
                'edgeUsageRates' => [],
                'edgeSiteBilling' => null,
                'edgeSiteTraffic' => null,
                'edgeSiteAccess' => null,
            ];
        }

        $edgeUsageRates = ($needsBillingSnapshot || $needsTrafficSnapshot)
            ? app(ManagedProductCostEstimator::class)->edgeUsageRates()
            : [];

        $edgeSiteBilling = ($needsBillingSnapshot || $needsTrafficSnapshot)
            ? app(EdgeSiteBillingAnalytics::class)->forSite($site)
            : null;

        $edgeSiteTraffic = $needsTrafficSnapshot
            ? app(EdgeSiteTrafficAnalytics::class)->forSite($site, billing: $edgeSiteBilling)
            : null;

        $edgeSiteAccess = $needsAccessSnapshot
            ? app(EdgeSiteAccessAnalytics::class)->forSite($site)
            : null;

        return [
            'edgeUsageBillingEnabled' => $edgeUsageBillingEnabled,
            'edgeManagedFee' => $edgeManagedFee,
            'edgeUsageRates' => $edgeUsageRates,
            'edgeSiteBilling' => $edgeSiteBilling,
            'edgeSiteTraffic' => $edgeSiteTraffic,
            'edgeSiteAccess' => $edgeSiteAccess,
        ];
    }

    /**
     * @param  array{title: string, description: string, icon: string}  $sectionHeader
     * @return list<array{label: string, href?: string|null, icon: string}>
     */
    private static function breadcrumbs(Server $server, Site $site, string $section, array $sectionHeader): array
    {
        if ($site->usesEdgeRuntime()) {
            $items = [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
                ['label' => __('Edge'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
            ];

            $items[] = [
                'label' => $site->name,
                'href' => $section === 'general' ? null : route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']),
                'icon' => 'globe-alt',
                'avatar' => $site->name ?: (string) $site->id,
                'avatar_image' => $site->logoUrl(),
            ];

            if ($section !== 'general') {
                $items[] = [
                    'label' => $sectionHeader['title'],
                    'icon' => SiteWorkspaceBreadcrumbs::iconKeyFromSection($section, $site, $server),
                ];
            }

            return $items;
        }

        $items = [
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Servers'), 'href' => route('servers.index'), 'icon' => 'server-stack'],
        ];

        if ($server->workspace) {
            $items[] = [
                'label' => $server->workspace->name,
                'href' => route('projects.resources', $server->workspace),
                'icon' => 'rectangle-group',
            ];
        }

        $items[] = [
            'label' => $server->name,
            'href' => route('servers.overview', $server),
            'icon' => 'server-stack',
            'avatar' => $server->name ?: (string) $server->id,
            'avatar_image' => $server->logoUrl(),
        ];
        $items[] = [
            'label' => __('Sites'),
            'href' => route('servers.sites', $server),
            'icon' => 'rectangle-stack',
        ];
        $items[] = [
            'label' => $site->name,
            'href' => $section === 'general' ? null : route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']),
            'icon' => 'globe-alt',
            'avatar' => $site->name ?: (string) $site->id,
            'avatar_image' => $site->logoUrl(),
        ];

        if ($section !== 'general') {
            $items[] = [
                'label' => $sectionHeader['title'],
                'icon' => SiteWorkspaceBreadcrumbs::iconKeyFromSection($section, $site, $server),
            ];
        }

        return $items;
    }

    /**
     * @param  list<string>  $kinds
     */
    private static function consoleActionRun(Site $site, array $kinds): ?ConsoleAction
    {
        if ($kinds === []) {
            return null;
        }

        return ConsoleAction::query()
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->id)
            ->whereIn('kind', $kinds)
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @return Collection<int, SiteDeployment>
     */
    private static function recentDeploymentsWithPhaseResults(Site $site): Collection
    {
        if (! $site->relationLoaded('deployments')) {
            return collect();
        }

        return $site->deployments
            ->filter(fn (SiteDeployment $deployment): bool => is_array($deployment->phase_results) && $deployment->phase_results !== [])
            ->take(10)
            ->values();
    }
}
