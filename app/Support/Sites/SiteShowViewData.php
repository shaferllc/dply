<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Livewire\Sites\Show;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Support\Deployment\DeploymentContract;
use Illuminate\Support\Collection;

/**
 * View-model for {@see resources/views/livewire/sites/show.blade.php}. Keeps
 * catalog/setup out of the site show blade tree.
 */
final class SiteShowViewData
{
    /**
     * @param  array<string, mixed>  $deploymentPreflight
     * @return array<string, mixed>
     */
    public static function for(
        Server $server,
        Site $site,
        Show $component,
        ?DeploymentContract $deploymentContract,
        array $deploymentPreflight,
        string $activeTab,
    ): array {
        $functionsHost = $server->hostCapabilities()->supportsFunctionDeploy();
        $supportsMachinePhp = $server->hostCapabilities()->supportsMachinePhpManagement();
        $supportsWebserverProvisioning = $server->hostCapabilities()->supportsWebserverProvisioning();
        $showWebserverConfigEditor = $server->hostCapabilities()->supportsSsh()
            && ! $site->usesFunctionsRuntime()
            && ! $site->usesDockerRuntime()
            && ! $site->usesKubernetesRuntime();
        $showVmCronDaemonsLinks = $showWebserverConfigEditor;
        $supportsEnvPush = $server->hostCapabilities()->supportsEnvPushToHost();
        $supportsReleaseRollback = $server->hostCapabilities()->supportsReleaseRollback();
        $supportsSshDeployHooks = $server->hostCapabilities()->supportsSshDeployHooks();
        $testingHostname = $site->testingHostname();
        $testingHostnameMeta = is_array($site->meta['testing_hostname'] ?? null) ? $site->meta['testing_hostname'] : [];
        $provisioningMeta = $site->provisioningMeta();
        $provisioningState = $site->provisioningState() ?? 'queued';
        $provisioningError = $site->provisioningError();
        $provisioningLog = collect($site->provisioningLog())->reverse()->values();
        $provisioningTranscript = $provisioningLog->take(8)->map(function (array $entry): string {
            $timestamp = (string) ($entry['at'] ?? '');
            $level = strtoupper((string) ($entry['level'] ?? 'info'));
            $message = (string) ($entry['message'] ?? 'Provisioning update');
            $lines = [];

            $prefixParts = array_values(array_filter([$timestamp, $level]));
            $lines[] = ($prefixParts !== [] ? '['.implode('] [', $prefixParts).'] ' : '').$message;

            foreach (collect($entry['context'] ?? [])->filter(fn ($value) => ! is_array($value)) as $contextKey => $contextValue) {
                $rendered = is_bool($contextValue) ? ($contextValue ? 'true' : 'false') : (string) $contextValue;
                if ($rendered === '') {
                    continue;
                }

                $lines[] = '  > '.str_replace('_', ' ', (string) $contextKey).': '.$rendered;
            }

            return implode("\n", $lines);
        })->implode("\n\n");
        $targetUrl = $testingHostname ? 'http://'.$testingHostname : ($site->visitUrl() ?? null);
        $readyForWorkspace = $site->isReadyForWorkspace();
        $hostChecks = collect($provisioningMeta['host_checks'] ?? [])
            ->filter(fn ($check) => is_array($check) && is_string($check['hostname'] ?? null))
            ->values();
        $serverlessRuntime = $site->usesFunctionsRuntime() ? $site->serverlessConfig() : [];
        $dockerRuntime = $site->usesDockerRuntime() && is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [];
        $kubernetesRuntime = $site->usesKubernetesRuntime() && is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
        $runtimeTarget = $site->runtimeTarget();
        $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
        $dockerRuntimeDetails = $site->usesDockerRuntime() && is_array($dockerRuntime['runtime_details'] ?? null) ? $dockerRuntime['runtime_details'] : [];
        $dockerContainers = collect($dockerRuntimeDetails['containers'] ?? [])->filter(fn ($entry) => is_array($entry))->values();
        $runtimeLogs = collect($runtimeTarget['logs'] ?? [])->filter(fn ($entry) => is_array($entry))->reverse()->values();

        $foundationStatus = is_array($deploymentContract?->status ?? null) ? $deploymentContract->status : [];
        $resourceBindings = collect($deploymentContract?->resourceBindingArrays() ?? [])->filter(fn ($entry) => is_array($entry))->values();
        $preflightChecks = collect($deploymentPreflight['checks'] ?? [])->filter(fn ($entry) => is_array($entry))->values();
        $preflightErrors = collect($deploymentPreflight['errors'] ?? [])->filter(fn ($entry) => is_string($entry))->values();
        $preflightWarnings = collect($deploymentPreflight['warnings'] ?? [])->filter(fn ($entry) => is_string($entry))->values();
        $preflightActionableChecks = PreflightIssueFixResolver::actionableChecks($site, $server, $preflightChecks);

        $runtimeOperationConsoles = self::runtimeOperationConsoles($runtimeLogs);
        $runtimeErrorConsole = $runtimeOperationConsoles->first(fn (array $console): bool => in_array($console['action'], ['errors'], true) || $console['status'] === 'failed');
        $previewDomain = $site->primaryPreviewDomain();
        $activeCertificate = $site->certificates->firstWhere('status', SiteCertificate::STATUS_ACTIVE);
        $pendingCertificate = $activeCertificate
            ? null
            : $site->certificates->first(fn ($certificate) => in_array($certificate->status, [
                SiteCertificate::STATUS_PENDING,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_INSTALLING,
            ], true));
        $latestCertificate = $activeCertificate ?? $pendingCertificate ?? $site->certificates->first();
        // Only serverless / container runtimes enter "awaiting_first_deploy" —
        // for them the first deploy is what publishes a live endpoint. A VM site
        // provisions to a live splash page (reachability → ready) and deploys
        // SEPARATELY once a repo is connected, so showing a "Waiting for first
        // deploy" step in its provisioning journey would imply provisioning waits
        // for a deploy it never triggers. Drop it for VM hosts.
        $entersFirstDeployState = $site->usesFunctionsRuntime()
            || $site->usesDockerRuntime()
            || $site->usesKubernetesRuntime();

        $statusSteps = [
            'queued' => __('Queued'),
            'preparing_runtime_artifacts' => __('Preparing runtime artifacts'),
            'configuring_publication' => __('Preparing publication target'),
            'provisioning_testing_hostname' => __('Assigning testing hostname'),
            'writing_site_config' => __('Writing site config'),
            'waiting_for_http' => __('Checking reachability'),
        ];
        if ($entersFirstDeployState) {
            $statusSteps['awaiting_first_deploy'] = __('Waiting for first deploy');
        }
        $statusSteps['ready'] = __('Site available');
        $statusSteps['failed'] = __('Needs attention');
        $stepKeys = array_keys($statusSteps);
        $currentStepIndex = array_search($provisioningState, $stepKeys, true);
        $currentStepIndex = $currentStepIndex === false ? 0 : $currentStepIndex;

        $deploymentConsoles = $site->relationLoaded('deployments')
            ? self::deploymentConsoles($site->deployments)
            : collect();

        $sidebarItems = [
            ['id' => 'general', 'label' => __('General'), 'icon' => 'heroicon-o-rectangle-stack'],
            ['id' => 'settings', 'label' => __('Site settings'), 'icon' => 'heroicon-o-cog-6-tooth', 'href' => route('sites.settings', ['server' => $server, 'site' => $site, 'section' => 'general'])],
            ['id' => 'deployment-log', 'label' => __('Deployments'), 'icon' => 'heroicon-o-code-bracket'],
            ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list'],
        ];
        if ($site->visitUrl()) {
            $sidebarItems[] = [
                'id' => 'view',
                'label' => __('View'),
                'icon' => 'heroicon-o-arrow-top-right-on-square',
                'href' => $site->visitUrl(),
                'external' => true,
            ];
        }

        $siteHeaderBreadcrumbs = [
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ];

        if ($site->usesEdgeRuntime()) {
            $siteHeaderBreadcrumbs[] = ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'];
            $siteHeaderBreadcrumbs[] = ['label' => __('Edge'), 'href' => route('edge.index'), 'icon' => 'globe-alt'];
            $siteHeaderBreadcrumbs[] = ['label' => $site->name, 'icon' => 'globe-alt'];
        } else {
            $siteHeaderBreadcrumbs[] = ['label' => __('Servers'), 'href' => route('servers.index'), 'icon' => 'server-stack'];
            if ($server->workspace) {
                $siteHeaderBreadcrumbs[] = [
                    'label' => $server->workspace->name,
                    'href' => route('projects.resources', $server->workspace),
                    'icon' => 'rectangle-group',
                ];
            }
            $siteHeaderBreadcrumbs[] = [
                'label' => $server->name,
                'href' => route('servers.overview', $server),
                'icon' => 'server-stack',
            ];
            $siteHeaderBreadcrumbs[] = [
                'label' => $site->name,
                'icon' => 'globe-alt',
            ];
        }

        $provisioningJourney = $site->usesEdgeRuntime() && ! $readyForWorkspace
            ? self::edgeProvisioningJourney($site)
            : self::provisioningJourney(
                $provisioningState,
                $statusSteps,
                $stepKeys,
                $currentStepIndex,
            );

        $dashboard = $readyForWorkspace
            ? self::dashboard(
                $site,
                $server,
                $component,
                $activeTab,
                $foundationStatus,
                $preflightErrors,
                $preflightWarnings,
                $hostChecks,
                $supportsReleaseRollback,
                $previewDomain,
            )
            : self::dashboardUnavailableDefaults();

        return array_merge(
            compact(
                'functionsHost',
                'supportsMachinePhp',
                'supportsWebserverProvisioning',
                'showWebserverConfigEditor',
                'showVmCronDaemonsLinks',
                'supportsEnvPush',
                'supportsReleaseRollback',
                'supportsSshDeployHooks',
                'testingHostname',
                'testingHostnameMeta',
                'provisioningMeta',
                'provisioningState',
                'provisioningError',
                'provisioningLog',
                'provisioningTranscript',
                'targetUrl',
                'readyForWorkspace',
                'hostChecks',
                'serverlessRuntime',
                'dockerRuntime',
                'kubernetesRuntime',
                'runtimeTarget',
                'runtimePublication',
                'dockerRuntimeDetails',
                'dockerContainers',
                'runtimeLogs',
                'foundationStatus',
                'resourceBindings',
                'preflightChecks',
                'preflightErrors',
                'preflightWarnings',
                'preflightActionableChecks',
                'runtimeOperationConsoles',
                'runtimeErrorConsole',
                'previewDomain',
                'activeCertificate',
                'pendingCertificate',
                'latestCertificate',
                'statusSteps',
                'stepKeys',
                'currentStepIndex',
                'deploymentConsoles',
                'sidebarItems',
                'siteHeaderBreadcrumbs',
            ),
            $provisioningJourney,
            $dashboard,
            $site->usesEdgeRuntime() ? EdgeSiteViewData::context($site) : [],
            ['activeTab' => $activeTab],
        );
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Public so the ad-hoc previews tab can render its own inline progress
     * card off a pending preview Site without going through the full
     * site-show view-data pipeline.
     *
     * @return array<string, mixed>
     */
    public static function edgeProvisioningJourney(Site $site): array
    {
        $edgeMeta = $site->edgeMeta();
        $sourceSpec = is_array($edgeMeta['source'] ?? null) ? $edgeMeta['source'] : [];
        $buildSpec = is_array($edgeMeta['build'] ?? null) ? $edgeMeta['build'] : [];
        $edgeBuildCommand = (string) ($buildSpec['command'] ?? 'npm ci && npm run build');
        $edgeOutputDir = (string) ($buildSpec['output_dir'] ?? 'dist');
        $edgeRepoLabel = (($sourceSpec['repo'] ?? '?').'@'.($sourceSpec['branch'] ?? 'main'));
        $edgeLiveUrl = $site->edgeLiveUrl();

        $edgeLatestDeployment = $site->relationLoaded('edgeDeployments')
            ? $site->edgeDeployments->first()
            : $site->edgeDeployments()->first();

        $edgeProvisioningState = self::resolveEdgeProvisioningState($site, $edgeLatestDeployment);
        $edgeProvisioningError = self::resolveEdgeProvisioningError($site, $edgeLatestDeployment);

        $edgeStatusSteps = [
            'queued' => __('Queued / cloning repository'),
            'building' => __('Installing dependencies & building'),
            'publishing' => __('Publishing to Edge CDN'),
            'live' => __('Live'),
            'failed' => __('Needs attention'),
        ];
        $edgeStepKeys = array_keys($edgeStatusSteps);
        $edgeCurrentStepIndex = array_search($edgeProvisioningState, $edgeStepKeys, true);
        $edgeCurrentStepIndex = $edgeCurrentStepIndex === false ? 0 : $edgeCurrentStepIndex;

        $edgeJourneyHasFailed = $edgeProvisioningState === 'failed';
        $edgeJourneyIsDone = $edgeProvisioningState === 'live';
        $edgeVisibleSteps = collect($edgeStatusSteps)->except('failed');
        $edgeTotalSteps = $edgeVisibleSteps->count();
        // Count the IN-FLIGHT step as the current step number — operators
        // read "(3/4)" as "we're on step 3", not "3 done and not started 4".
        // Done = totalSteps; failed = the step that died (currentStepIndex
        // stays at the live-time index since state flips to 'failed' only
        // after the failing step is set).
        $edgeCompletedSteps = $edgeJourneyHasFailed
            ? max(1, min($edgeTotalSteps, $edgeCurrentStepIndex))
            : ($edgeJourneyIsDone ? $edgeTotalSteps : max(1, min($edgeTotalSteps, $edgeCurrentStepIndex + 1)));
        $edgeProgressPercent = $edgeTotalSteps > 0
            ? (int) round(($edgeCompletedSteps / $edgeTotalSteps) * 100)
            : 0;
        $edgeCurrentLabel = $edgeStatusSteps[$edgeProvisioningState]
            ?? str_replace('_', ' ', $edgeProvisioningState);

        return compact(
            'edgeMeta',
            'sourceSpec',
            'buildSpec',
            'edgeBuildCommand',
            'edgeOutputDir',
            'edgeRepoLabel',
            'edgeLiveUrl',
            'edgeLatestDeployment',
            'edgeProvisioningState',
            'edgeProvisioningError',
            'edgeStatusSteps',
            'edgeStepKeys',
            'edgeCurrentStepIndex',
            'edgeJourneyHasFailed',
            'edgeJourneyIsDone',
            'edgeVisibleSteps',
            'edgeTotalSteps',
            'edgeCompletedSteps',
            'edgeProgressPercent',
            'edgeCurrentLabel',
        );
    }

    /**
     * Journey shape scoped to a single deployment (build → publish → live),
     * without leaning on the parent Site's status. Used by the deployment
     * detail page so the progress card reflects THIS build even when the
     * site as a whole is already live on a different deployment.
     *
     * @return array<string, mixed>
     */
    public static function edgeDeploymentJourney(EdgeDeployment $deployment): array
    {
        $state = match ($deployment->status) {
            EdgeDeployment::STATUS_BUILDING => 'building',
            EdgeDeployment::STATUS_PUBLISHING => 'publishing',
            EdgeDeployment::STATUS_LIVE, EdgeDeployment::STATUS_SUPERSEDED => 'live',
            EdgeDeployment::STATUS_FAILED => 'failed',
            default => 'queued',
        };

        $statusSteps = [
            'queued' => __('Queued / cloning repository'),
            'building' => __('Installing dependencies & building'),
            'publishing' => __('Publishing to Edge CDN'),
            'live' => __('Live'),
            'failed' => __('Needs attention'),
        ];
        $stepKeys = array_keys($statusSteps);
        $currentStepIndex = array_search($state, $stepKeys, true);
        $currentStepIndex = $currentStepIndex === false ? 0 : $currentStepIndex;

        $hasFailed = $state === 'failed';
        $isDone = $state === 'live';
        $visibleSteps = collect($statusSteps)->except('failed');
        $totalSteps = $visibleSteps->count();
        // "(X/N)" reads as "currently on step X" — count the in-flight step
        // as the current number, not the count of finished ones.
        $completedSteps = $hasFailed
            ? max(1, min($totalSteps, $currentStepIndex))
            : ($isDone ? $totalSteps : max(1, min($totalSteps, $currentStepIndex + 1)));
        $progressPercent = $totalSteps > 0
            ? (int) round(($completedSteps / $totalSteps) * 100)
            : 0;
        $currentLabel = $statusSteps[$state] ?? str_replace('_', ' ', $state);

        return [
            'state' => $state,
            'statusSteps' => $statusSteps,
            'stepKeys' => $stepKeys,
            'visibleSteps' => $visibleSteps,
            'currentStepIndex' => $currentStepIndex,
            'totalSteps' => $totalSteps,
            'completedSteps' => $completedSteps,
            'progressPercent' => $progressPercent,
            'currentLabel' => $currentLabel,
            'hasFailed' => $hasFailed,
            'isDone' => $isDone,
            'error' => is_string($deployment->failure_reason) && $deployment->failure_reason !== ''
                ? $deployment->failure_reason
                : null,
        ];
    }

    private static function resolveEdgeProvisioningState(Site $site, ?EdgeDeployment $deployment): string
    {
        if ($site->status === Site::STATUS_EDGE_FAILED
            || ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_FAILED)) {
            return 'failed';
        }

        if ($site->status === Site::STATUS_EDGE_ACTIVE
            || ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_LIVE)) {
            return 'live';
        }

