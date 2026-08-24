<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Livewire\Sites\Show;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SiteDeployment;
use App\Services\Sites\SiteProvisioner;
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
        /** @var list<mixed> $hostChecksRaw */
        $hostChecksRaw = is_array($provisioningMeta['host_checks'] ?? null) ? $provisioningMeta['host_checks'] : [];
        /** @var Collection<int, array<string, mixed>> $hostChecks */
        $hostChecks = (new Collection($hostChecksRaw))
            ->filter(fn ($check): bool => is_array($check) && is_string($check['hostname'] ?? null))
            ->values();
        // Site::serverlessConfig() is removed with the serverless surface
        // (remove-cloud-edge-serverless).
        $serverlessRuntime = [];
        $dockerRuntime = $site->usesDockerRuntime() && is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [];
        $kubernetesRuntime = $site->usesKubernetesRuntime() && is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
        $runtimeTarget = $site->runtimeTarget();
        $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
        $dockerRuntimeDetails = $site->usesDockerRuntime() && is_array($dockerRuntime['runtime_details'] ?? null) ? $dockerRuntime['runtime_details'] : [];
        /** @var list<mixed> $dockerContainersRaw */
        $dockerContainersRaw = is_array($dockerRuntimeDetails['containers'] ?? null) ? $dockerRuntimeDetails['containers'] : [];
        /** @var Collection<int, array<string, mixed>> $dockerContainers */
        $dockerContainers = (new Collection($dockerContainersRaw))
            ->filter(fn ($entry): bool => is_array($entry))
            ->values();
        /** @var list<mixed> $runtimeLogsRaw */
        $runtimeLogsRaw = is_array($runtimeTarget['logs'] ?? null) ? $runtimeTarget['logs'] : [];
        /** @var Collection<int, array<string, mixed>> $runtimeLogs */
        $runtimeLogs = (new Collection($runtimeLogsRaw))
            ->filter(fn ($entry): bool => is_array($entry))
            ->reverse()
            ->values();

        $foundationStatus = is_array($deploymentContract->status ?? null) ? $deploymentContract->status : [];
        /** @var list<mixed> $resourceBindingsRaw */
        $resourceBindingsRaw = $deploymentContract?->resourceBindingArrays() ?? [];
        /** @var Collection<int, array<string, mixed>> $resourceBindings */
        $resourceBindings = (new Collection($resourceBindingsRaw))
            ->filter(fn ($entry): bool => is_array($entry))
            ->values();
        /** @var list<mixed> $preflightChecksRaw */
        $preflightChecksRaw = is_array($deploymentPreflight['checks'] ?? null) ? $deploymentPreflight['checks'] : [];
        /** @var Collection<int, array{key?: string, level?: string, message?: string}> $preflightChecks */
        $preflightChecks = (new Collection($preflightChecksRaw))
            ->filter(fn ($entry): bool => is_array($entry))
            ->values();
        /** @var list<mixed> $preflightErrorsRaw */
        $preflightErrorsRaw = is_array($deploymentPreflight['errors'] ?? null) ? $deploymentPreflight['errors'] : [];
        /** @var Collection<int, string> $preflightErrors */
        $preflightErrors = (new Collection($preflightErrorsRaw))
            ->filter(fn ($entry): bool => is_string($entry))
            ->values();
        /** @var list<mixed> $preflightWarningsRaw */
        $preflightWarningsRaw = is_array($deploymentPreflight['warnings'] ?? null) ? $deploymentPreflight['warnings'] : [];
        /** @var Collection<int, string> $preflightWarnings */
        $preflightWarnings = (new Collection($preflightWarningsRaw))
            ->filter(fn ($entry): bool => is_string($entry))
            ->values();

        // Preflight only makes sense once the operator has actually tried to ship.
        // A brand-new, never-deployed site hasn't asked for a deploy, so surfacing
        // "database/workers binding still pending" up front is noise. Gate the whole
        // preflight surface on a real deploy attempt — a SiteDeployment row exists
        // from the moment a deploy starts (failures included); last_deploy_at covers
        // the cloud/serverless/edge paths that don't write a SiteDeployment row.
        $hasDeployAttempt = ($site->relationLoaded('deployments') && $site->deployments->isNotEmpty())
            || $site->last_deploy_at !== null;
        $preflightActive = $readyForWorkspace && $hasDeployAttempt;

        if (! $preflightActive) {
            /** @var Collection<int, array{key?: string, level?: string, message?: string}> $preflightChecks */
            $preflightChecks = new Collection;
            /** @var Collection<int, string> $preflightErrors */
            $preflightErrors = new Collection;
            /** @var Collection<int, string> $preflightWarnings */
            $preflightWarnings = new Collection;
        }

        $preflightActionableChecks = collect(PreflightIssueFixResolver::actionableChecks($site, $server, $preflightChecks));

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

        $statusSteps = self::byoStatusSteps($site, $provisioningState, $entersFirstDeployState);
        /** @var list<string> $stepKeys */
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

        $siteHeaderBreadcrumbs[] = ['label' => __('Servers'), 'href' => route('servers.index'), 'icon' => 'server-stack'];
        // Project omitted — see SiteWorkspaceBreadcrumbs.
        $siteHeaderBreadcrumbs[] = [
            'label' => $server->name,
            'href' => route('servers.overview', $server),
            'icon' => 'server-stack',
            'avatar' => $server->name ?: (string) $server->id,
            'avatar_image' => $server->logoUrl(),
        ];
        $siteHeaderBreadcrumbs[] = [
            'label' => $site->name,
            'icon' => 'globe-alt',
            'avatar' => $site->name ?: (string) $site->id,
            'avatar_image' => $site->logoUrl(),
        ];

        // Edge is removed; a legacy row with edge_backend still set falls
        // through to the BYO journey rather than a deleted code path.
        $provisioningJourney = self::provisioningJourney(
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
                'preflightActive',
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
            ['activeTab' => $activeTab],
        );
    }

    /**
     * Ordered BYO provision-journey keys + labels (including failed).
     * Wildcard TLS sits between the testing hostname and writing vhost — the
     * same place {@see SiteProvisioner} pauses — so the
     * progress bar does not fall through to 0 when state is
     * `waiting_for_wildcard_tls`.
     *
     * @return array<string, string>
     */
    public static function byoStatusSteps(Site $site, string $provisioningState, bool $entersFirstDeployState): array
    {
        $statusSteps = [
            'queued' => __('Queued'),
            'preparing_runtime_artifacts' => __('Preparing runtime artifacts'),
            'configuring_publication' => __('Preparing publication target'),
            'provisioning_testing_hostname' => __('Assigning testing hostname'),
        ];

        if (self::showsWildcardTlsStep($site, $provisioningState)) {
            $statusSteps['waiting_for_wildcard_tls'] = __('Issuing wildcard TLS');
        }

        $statusSteps['writing_site_config'] = __('Writing site config');
        $statusSteps['waiting_for_http'] = __('Checking reachability');

        if ($entersFirstDeployState) {
            $statusSteps['awaiting_first_deploy'] = __('Waiting for first deploy');
        }

        $statusSteps['ready'] = __('Site available');
        $statusSteps['failed'] = __('Needs attention');

        return $statusSteps;
    }

    /**
     * Show the wildcard step whenever this site will (or already did) wait
     * on a shared per-server cert — not only while the state key is live.
     * That keeps the step count stable from queued through ready.
     */
    private static function showsWildcardTlsStep(Site $site, string $provisioningState): bool
    {
        if ($provisioningState === 'waiting_for_wildcard_tls') {
            return true;
        }

        if (! (bool) config('sites.wildcard_testing_ssl', true)) {
            return false;
        }

        if ($site->usesFunctionsRuntime() || $site->usesDockerRuntime() || $site->usesKubernetesRuntime() || $site->usesEdgeRuntime()) {
            return false;
        }

        $webserver = $site->webserver();

        if ($webserver === 'caddy' && ! ($site->server?->hasEdgeProxy() ?? false)) {
            return false;
        }

        return in_array($webserver, ['nginx', 'caddy', 'openlitespeed', 'apache'], true);
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
     * @param  Collection<int, string>  $preflightErrors
     * @param  Collection<int, string>  $preflightWarnings
     * @param  Collection<int, array<string, mixed>>  $hostChecks
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
     * @param  Collection<int, array<string, mixed>>  $runtimeLogs
     * @return Collection<int, array{title: string, meta: string, transcript: string, action: string, status: string}>
     */
    public static function runtimeOperationConsoles(Collection $runtimeLogs): Collection
    {
        /** @var Collection<int, array{title: string, meta: string, transcript: string, action: string, status: string}> $rows */
        $rows = new Collection;

        foreach ($runtimeLogs as $runtimeLog) {
            $timestamp = (string) ($runtimeLog['ran_at'] ?? '');
            $status = strtoupper((string) ($runtimeLog['status'] ?? 'unknown'));
            $action = ucfirst((string) ($runtimeLog['action'] ?? 'runtime'));
            $headerParts = array_values(array_filter([$timestamp, $status]));
            $transcript = ($headerParts !== [] ? '['.implode('] [', $headerParts).'] ' : '').$action;
            $output = trim((string) ($runtimeLog['output'] ?? ''));

            if ($output !== '') {
                $transcript .= "\n\n".$output;
            }

            $rows->push([
                'title' => (string) __('Runtime activity'),
                'meta' => $action,
                'transcript' => $transcript,
                'action' => strtolower((string) ($runtimeLog['action'] ?? '')),
                'status' => strtolower((string) ($runtimeLog['status'] ?? '')),
            ]);
        }

        /** @var Collection<int, array{title: string, meta: string, transcript: string, action: string, status: string}> $consoles */
        $consoles = $rows->values();

        return $consoles;
    }

    /**
     * @param  Collection<int, SiteDeployment>  $deployments
     * @return Collection<int, array{title: string, meta: string, transcript: string}>
     */
    public static function deploymentConsolesFor(Collection $deployments): Collection
    {
        /** @var Collection<int, array{title: string, meta: string, transcript: string}> $consoles */
        $consoles = self::deploymentConsoles($deployments);

        return $consoles;
    }

    /**
     * @param  Collection<int, SiteDeployment>  $deployments
     * @return Collection<int, array{title: string, meta: string, transcript: string}>
     */
    private static function deploymentConsoles(Collection $deployments): Collection
    {
        /** @var Collection<int, array{title: string, meta: string, transcript: string}> $rows */
        $rows = new Collection;

        foreach ($deployments as $deployment) {
            $status = strtoupper((string) $deployment->status);
            $trigger = strtoupper((string) $deployment->trigger);
            $createdAt = $deployment->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s T');
            $prefix = array_values(array_filter([$createdAt, $status, $trigger]));
            $transcript = trim(implode("\n", array_filter([
                '['.implode('] [', $prefix).'] Deployment record',
                $deployment->git_sha ? 'SHA: '.$deployment->git_sha : null,
                trim((string) $deployment->log_output) !== '' ? trim((string) $deployment->log_output) : null,
            ])));

            $rows->push([
                'title' => (string) __('Deployment log'),
                'meta' => (string) $deployment->created_at->diffForHumans(),
                'transcript' => $transcript,
            ]);
        }

        /** @var Collection<int, array{title: string, meta: string, transcript: string}> $consoles */
        $consoles = $rows->values();

        return $consoles;
    }
}
