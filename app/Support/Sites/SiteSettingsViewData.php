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
use App\Support\Deployment\DeploymentContract;
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
            'aliases' => 'heroicon-o-link',
            'redirects' => 'heroicon-o-arrow-uturn-right',
            'preview' => 'heroicon-o-sparkles',
            'tenants' => 'heroicon-o-building-office-2',
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
        $dockerRuntimeDetails = $site->usesDockerRuntime() && is_array($dockerRuntime['runtime_details'] ?? null) ? $dockerRuntime['runtime_details'] : [];
        $dockerContainers = collect($dockerRuntimeDetails['containers'] ?? [])->filter(fn ($entry) => is_array($entry))->values();
        $runtimeLogs = collect($runtimeTarget['logs'] ?? [])->filter(fn ($entry) => is_array($entry))->reverse()->values();
        $runtimeOperationConsoles = SiteShowViewData::runtimeOperationConsoles($runtimeLogs);
        $runtimeErrorConsole = $runtimeOperationConsoles->first(fn (array $console): bool => in_array($console['action'], ['errors'], true) || $console['status'] === 'failed');
        $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
        $resourceNounLower = strtolower($resourceNoun);
        $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
        $workspacePrefix = match (true) {
            str_contains($runtimeFamily, 'cloud') || str_contains($runtimePlatform, 'cloud') => __('Cloud'),
            in_array($runtimePlatform, ['aws', 'digitalocean'], true) => __('Cloud'),
            $runtimeMode === 'docker' => __('Container'),
            $runtimeMode === 'kubernetes' => __('Kubernetes'),
            $runtimeMode === 'serverless' => __('Function'),
            default => null,
        };
        $workspaceTitle = $workspacePrefix ? $workspacePrefix.' '.$resourceNounLower.' '.__('workspace') : $resourceNoun.' '.__('workspace');
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
        $sectionConsoleActionKinds = (array) (config('console_actions.section_kinds.'.$section, []));
        $sectionConsoleActionRun = self::consoleActionRun($site, $sectionConsoleActionKinds);
        $generalRecentDeployments = $section === 'general'
            ? self::recentDeploymentsWithPhaseResults($site)
            : collect();

        return compact(
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
        );
    }

    /**
     * @param  array{title: string, description: string, icon: string}  $sectionHeader
     * @return list<array{label: string, href?: string|null, icon: string}>
     */
    private static function breadcrumbs(Server $server, Site $site, string $section, array $sectionHeader): array
    {
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
        ];
        $items[] = [
            'label' => $site->name,
            'href' => $section === 'general' ? null : route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']),
            'icon' => 'globe-alt',
        ];

        if ($section !== 'general') {
            $items[] = ['label' => $sectionHeader['title'], 'icon' => 'cog-6-tooth'];
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