        if ($deployment === null) {
            return 'queued';
        }

        return match ($deployment->status) {
            EdgeDeployment::STATUS_PUBLISHING => 'publishing',
            EdgeDeployment::STATUS_BUILDING => 'building',
            default => 'queued',
        };
    }

    private static function resolveEdgeProvisioningError(Site $site, ?EdgeDeployment $deployment): ?string
    {
        $metaError = $site->edgeMeta()['last_error'] ?? null;
        if (is_string($metaError) && $metaError !== '') {
            return $metaError;
        }

        $deploymentError = $deployment?->failure_reason;
        if (is_string($deploymentError) && $deploymentError !== '') {
            return $deploymentError;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $statusSteps
     * @param  list<string>  $stepKeys
     * @return array<string, mixed>
     */
    private static function provisioningJourney(
        string $provisioningState,
        array $statusSteps,
        array $stepKeys,
        int $currentStepIndex,
    ): array {
        $siteJourneyHasFailed = $provisioningState === 'failed';
        $siteJourneyIsDone = $provisioningState === 'ready';
        $siteVisibleSteps = collect($statusSteps)->except('failed');
        $siteTotalSteps = $siteVisibleSteps->count();
        $siteCompletedSteps = $siteJourneyHasFailed ? max(0, $currentStepIndex) : ($siteJourneyIsDone ? $siteTotalSteps : max(0, $currentStepIndex));
        $siteProgressPercent = $siteTotalSteps > 0 ? (int) round(($siteCompletedSteps / $siteTotalSteps) * 100) : 0;
        $siteCurrentLabel = $statusSteps[$provisioningState] ?? str_replace('_', ' ', $provisioningState);

        return compact(
            'siteJourneyHasFailed',
            'siteJourneyIsDone',
            'siteVisibleSteps',
            'siteTotalSteps',
            'siteCompletedSteps',
            'siteProgressPercent',
            'siteCurrentLabel',
        );
    }

    /**
     * Safe defaults when {@see dashboard()} is skipped (provisioning / not ready).
     *
     * @return array<string, mixed>
     */
    private static function dashboardUnavailableDefaults(): array
    {
        return [
            'atomicReleases' => false,
            'showRuntimeTab' => false,
            'showSslTab' => false,
            'aliasHostnames' => collect(),
        ];
    }

    /**
     * @param  array<string, mixed>  $foundationStatus
     * @return array<string, mixed>
     */
    private static function dashboard(
        Site $site,
        Server $server,
        Show $component,
        string $activeTab,
        array $foundationStatus,
        Collection $preflightErrors,
        Collection $preflightWarnings,
        Collection $hostChecks,
        bool $supportsReleaseRollback,
        mixed $previewDomain,
    ): array {
        $latestDeployment = $site->latestDeployment();
        $primaryHostname = optional($site->primaryDomain())->hostname;
        $aliasHostnames = $site->relationLoaded('domainAliases')
            ? $site->domainAliases->pluck('hostname')->filter()->values()
            : collect();
        $healthLastOk = $site->meta['site_health_last_ok'] ?? null;
        $healthLastCheck = $site->meta['site_health_last_check_at'] ?? null;
        $runtimeDrifted = (bool) ($foundationStatus['runtime_drifted'] ?? false);
        $hostChecksFailing = $hostChecks->filter(fn ($c) => empty($c['ok']))->count();

        $statusTone = match (true) {
            $site->isSuspended() => 'amber',
            $healthLastOk === false => 'red',
            $preflightErrors->isNotEmpty() => 'red',
            $runtimeDrifted, $preflightWarnings->isNotEmpty(), $hostChecksFailing > 0 => 'amber',
            default => 'emerald',
        };
        $statusLabel = match (true) {
            $site->isSuspended() => __('Suspended'),
            $healthLastOk === false => __('URL not responding'),
            $preflightErrors->isNotEmpty() => __('Preflight blocking'),
            $runtimeDrifted => __('Runtime drift'),
            $preflightWarnings->isNotEmpty() => __('Warnings'),
            $hostChecksFailing > 0 => __('Reachability waiting'),
            default => __('Healthy'),
        };
        $toneClasses = [
            'emerald' => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
            'amber' => 'bg-amber-100 text-amber-900 ring-amber-200',
            'red' => 'bg-red-100 text-red-800 ring-red-200',
        ][$statusTone];
        $toneDot = [
            'emerald' => 'bg-emerald-500',
            'amber' => 'bg-amber-500',
            'red' => 'bg-red-500',
        ][$statusTone];

        $showRuntimeTab = ! $site->usesEdgeRuntime()
            && ($site->usesFunctionsRuntime() || $site->usesDockerRuntime() || $site->usesKubernetesRuntime());
        $showSslTab = ! $site->usesEdgeRuntime()
            && ! $site->usesDockerRuntime()
            && ($previewDomain || $site->certificates->isNotEmpty());
        $allowedTabs = collect(['overview', 'deploys', 'logs'])
            ->when($showRuntimeTab, fn ($collection) => $collection->push('runtime'))
            ->when($showSslTab, fn ($collection) => $collection->push('ssl'))
            ->all();
        $activeTab = in_array($activeTab, $allowedTabs, true) ? $activeTab : 'overview';
        $atomicReleases = $site->deploy_strategy === 'atomic' && $supportsReleaseRollback;
        $dashboard_tab = $component->dashboard_tab;

        return compact(
            'latestDeployment',
            'primaryHostname',
            'aliasHostnames',
            'healthLastOk',
            'healthLastCheck',
            'runtimeDrifted',
            'hostChecksFailing',
            'statusTone',
            'statusLabel',
            'toneClasses',
            'toneDot',
            'showRuntimeTab',
            'showSslTab',
            'allowedTabs',
            'activeTab',
            'atomicReleases',
            'dashboard_tab',
        );
    }

    /**
     * @return Collection<int, array{title: string, meta: string|null, transcript: string, action: string, status: string}>
     */
    public static function runtimeOperationConsoles(Collection $runtimeLogs): Collection
    {
        return $runtimeLogs->map(function (array $runtimeLog): array {
            $timestamp = (string) ($runtimeLog['ran_at'] ?? '');
            $status = strtoupper((string) ($runtimeLog['status'] ?? 'unknown'));
            $action = ucfirst((string) ($runtimeLog['action'] ?? 'runtime'));
            $headerParts = array_values(array_filter([$timestamp, $status]));
            $transcript = ($headerParts !== [] ? '['.implode('] [', $headerParts).'] ' : '').$action;
            $output = trim((string) ($runtimeLog['output'] ?? ''));

            if ($output !== '') {
                $transcript .= "\n\n".$output;
            }

            return [
                'title' => __('Runtime activity'),
                'meta' => $action,
                'transcript' => $transcript,
                'action' => strtolower((string) ($runtimeLog['action'] ?? '')),
                'status' => strtolower((string) ($runtimeLog['status'] ?? '')),
            ];
        });
    }

    /**
     * @return Collection<int, array{title: string, meta: string|null, transcript: string}>
     */
    /**
     * @return Collection<int, array{title: string, meta: string|null, transcript: string}>
     */
    public static function deploymentConsolesFor(Collection $deployments): Collection
    {
        return self::deploymentConsoles($deployments);
    }

    /**
     * @return Collection<int, array{title: string, meta: string|null, transcript: string}>
     */
    private static function deploymentConsoles(Collection $deployments): Collection
    {
        return $deployments->map(function ($deployment): array {
            $status = strtoupper((string) $deployment->status);
            $trigger = strtoupper((string) $deployment->trigger);
            $createdAt = $deployment->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') ?? '';
            $prefix = array_filter([$createdAt, $status, $trigger]);
            $transcript = trim(implode("\n", array_filter([
                $prefix !== [] ? '['.implode('] [', $prefix).'] Deployment record' : 'Deployment record',
                $deployment->git_sha ? 'SHA: '.$deployment->git_sha : null,
                trim((string) $deployment->log_output) !== '' ? trim((string) $deployment->log_output) : null,
            ])));

            return [
                'title' => __('Deployment log'),
                'meta' => $deployment->created_at?->diffForHumans(),
                'transcript' => $transcript,
            ];
        });
    }
}
