@php
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
    $foundationStatus = is_array($deploymentContract->status ?? null) ? $deploymentContract->status : [];
    $resourceBindings = collect($deploymentContract->resourceBindingArrays() ?? [])->filter(fn ($entry) => is_array($entry))->values();
    $preflightChecks = collect($deploymentPreflight['checks'] ?? [])->filter(fn ($entry) => is_array($entry))->values();
    $preflightErrors = collect($deploymentPreflight['errors'] ?? [])->filter(fn ($entry) => is_string($entry))->values();
    $preflightWarnings = collect($deploymentPreflight['warnings'] ?? [])->filter(fn ($entry) => is_string($entry))->values();
    $runtimeOperationConsoles = $runtimeLogs->map(function (array $runtimeLog): array {
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
    $runtimeErrorConsole = $runtimeOperationConsoles->first(fn (array $console): bool => in_array($console['action'], ['errors'], true) || $console['status'] === 'failed');
    $previewDomain = $site->primaryPreviewDomain();
    $activeCertificate = $site->certificates->firstWhere('status', \App\Models\SiteCertificate::STATUS_ACTIVE);
    $pendingCertificate = $activeCertificate
        ? null
        : $site->certificates->first(fn ($certificate) => in_array($certificate->status, [
            \App\Models\SiteCertificate::STATUS_PENDING,
            \App\Models\SiteCertificate::STATUS_ISSUED,
            \App\Models\SiteCertificate::STATUS_INSTALLING,
        ], true));
    $latestCertificate = $activeCertificate ?? $pendingCertificate ?? $site->certificates->first();
    $statusSteps = [
        'queued' => __('Queued'),
        'preparing_runtime_artifacts' => __('Preparing runtime artifacts'),
        'configuring_publication' => __('Preparing publication target'),
        'provisioning_testing_hostname' => __('Assigning testing hostname'),
        'writing_site_config' => __('Writing site config'),
        'waiting_for_http' => __('Checking reachability'),
        'awaiting_first_deploy' => __('Waiting for first deploy'),
        'ready' => __('Site available'),
        'failed' => __('Needs attention'),
    ];
    $stepKeys = array_keys($statusSteps);
    $currentStepIndex = array_search($provisioningState, $stepKeys, true);
    $currentStepIndex = $currentStepIndex === false ? 0 : $currentStepIndex;
    $deploymentConsoles = $site->deployments->map(function ($deployment): array {
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
@endphp

<div>
    @if ($site->server_id)
        <div
            id="dply-site-provisioning-context"
            data-server-id="{{ $site->server_id }}"
            data-site-id="{{ $site->id }}"
            data-subscribe="1"
            class="hidden"
            aria-hidden="true"
        ></div>
    @endif
    @php
        $siteHeaderBreadcrumbs = [
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Servers'), 'href' => route('servers.index'), 'icon' => 'server-stack'],
        ];
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
    @endphp
    <div class="dply-page-shell pt-6">
        <x-breadcrumb-trail :items="$siteHeaderBreadcrumbs" />
    </div>
    <div class="dply-page-shell pt-4">
        <x-page-header
            :title="$readyForWorkspace ? __('Site workspace') : __('Site setup')"
            :description="$readyForWorkspace
                ? __('Manage this site from one workspace with General as the default landing section.')
                : __('Track provisioning steps and setup until this site is ready to receive traffic.')"
            doc-route="docs.index"
            toolbar
            compact
            flush
        >
            <x-slot name="leading">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                    @if ($readyForWorkspace)
                        <x-heroicon-o-globe-alt class="h-7 w-7 text-brand-ink" aria-hidden="true" />
                    @else
                        <x-heroicon-o-rocket-launch class="h-7 w-7 text-brand-ink" aria-hidden="true" />
                    @endif
                </span>
            </x-slot>
            <x-slot name="actions">
                @if ($readyForWorkspace && $site->workspace)
                    <x-outline-link :href="route('projects.resources', $site->workspace)" wire:navigate>
                        <x-heroicon-o-folder-open class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Open project') }}
                    </x-outline-link>
                @endif
                @if ($showWebserverConfigEditor && ! $site->isCustom())
                    <x-outline-link :href="route('sites.webserver-config', [$server, $site])" wire:navigate>
                        <x-heroicon-o-server-stack class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Web server config') }}
                    </x-outline-link>
                @endif
                @if ($readyForWorkspace && ! $site->isCustom())
                    <x-outline-link :href="route('sites.files', [$server, $site])" wire:navigate>
                        <x-heroicon-o-folder class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Files') }}
                    </x-outline-link>
                    <x-outline-link :href="route('sites.insights', [$server, $site])" wire:navigate>
                        <x-heroicon-o-light-bulb class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Insights') }}
                        @if ($openSiteInsightsCount > 0)
                            <span class="inline-flex min-w-[1.25rem] justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[11px] font-semibold leading-none text-white" title="{{ trans_choice(':count open finding|:count open findings', $openSiteInsightsCount, ['count' => $openSiteInsightsCount]) }}">{{ $openSiteInsightsCount }}</span>
                        @endif
                    </x-outline-link>
                    <x-outline-link :href="route('sites.monitor', [$server, $site])" wire:navigate>
                        <x-heroicon-o-signal class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Monitor') }}
                    </x-outline-link>
                @endif
                @if ($site->isCustom() && $site->status === \App\Models\Site::STATUS_CUSTOM_ACTIVE)
                    <x-outline-link :href="route('sites.deployments.index', [$server, $site])" wire:navigate>
                        <x-heroicon-o-code-bracket-square class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Deployments') }}
                    </x-outline-link>
                @endif
            </x-slot>
        </x-page-header>
    </div>
    <div class="pb-12 pt-2">
        <div class="dply-page-shell space-y-6">
            @if ($this->deployLockInfo)
                <div class="p-4 rounded-md bg-amber-50 text-amber-900 text-sm border border-amber-200" wire:poll.5s>
                    <strong>Deployment in progress</strong>
                    @if (! empty($this->deployLockInfo['deployment_id']))
                        <span class="text-amber-800">· run #{{ $this->deployLockInfo['deployment_id'] }}</span>
                    @endif
                    <p class="mt-1 text-amber-800">Queued deploys may appear as <span class="font-medium">skipped</span> until this run finishes.</p>
                    <button type="button" wire:click="openConfirmActionModal('releaseDeployLock', [], @js(__('Clear deploy lock')), @js(__('Force-clear the deploy lock? Only if no worker is actually deploying.')), @js(__('Clear lock')), true)" class="mt-2 text-sm text-amber-900 underline">Clear lock</button>
                </div>
            @endif

            @if (is_array($sitePhpData) && $site->type === \App\Enums\SiteType::Php && ! empty($sitePhpData['mismatch_version']))
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p class="font-medium">{{ __('PHP version mismatch') }}</p>
                    <p class="mt-1 text-amber-800">{{ __('This site references PHP :version, but that version is not currently installed on this server.', ['version' => $sitePhpData['mismatch_version']]) }}</p>
                    <p class="mt-2">
                        <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="font-medium text-amber-900 underline">
                            {{ __('Install or switch versions on the server PHP page') }}
                        </a>
                    </p>
                </div>
            @endif

            @if (! $readyForWorkspace)
                @php
                    $siteJourneyHasFailed = $provisioningState === 'failed';
                    $siteJourneyIsDone = $provisioningState === 'ready';
                    $siteVisibleSteps = collect($statusSteps)->except('failed');
                    $siteTotalSteps = $siteVisibleSteps->count();
                    $siteCompletedSteps = $siteJourneyHasFailed ? max(0, $currentStepIndex) : ($siteJourneyIsDone ? $siteTotalSteps : max(0, $currentStepIndex));
                    $siteProgressPercent = $siteTotalSteps > 0 ? (int) round(($siteCompletedSteps / $siteTotalSteps) * 100) : 0;
                    $siteCurrentLabel = $statusSteps[$provisioningState] ?? str_replace('_', ' ', $provisioningState);
                @endphp
                <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(17rem,20rem)] lg:gap-8" wire:poll.5s="pollProvisioningStatus">
                    {{-- Header card: matches server provision-journey hero --}}
                    <section class="dply-card overflow-hidden min-w-0 lg:col-start-1 lg:row-start-1">
                        <div class="flex flex-col gap-6 border-b border-brand-ink/10 px-5 pb-6 pt-6 sm:px-8 sm:pb-8 sm:pt-8">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Site provisioning') }}</p>
                                        @if ($siteJourneyHasFailed)
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-red-800 ring-1 ring-red-200">
                                                <x-heroicon-s-x-mark class="h-3 w-3" />
                                                {{ __('Failed') }}
                                            </span>
                                        @elseif ($siteJourneyIsDone)
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                                <x-heroicon-s-check class="h-3 w-3" />
                                                {{ __('Ready') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">
                                                <x-heroicon-o-arrow-path class="h-3 w-3 animate-spin" />
                                                {{ __('Live') }}
                                            </span>
                                        @endif
                                    </div>
                                    <h2 class="mt-2 text-xl font-semibold tracking-tight text-brand-ink sm:text-2xl">
                                        {{ __('Site setup (:done/:total)', ['done' => $siteCompletedSteps, 'total' => $siteTotalSteps]) }}
                                    </h2>
                                    <p class="mt-2 max-w-prose text-sm leading-relaxed text-brand-moss">
                                        @if ($siteJourneyHasFailed)
                                            {{ __('Provisioning hit an error. Review the failure details below, then retry — Dply re-runs only the steps that need it.') }}
                                        @else
                                            {{ __('Dply is writing the web server config, attaching the temporary testing URL, and watching for the first hostname that responds.') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                                    @if ($siteJourneyHasFailed)
                                        <button
                                            type="button"
                                            wire:click="retryProvisioning"
                                            wire:loading.attr="disabled"
                                            wire:target="retryProvisioning"
                                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:border-brand-sage hover:text-brand-sage disabled:opacity-60"
                                        >
                                            <x-heroicon-o-arrow-path class="h-4 w-4" />
                                            <span wire:loading.remove wire:target="retryProvisioning">{{ __('Retry provisioning') }}</span>
                                            <span wire:loading wire:target="retryProvisioning">{{ __('Retrying…') }}</span>
                                        </button>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="openCancelProvisioningModal"
                                        wire:loading.attr="disabled"
                                        wire:target="openCancelProvisioningModal"
                                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-800 shadow-sm transition-colors hover:border-red-300 hover:bg-red-100 disabled:opacity-60"
                                    >
                                        <x-heroicon-o-x-circle class="h-4 w-4" />
                                        {{ __('Cancel build') }}
                                    </button>
                                </div>
                            </div>

                            <div>
                                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="inline-flex items-center gap-2 text-sm font-medium text-brand-ink">
                                        <x-heroicon-m-wrench-screwdriver class="h-4 w-4 text-brand-moss" />
                                        {{ __('Site setup') }}
                                    </span>
                                    <span class="text-sm tabular-nums text-brand-moss">{{ __(':done of :total', ['done' => $siteCompletedSteps, 'total' => $siteTotalSteps]) }}</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="h-2.5 min-w-0 flex-1 overflow-hidden rounded-full bg-brand-sand/80">
                                        <div class="h-full rounded-full {{ $siteJourneyHasFailed ? 'bg-red-500' : 'bg-sky-600' }} transition-[width] duration-300" style="width: {{ $siteProgressPercent }}%"></div>
                                    </div>
                                    <span class="shrink-0 text-sm font-semibold tabular-nums {{ $siteJourneyHasFailed ? 'text-red-700' : 'text-sky-700' }}">{{ $siteProgressPercent }}%</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-6 px-5 py-6 sm:px-8 sm:py-8">
                            @if ($siteJourneyHasFailed)
                                <div class="rounded-2xl border-2 border-red-300 bg-red-50/95 px-5 py-5 shadow-sm">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start gap-3">
                                                <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-600 text-white">
                                                    <x-heroicon-s-x-mark class="h-4 w-4" aria-hidden="true" />
                                                </span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-base font-semibold text-red-900 sm:text-lg">{{ __('Provisioning failed at: :step', ['step' => $siteCurrentLabel]) }}</p>
                                                    @if ($provisioningError)
                                                        <div class="mt-2 rounded-xl border border-red-300 bg-white/80 px-4 py-3">
                                                            <div class="flex items-start justify-between gap-3">
                                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-red-700">{{ __('Reason') }}</p>
                                                                <button
                                                                    type="button"
                                                                    x-data="{ copied: false }"
                                                                    x-on:click="navigator.clipboard.writeText(@js($provisioningError)); copied = true; setTimeout(() => copied = false, 1500)"
                                                                    class="shrink-0 rounded-md border border-red-200 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700 hover:border-red-300 hover:bg-red-50"
                                                                >
                                                                    <span x-show="!copied">{{ __('Copy') }}</span>
                                                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                                                </button>
                                                            </div>
                                                            <p class="mt-1 break-words font-mono text-sm leading-6 text-red-900">{{ $provisioningError }}</p>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                        <span class="shrink-0 rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-red-800">{{ __('Failed') }}</span>
                                    </div>
                                </div>
                            @else
                                <div class="rounded-2xl border border-sky-200/80 bg-gradient-to-br from-sky-50/95 to-white px-4 py-4 sm:px-5">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-3">
                                                <span class="inline-flex h-7 w-7 animate-spin items-center justify-center rounded-full border-[3px] border-sky-200 border-t-sky-600" aria-hidden="true"></span>
                                                <p class="text-base font-semibold text-brand-ink sm:text-lg">{{ $siteCurrentLabel }}</p>
                                            </div>
                                            <p class="mt-3 text-sm leading-6 text-brand-moss">
                                                {{ __('This page updates live as the installer moves through each step. The site is considered ready as soon as either the testing URL or the real domain responds.') }}
                                            </p>
                                            @if ($provisioningLog->isNotEmpty())
                                                <details class="mt-4 overflow-hidden rounded-xl border border-brand-ink/10 bg-slate-950 shadow-inner group" x-data>
                                                    <summary class="flex cursor-pointer items-center justify-between gap-3 border-b border-white/5 bg-slate-900/80 px-4 py-2.5">
                                                        <span class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                                            <x-heroicon-o-chevron-right class="h-3.5 w-3.5 transition-transform group-open:rotate-90" />
                                                            {{ __('Install activity') }}
                                                        </span>
                                                        <span class="text-[11px] text-slate-500">{{ __('last :count entries', ['count' => min(8, $provisioningLog->count())]) }}</span>
                                                    </summary>
                                                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words px-4 py-3 font-mono text-[12px] leading-5 text-slate-200">{{ $provisioningTranscript }}</pre>
                                                </details>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endif

                            {{-- Step timeline --}}
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10">
                                <div class="flex items-center justify-between gap-4 border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Provisioning steps') }}</p>
                                        <p class="mt-1 text-sm text-brand-moss">{{ __('Compact install timeline — done, running, and what comes next.') }}</p>
                                    </div>
                                    <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                                        {{ max(1, $currentStepIndex + 1) }} / {{ $siteTotalSteps }}
                                    </span>
                                </div>
                                <ol class="divide-y divide-brand-ink/5">
                                    @foreach ($siteVisibleSteps as $key => $label)
                                        @php
                                            $loopIndex = array_search($key, $stepKeys, true);
                                            $isDone = ! $siteJourneyHasFailed && $loopIndex !== false && $loopIndex < $currentStepIndex;
                                            $isCurrent = $key === $provisioningState;
                                        @endphp
                                        <li class="flex items-start gap-4 px-5 py-4 sm:px-6">
                                            <div class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $isCurrent ? ($siteJourneyHasFailed ? 'bg-red-600 text-white' : 'bg-sky-600 text-white ring-4 ring-sky-100') : ($isDone ? 'bg-emerald-600 text-white' : 'bg-white text-brand-mist ring-1 ring-brand-ink/10') }}">
                                                @if ($isDone)
                                                    <x-heroicon-s-check class="h-4 w-4" />
                                                @elseif ($isCurrent && ! $siteJourneyHasFailed)
                                                    <span class="inline-flex h-3 w-3 animate-pulse rounded-full bg-white"></span>
                                                @else
                                                    {{ $loop->iteration }}
                                                @endif
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <p class="font-medium text-brand-ink">{{ $label }}</p>
                                                    @if ($isCurrent && ! $siteJourneyHasFailed)
                                                        <span class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800">{{ __('Live') }}</span>
                                                    @elseif ($isDone)
                                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800">{{ __('Done') }}</span>
                                                    @endif
                                                </div>
                                                <p class="mt-1 text-sm leading-6 {{ $isDone ? 'text-brand-forest' : 'text-brand-moss' }}">
                                                    @if ($isCurrent && ! $siteJourneyHasFailed)
                                                        {{ __('This is the active install step right now.') }}
                                                    @elseif ($isDone)
                                                        {{ __('Completed successfully.') }}
                                                    @else
                                                        {{ __('Runs automatically once the earlier steps finish.') }}
                                                    @endif
                                                </p>
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        </div>
                    </section>

                    {{-- Right sidebar — mirrors server provision-journey's
                         <aside>: site summary + testing URL + DNS readiness.
                         Sticky on lg so it stays in view while the journey
                         scrolls. --}}
                    <aside class="w-full space-y-6 self-start lg:col-start-2 lg:row-start-1 lg:sticky lg:top-24 lg:max-w-none">
                        <section class="dply-card overflow-hidden p-5 sm:p-6">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Site summary') }}</h3>
                            <dl class="mt-4 grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                                    <dd class="mt-0.5 font-semibold capitalize text-brand-ink">{{ $site->statusLabel() }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Type') }}</dt>
                                    <dd class="mt-0.5 font-medium capitalize text-brand-ink">{{ $site->type->label() }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Web server') }}</dt>
                                    <dd class="mt-0.5 font-medium capitalize text-brand-ink">{{ $site->webserver() }}</dd>
                                </div>
                                @if ($site->runtimeKey())
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Runtime') }}</dt>
                                        <dd class="mt-0.5 font-medium text-brand-ink">
                                            <span class="capitalize">{{ $site->runtimeKey() }}</span>@if ($site->runtimeVersion())
                                                <span class="font-mono text-brand-mist"> · {{ $site->runtimeVersion() }}</span>
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                                @if ($site->internal_port)
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Internal port') }}</dt>
                                        <dd class="mt-0.5 font-mono text-brand-ink">{{ $site->internal_port }}</dd>
                                    </div>
                                @endif
                                @if (filled($site->build_command))
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Build command') }}</dt>
                                        <dd class="mt-0.5 break-all font-mono text-xs text-brand-ink">{{ $site->build_command }}</dd>
                                    </div>
                                @endif
                                @if (filled($site->start_command))
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Start command') }}</dt>
                                        <dd class="mt-0.5 break-all font-mono text-xs text-brand-ink">{{ $site->start_command }}</dd>
                                    </div>
                                @endif
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Primary domain') }}</dt>
                                    <dd class="mt-0.5 break-all font-mono text-xs font-medium text-brand-ink">{{ optional($site->primaryDomain())->hostname ?? '—' }}</dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Current step') }}</dt>
                                    <dd class="mt-0.5 font-medium text-brand-ink">{{ $siteCurrentLabel }}</dd>
                                </div>
                            </dl>
                            @if ($targetUrl)
                                <div class="mt-5 rounded-2xl border border-emerald-200 bg-gradient-to-b from-emerald-50 to-white px-4 py-4">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-700">{{ __('Testing URL') }}</p>
                                    <p class="mt-2 break-all font-mono text-xs text-emerald-950">{{ $targetUrl }}</p>
                                    <p class="mt-2 text-xs leading-5 text-emerald-800/80">{{ __('Use this first while the customer domain catches up.') }}</p>
                                </div>
                            @endif
                        </section>

                        <section class="dply-card overflow-hidden p-5 sm:p-6">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('DNS readiness') }}</p>
                            <h3 class="mt-2 text-base font-semibold text-brand-ink">{{ __('Either URL can finish setup') }}</h3>
                            <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Dply checks both URLs and moves on as soon as one responds.') }}</p>

                            @if (($testingHostnameMeta['status'] ?? null) === 'failed')
                                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                                    <p class="font-medium">{{ __('Temporary hostname could not be created') }}</p>
                                    <p class="mt-1">{{ $testingHostnameMeta['error'] ?? __('Check the global DigitalOcean token and the configured testing domains.') }}</p>
                                </div>
                            @endif

                            @if ($hostChecks->isNotEmpty())
                                <ul class="mt-4 space-y-2">
                                    @foreach ($hostChecks as $check)
                                        <li class="rounded-xl border {{ ($check['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50/70' : 'border-amber-200 bg-amber-50/70' }} p-3">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="min-w-0">
                                                    <p class="break-all font-mono text-xs font-medium text-brand-ink">{{ $check['hostname'] }}</p>
                                                    <p class="mt-1 text-[11px] leading-snug {{ ($check['ok'] ?? false) ? 'text-emerald-800' : 'text-amber-900' }}">
                                                        {{ ($check['ok'] ?? false) ? __('Reachable — can finish the install.') : ($check['error'] ?? __('Not reachable yet.')) }}
                                                    </p>
                                                </div>
                                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ ($check['ok'] ?? false) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                                    {{ ($check['ok'] ?? false) ? __('Ready') : __('Waiting') }}
                                                </span>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-white/60 p-3 text-xs text-brand-moss">
                                    {{ __('No hostname checks yet — Dply will start polling once the web server config is written.') }}
                                </p>
                            @endif
                        </section>

                        @can('delete', $site)
                            <section class="dply-card overflow-hidden p-5 sm:p-6">
                                <p class="text-xs leading-relaxed text-brand-moss">
                                    {{ __('If the install is stuck or you want to abandon it, cancel provisioning to remove the temporary DNS record and clean up the generated server config.') }}
                                </p>
                            </section>
                        @endcan
                    </aside>
                </div>
            @else
            @php
                $latestDeployment = $site->deployments->first();
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

                $showRuntimeTab = $site->usesFunctionsRuntime() || $site->usesDockerRuntime() || $site->usesKubernetesRuntime();
                $showSslTab = ! $site->usesDockerRuntime() && ($previewDomain || $site->certificates->isNotEmpty());
                $allowedTabs = collect(['overview', 'deploys', 'logs'])
                    ->when($showRuntimeTab, fn ($c) => $c->push('runtime'))
                    ->when($showSslTab, fn ($c) => $c->push('ssl'))
                    ->all();
                $activeTab = in_array($dashboard_tab, $allowedTabs, true) ? $dashboard_tab : 'overview';
                $atomicReleases = $site->deploy_strategy === 'atomic' && $supportsReleaseRollback;
            @endphp

            <x-explainer class="mb-2">
                <p>{{ __('Site dashboard — health at a glance, the most-recent endpoints, and the deploy controls.') }}</p>
                <p>{{ __('Routing, certificates, environment, redirects, deploy hooks, and destructive actions live in') }}
                    <a href="{{ route('sites.settings', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate>{{ __('Site settings') }}</a>.
                    {{ __('Logs, runtime, and SSL are tabs below. Webserver config and Insights have dedicated pages linked above.') }}
                </p>
            </x-explainer>

            {{-- Hero card: identity + endpoints + primary action ----------------------------------- --}}
            <section class="dply-card overflow-hidden">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                            <x-heroicon-o-globe-alt class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold text-brand-ink">{{ $primaryHostname ?? $site->name }}</h2>
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 {{ $toneClasses }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $toneDot }}"></span>
                                    {{ $statusLabel }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                <span class="capitalize">{{ $site->type->label() }}</span>
                                @if ($site->runtimeKey())
                                    <span class="text-brand-mist/70">·</span>
                                    <span class="capitalize">{{ $site->runtimeKey() }}</span>@if ($site->runtimeVersion())<span class="font-mono text-brand-mist"> {{ $site->runtimeVersion() }}</span>@endif
                                @endif
                                <span class="text-brand-mist/70">·</span>
                                <span class="capitalize">{{ $site->webserver() }}</span>
                                <span class="text-brand-mist/70">·</span>
                                <span>{{ $site->deploy_strategy === 'atomic' ? __('atomic deploys') : __('simple deploys') }}</span>
                            </p>
                            <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1.5 text-xs">
                                @if ($site->visitUrl())
                                    <a href="{{ $site->visitUrl() }}" target="_blank" rel="noopener" class="inline-flex max-w-full items-center gap-1 rounded-full border border-brand-forest/25 bg-brand-forest/8 px-2.5 py-1 font-mono text-[11px] text-brand-forest hover:bg-brand-forest/15">
                                        <x-heroicon-m-globe-alt class="h-3 w-3" />
                                        <span class="truncate">{{ $site->visitUrl() }}</span>
                                        <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 shrink-0 opacity-70" />
                                    </a>
                                @endif
                                @foreach ($aliasHostnames as $alias)
                                    <span class="inline-flex max-w-full items-center gap-1 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 font-mono text-[11px] text-brand-ink">
                                        <x-heroicon-m-link class="h-3 w-3 text-brand-mist" />
                                        <span class="truncate">{{ $alias }}</span>
                                    </span>
                                @endforeach
                                @if ($testingHostname !== '')
                                    <a href="http://{{ $testingHostname }}" target="_blank" rel="noopener" class="inline-flex max-w-full items-center gap-1 rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 font-mono text-[11px] text-sky-900 hover:bg-sky-100">
                                        <x-heroicon-m-beaker class="h-3 w-3" />
                                        <span class="truncate">{{ $testingHostname }}</span>
                                        <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 shrink-0 opacity-70" />
                                    </a>
                                @endif
                                @if ($previewDomain?->hostname)
                                    <a href="http://{{ $previewDomain->hostname }}" target="_blank" rel="noopener" class="inline-flex max-w-full items-center gap-1 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 font-mono text-[11px] text-brand-ink hover:bg-brand-sand/40">
                                        <x-heroicon-m-eye class="h-3 w-3 text-brand-mist" />
                                        <span class="truncate">{{ $previewDomain->hostname }}</span>
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <button
                            type="button"
                            wire:click="deployNow"
                            wire:loading.attr="disabled"
                            wire:target="deployNow"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <x-heroicon-o-rocket-launch class="h-3.5 w-3.5" wire:loading.remove wire:target="deployNow" />
                            <span wire:loading wire:target="deployNow" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="white" size="sm" /></span>
                            <span wire:loading.remove wire:target="deployNow">{{ __('Deploy now') }}</span>
                            <span wire:loading wire:target="deployNow">{{ __('Deploying…') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="queueDeploy"
                            wire:loading.attr="disabled"
                            wire:target="queueDeploy"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <x-heroicon-o-queue-list class="h-3.5 w-3.5" />
                            {{ __('Queue deploy') }}
                        </button>
                        <span class="hidden h-5 w-px bg-brand-ink/10 sm:block" aria-hidden="true"></span>
                        <a href="{{ route('sites.settings', ['server' => $server, 'site' => $site, 'section' => $site->usesDockerRuntime() ? 'runtime' : 'general']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5" />
                            {{ __('Settings') }}
                        </a>
                    </div>
                </div>

                <div class="grid gap-4 px-6 py-4 text-xs sm:grid-cols-3 sm:gap-6 sm:px-8">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Last deploy') }}</p>
                        @if ($latestDeployment)
                            <p class="mt-1 font-medium text-brand-ink">
                                <span class="capitalize">{{ str_replace('_', ' ', (string) $latestDeployment->status) }}</span>
                                <span class="text-brand-mist/80">·</span>
                                <span class="text-brand-moss">{{ optional($latestDeployment->started_at ?? $latestDeployment->created_at)->diffForHumans() ?? '—' }}</span>
                            </p>
                            @if ($latestDeployment->git_sha)
                                <p class="mt-0.5 font-mono text-[11px] text-brand-mist">{{ \Illuminate\Support\Str::limit($latestDeployment->git_sha, 14, '') }}</p>
                            @endif
                        @else
                            <p class="mt-1 text-brand-moss">{{ __('No deploys yet.') }}</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('URL health') }}</p>
                        @if ($healthLastCheck)
                            <p class="mt-1 font-medium {{ $healthLastOk ? 'text-emerald-700' : 'text-red-700' }}">
                                {{ $healthLastOk ? __('OK') : __('Failed') }}
                                <span class="font-normal text-brand-mist/80">·</span>
                                <span class="font-normal text-brand-moss">{{ \Illuminate\Support\Carbon::parse($healthLastCheck)->diffForHumans() }}</span>
                            </p>
                        @else
                            <p class="mt-1 text-brand-moss">{{ __('Not checked yet') }}</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('SSL') }}</p>
                        <p class="mt-1 font-medium capitalize text-brand-ink">{{ $site->currentSslSummary() ?: __('—') }}</p>
                    </div>
                </div>
            </section>

            {{-- Tablist ---------------------------------------------------------------------------- --}}
            <x-server-workspace-tablist :aria-label="__('Site dashboard sections')" class="mt-6">
                <x-server-workspace-tab id="site-tab-overview" :active="$activeTab === 'overview'" wire:click="$set('dashboard_tab', 'overview')">
                    <span class="inline-flex items-center gap-1.5"><x-heroicon-o-rectangle-stack class="h-4 w-4" />{{ __('Overview') }}</span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="site-tab-deploys" :active="$activeTab === 'deploys'" wire:click="$set('dashboard_tab', 'deploys')">
                    <span class="inline-flex items-center gap-1.5"><x-heroicon-o-code-bracket class="h-4 w-4" />{{ __('Deploys') }}</span>
                </x-server-workspace-tab>
                @if ($showRuntimeTab)
                    <x-server-workspace-tab id="site-tab-runtime" :active="$activeTab === 'runtime'" wire:click="$set('dashboard_tab', 'runtime')">
                        <span class="inline-flex items-center gap-1.5"><x-heroicon-o-cube class="h-4 w-4" />{{ __('Runtime') }}</span>
                    </x-server-workspace-tab>
                @endif
                <x-server-workspace-tab id="site-tab-logs" :active="$activeTab === 'logs'" wire:click="$set('dashboard_tab', 'logs')">
                    <span class="inline-flex items-center gap-1.5"><x-heroicon-o-clipboard-document-list class="h-4 w-4" />{{ __('Logs') }}</span>
                </x-server-workspace-tab>
                @if ($showSslTab)
                    <x-server-workspace-tab id="site-tab-ssl" :active="$activeTab === 'ssl'" wire:click="$set('dashboard_tab', 'ssl')">
                        <span class="inline-flex items-center gap-1.5"><x-heroicon-o-lock-closed class="h-4 w-4" />{{ __('SSL') }}</span>
                    </x-server-workspace-tab>
                @endif
            </x-server-workspace-tablist>

            {{-- Overview panel --------------------------------------------------------------------- --}}
            <x-server-workspace-tab-panel id="site-panel-overview" labelled-by="site-tab-overview" :hidden="$activeTab !== 'overview'" panel-class="space-y-6">
                @if ($site->workspace)
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-moss">
                        <p class="font-medium text-brand-ink">{{ __('Project context') }}</p>
                        <p class="mt-1">
                            {{ __('Rolls up into the :project project.', ['project' => $site->workspace->name]) }}
                            <a href="{{ route('projects.operations', $site->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:underline">{{ __('Operations') }}</a>
                            ·
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:underline">{{ __('Delivery') }}</a>
                        </p>
                    </div>
                @endif

                <div class="grid gap-6 lg:grid-cols-2">
                    {{-- Endpoints --}}
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Endpoints') }}</h3>
                            <a href="{{ route('sites.settings', ['server' => $server, 'site' => $site, 'section' => 'routing']) }}" wire:navigate class="text-xs font-medium text-brand-sage hover:underline">{{ __('Manage routing') }}</a>
                        </div>
                        <dl class="divide-y divide-brand-ink/8 px-6 py-2 text-sm sm:px-8">
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Primary domain') }}</dt>
                                <dd class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink">{{ $primaryHostname ?? '—' }}</dd>
                            </div>
                            @if ($aliasHostnames->isNotEmpty())
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Aliases') }}</dt>
                                    <dd class="min-w-0 flex-1 space-y-0.5 font-mono text-xs text-brand-ink">
                                        @foreach ($aliasHostnames as $alias)
                                            <p class="break-all">{{ $alias }}</p>
                                        @endforeach
                                    </dd>
                                </div>
                            @endif
                            @if ($previewDomain?->hostname)
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Preview') }}</dt>
                                    <dd class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink">
                                        {{ $previewDomain->hostname }}
                                        <span class="text-brand-mist">· {{ $previewDomain->dns_status ?? __('not configured') }}</span>
                                    </dd>
                                </div>
                            @endif
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Testing URL') }}</dt>
                                <dd class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink">
                                    @if ($testingHostname !== '')
                                        {{ $testingHostname }}
                                        @if (! $site->isReadyForTraffic())
                                            <span class="text-brand-mist">· {{ __('still polling') }}</span>
                                        @endif
                                    @elseif (($testingHostnameMeta['status'] ?? null) === 'failed')
                                        <span class="text-amber-800">{{ $testingHostnameMeta['error'] ?? __('failed to assign') }}</span>
                                    @else
                                        <span class="text-brand-mist">{{ __('none assigned') }}</span>
                                    @endif
                                </dd>
                            </div>
                            @if ($site->internal_port)
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Internal port') }}</dt>
                                    <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">127.0.0.1:{{ $site->internal_port }}</dd>
                                </div>
                            @endif
                            @if ($site->usesDockerRuntime() && ($runtimePublication['url'] ?? null))
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Published URL') }}</dt>
                                    <dd class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink">{{ $runtimePublication['url'] }}</dd>
                                </div>
                            @endif
                            @php
                                $cdnCfg = $site->cdnConfig();
                                $cdnHitRate = isset($cdnCfg['metrics']['hit_rate']) && is_numeric($cdnCfg['metrics']['hit_rate'])
                                    ? (float) $cdnCfg['metrics']['hit_rate']
                                    : null;
                            @endphp
                            @if (! empty($cdnCfg['provider']))
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Edge / CDN') }}</dt>
                                    <dd class="min-w-0 flex-1 text-xs text-brand-ink">
                                        <a href="{{ route('sites.cdn', [$server, $site]) }}" wire:navigate class="hover:underline">
                                            <span class="font-mono">{{ ucfirst($cdnCfg['provider']) }}</span>
                                            <span class="ml-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide
                                                {{ ! empty($cdnCfg['enabled']) ? 'bg-emerald-100 text-emerald-800' : 'bg-brand-sand/40 text-brand-mist' }}">
                                                {{ ! empty($cdnCfg['enabled']) ? __('Active') : __('Off') }}
                                            </span>
                                            @if ($cdnHitRate !== null)
                                                <span class="ml-1 rounded-full bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800" title="{{ __('Cache hit rate over the last 24h') }}">
                                                    {{ number_format($cdnHitRate * 100, 0) }}% {{ __('hit') }}
                                                </span>
                                            @endif
                                            @if (! empty($cdnCfg['last_error']))
                                                <span class="ml-1 rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-800" title="{{ $cdnCfg['last_error'] }}">{{ __('Error') }}</span>
                                            @endif
                                        </a>
                                    </dd>
                                </div>
                            @endif
                        </dl>
                        <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3 text-xs text-brand-moss sm:px-8">
                            {{ __('Show this site on a public') }}
                            <a href="{{ route('status-pages.index') }}" class="font-medium text-brand-ink hover:underline">{{ __('status page') }}</a>.
                        </div>
                    </section>

                    {{-- Health --}}
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Health & checks') }}</h3>
                            <a href="{{ route('sites.monitor', [$server, $site]) }}" wire:navigate class="text-xs font-medium text-brand-sage hover:underline">{{ __('Open monitor') }}</a>
                        </div>
                        <ul class="divide-y divide-brand-ink/8 px-6 sm:px-8">
                            <li class="flex items-start justify-between gap-3 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-brand-ink">{{ __('URL responds') }}</p>
                                    <p class="text-xs text-brand-moss">{{ __('Last checked') }} {{ $healthLastCheck ? \Illuminate\Support\Carbon::parse($healthLastCheck)->diffForHumans() : __('never') }}</p>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide
                                    {{ $healthLastOk === true ? 'bg-emerald-100 text-emerald-800' : ($healthLastOk === false ? 'bg-red-100 text-red-800' : 'bg-brand-sand/40 text-brand-mist') }}">
                                    {{ $healthLastOk === true ? __('OK') : ($healthLastOk === false ? __('Failed') : __('—')) }}
                                </span>
                            </li>
                            <li class="flex items-start justify-between gap-3 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-brand-ink">{{ __('Runtime contract') }}</p>
                                    <p class="break-all font-mono text-[11px] text-brand-mist">{{ \Illuminate\Support\Str::limit((string) ($foundationStatus['current_runtime_revision'] ?? '—'), 24) }}</p>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide
                                    {{ $runtimeDrifted ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                                    {{ $runtimeDrifted ? __('Drift') : __('In sync') }}
                                </span>
                            </li>
                            <li class="flex items-start justify-between gap-3 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-brand-ink">{{ __('SSL') }}</p>
                                    <p class="text-xs capitalize text-brand-moss">{{ $site->ssl_status ?: __('Not configured') }}</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                    {{ $site->currentSslSummary() ?: '—' }}
                                </span>
                            </li>
                            @if ($site->isSuspended())
                                <li class="flex items-start justify-between gap-3 py-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-brand-ink">{{ __('Public traffic') }}</p>
                                        <p class="text-xs text-amber-800">{{ __('Suspended — visitors see the suspended page.') }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">{{ __('Suspended') }}</span>
                                </li>
                            @endif
                            @if ($hostChecks->isNotEmpty())
                                <li class="py-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Reachability checks') }}</p>
                                    <ul class="mt-2 space-y-1.5">
                                        @foreach ($hostChecks as $check)
                                            <li class="flex items-center justify-between gap-3 rounded-lg border {{ ($check['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50/60' : 'border-amber-200 bg-amber-50/60' }} px-3 py-2">
                                                <p class="break-all font-mono text-[11px] text-brand-ink">{{ $check['hostname'] }}</p>
                                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ ($check['ok'] ?? false) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                                    {{ ($check['ok'] ?? false) ? __('Ready') : __('Waiting') }}
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </li>
                            @endif
                        </ul>
                    </section>
                </div>

                {{-- Preflight + resources --}}
                <div class="grid gap-6 lg:grid-cols-2">
                    <section class="dply-card overflow-hidden p-6 sm:p-8">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Launch preflight') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Shared deployment checks for config, publication, and attached resources.') }}</p>
                        @if ($preflightErrors->isEmpty() && $preflightWarnings->isEmpty())
                            <p class="mt-3 text-sm font-medium text-emerald-700">{{ __('No blocking preflight issues.') }}</p>
                        @else
                            <div class="mt-3 space-y-2">
                                @foreach ($preflightErrors as $error)
                                    <p class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $error }}</p>
                                @endforeach
                                @foreach ($preflightWarnings as $warning)
                                    <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ $warning }}</p>
                                @endforeach
                            </div>
                        @endif
                    </section>

                    <section class="dply-card overflow-hidden p-6 sm:p-8">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Attached resources') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('What this site expects around the app runtime.') }}</p>
                        @if ($resourceBindings->isEmpty())
                            <p class="mt-3 text-sm text-brand-mist">{{ __('No resource bindings recorded.') }}</p>
                        @else
                            <div class="mt-3 space-y-2">
                                @foreach ($resourceBindings as $binding)
                                    @include('livewire.sites.partials.resource-binding-row', [
                                        'binding' => $binding,
                                        'configuredClass' => 'bg-emerald-100 text-emerald-700',
                                    ])
                                @endforeach
                            </div>
                        @endif
                    </section>
                </div>
            </x-server-workspace-tab-panel>

            {{-- Deploys panel ---------------------------------------------------------------------- --}}
            <x-server-workspace-tab-panel id="site-panel-deploys" labelled-by="site-tab-deploys" :hidden="$activeTab !== 'deploys'" panel-class="space-y-6">
                <section class="dply-card overflow-hidden p-6 sm:p-8">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Deploy this site') }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Run a deploy now (synchronous) or queue one for the worker. Repository and runtime config live in') }}
                                <a href="{{ route('sites.settings', ['server' => $server, 'site' => $site, 'section' => 'deploy']) }}" wire:navigate class="font-medium text-brand-ink hover:underline">{{ __('deploy settings') }}</a>.
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <button type="button" wire:click="deployNow" wire:loading.attr="disabled" wire:target="deployNow" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:opacity-60">
                                <x-heroicon-o-rocket-launch class="h-3.5 w-3.5" wire:loading.remove wire:target="deployNow" />
                                <span wire:loading wire:target="deployNow"><x-spinner variant="white" size="sm" /></span>
                                <span wire:loading.remove wire:target="deployNow">{{ __('Deploy now') }}</span>
                                <span wire:loading wire:target="deployNow">{{ __('Deploying…') }}</span>
                            </button>
                            <button type="button" wire:click="queueDeploy" wire:loading.attr="disabled" wire:target="queueDeploy" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50">
                                <x-heroicon-o-queue-list class="h-3.5 w-3.5" />
                                {{ __('Queue deploy') }}
                            </button>
                        </div>
                    </div>
                </section>

                @if ($atomicReleases)
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Releases & rollback') }}</h3>
                            <span class="text-xs text-brand-mist">{{ trans_choice('{0} no releases|{1} :count release|[2,*] :count releases', $site->releases->count(), ['count' => $site->releases->count()]) }}</span>
                        </div>
                        @if ($site->releases->isEmpty())
                            <p class="px-6 py-6 text-sm text-brand-mist sm:px-8">{{ __('No recorded releases yet. Deploy once with the atomic strategy.') }}</p>
                        @else
                            <ul class="divide-y divide-brand-ink/8">
                                @foreach ($site->releases as $rel)
                                    <li class="flex items-center justify-between gap-3 px-6 py-3 sm:px-8">
                                        <div class="min-w-0">
                                            <p class="font-mono text-xs text-brand-ink">{{ $rel->folder }}
                                                @if ($rel->is_active)<span class="ml-2 inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-800">{{ __('Active') }}</span>@endif
                                            </p>
                                            @if ($rel->git_sha)
                                                <p class="font-mono text-[11px] text-brand-mist">{{ $rel->git_sha }}</p>
                                            @endif
                                        </div>
                                        @if (! $rel->is_active)
                                            <button type="button" wire:click="confirmRollbackRelease('{{ $rel->id }}')" class="text-xs font-medium text-brand-sage hover:underline">{{ __('Rollback') }}</button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif

                <section class="dply-card overflow-hidden" wire:poll.10s>
                    <div class="flex items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Recent deployments') }}</h3>
                        @if ($site->workspace)
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="text-xs font-medium text-brand-sage hover:underline">{{ __('Project delivery') }}</a>
                        @endif
                    </div>
                    <div class="px-6 py-5 sm:px-8">
                        @if ($deploymentConsoles->isEmpty())
                            <p class="text-sm text-brand-mist">{{ __('No deployments yet.') }}</p>
                        @else
                            <div class="space-y-4">
                                @foreach ($deploymentConsoles as $deploymentConsole)
                                    @include('livewire.partials.deployment-activity-console', [
                                        'title' => $deploymentConsole['title'],
                                        'meta' => $deploymentConsole['meta'],
                                        'transcript' => \Illuminate\Support\Str::limit($deploymentConsole['transcript'], 8000),
                                        'maxHeight' => '20rem',
                                    ])
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>
            </x-server-workspace-tab-panel>

            {{-- Runtime panel ---------------------------------------------------------------------- --}}
            @if ($showRuntimeTab)
                <x-server-workspace-tab-panel id="site-panel-runtime" labelled-by="site-tab-runtime" :hidden="$activeTab !== 'runtime'" panel-class="space-y-6">
                    <section class="dply-card overflow-hidden p-6 sm:p-8 space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('Runtime target') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('The latest managed deploy details for this runtime target.') }}</p>
                            </div>
                            <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">
                                {{ $site->runtimeTargetLabel() }}
                            </span>
                        </div>

                        <dl class="grid gap-4 sm:grid-cols-3">
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Platform') }}</dt>
                                <dd class="mt-2 text-sm text-brand-ink">{{ ucfirst((string) ($runtimeTarget['platform'] ?? 'unknown')) }}</dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Mode') }}</dt>
                                <dd class="mt-2 text-sm text-brand-ink">{{ ucfirst((string) ($runtimeTarget['mode'] ?? 'unknown')) }}</dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Status') }}</dt>
                                <dd class="mt-2 text-sm text-brand-ink">{{ ucfirst(str_replace('_', ' ', (string) ($runtimeTarget['status'] ?? 'unknown'))) }}</dd>
                            </div>
                        </dl>

                        @if ($preflightChecks->isNotEmpty())
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <h4 class="text-sm font-semibold text-brand-ink">{{ __('Deployment foundation') }}</h4>
                                <dl class="mt-3 grid gap-4 sm:grid-cols-3">
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Current revision') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-xs text-brand-ink">{{ $foundationStatus['current_runtime_revision'] ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Last applied revision') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-xs text-brand-ink">{{ $foundationStatus['last_applied_runtime_revision'] ?? __('Not applied yet') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Drift') }}</dt>
                                        <dd class="mt-2 text-sm {{ $runtimeDrifted ? 'text-amber-700' : 'text-emerald-700' }}">{{ $runtimeDrifted ? __('Detected') : __('In sync') }}</dd>
                                    </div>
                                </dl>
                                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                                    @foreach ($preflightChecks as $check)
                                        <div class="rounded-lg border px-3 py-2 text-sm {{ ($check['level'] ?? 'ok') === 'error' ? 'border-red-200 bg-red-50 text-red-800' : (($check['level'] ?? 'ok') === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800') }}">
                                            <span class="font-medium">{{ str($check['key'] ?? 'check')->headline() }}</span>
                                            <p class="mt-1 text-xs leading-5">{{ $check['message'] ?? '' }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($runtimePublication !== [])
                            <dl class="grid gap-4 sm:grid-cols-3">
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Publication status') }}</dt>
                                    <dd class="mt-2 text-sm text-brand-ink">{{ ucfirst((string) ($runtimePublication['status'] ?? 'pending')) }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Hostname') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['hostname'] ?? '—' }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Published URL') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['url'] ?? '—' }}</dd>
                                </div>
                            </dl>
                        @endif

                        @if ($site->usesFunctionsRuntime())
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Runtime') }}</dt>
                                    <dd class="mt-2 font-mono text-sm text-brand-ink">{{ $serverlessRuntime['runtime'] ?? '—' }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Entrypoint') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $serverlessRuntime['entrypoint'] ?? '—' }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Revision') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $serverlessRuntime['last_revision_id'] ?? __('Not deployed yet') }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Latest artifact') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $serverlessRuntime['artifact_path'] ?? __('Not built yet') }}</dd>
                                </div>
                                @if (! empty($serverlessRuntime['function_arn']))
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Function ARN') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $serverlessRuntime['function_arn'] }}</dd>
                                    </div>
                                @endif
                                @if (! empty($serverlessRuntime['function_url']))
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Function URL') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $serverlessRuntime['function_url'] }}</dd>
                                    </div>
                                @endif
                                @if (! empty($serverlessRuntime['action_url']))
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Published action URL') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $serverlessRuntime['action_url'] }}</dd>
                                    </div>
                                @endif
                            </dl>
                        @elseif ($site->usesDockerRuntime())
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Compose file') }}</dt>
                                    <dd class="mt-2 text-sm text-brand-ink">{{ isset($dockerRuntime['compose_yaml']) ? __('Available') : __('Not generated yet') }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Dockerfile') }}</dt>
                                    <dd class="mt-2 text-sm text-brand-ink">{{ isset($dockerRuntime['dockerfile']) ? __('Available') : __('Not generated yet') }}</dd>
                                </div>
                                @if (! empty($dockerRuntime['workspace_path']))
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Local workspace') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $dockerRuntime['workspace_path'] }}</dd>
                                    </div>
                                @endif
                            </dl>

                            @if ($dockerContainers->isNotEmpty() || $runtimePublication !== [])
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 space-y-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Docker discovery') }}</p>
                                            <p class="mt-1 text-sm text-brand-moss">{{ __('Saved from the live runtime so hostname, IP, and identity stay referenceable.') }}</p>
                                        </div>
                                        @if (! empty($dockerRuntimeDetails['collected_at']))
                                            <p class="font-mono text-[11px] text-brand-mist">{{ __('Collected :time', ['time' => $dockerRuntimeDetails['collected_at']]) }}</p>
                                        @endif
                                    </div>

                                    <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Hostname') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['hostname'] ?? '—' }}</dd>
                                        </div>
                                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Container IP') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['container_ip'] ?? '—' }}</dd>
                                        </div>
                                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Container name') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['container_name'] ?? '—' }}</dd>
                                        </div>
                                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Service') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['docker_service'] ?? '—' }}</dd>
                                        </div>
                                    </dl>

                                    @if ($dockerContainers->isNotEmpty())
                                        <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white">
                                            <div class="border-b border-brand-ink/10 px-4 py-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Containers') }}</p>
                                            </div>
                                            <div class="overflow-x-auto">
                                                <table class="min-w-full divide-y divide-brand-ink/10 text-left">
                                                    <thead class="bg-brand-sand/30">
                                                        <tr>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Name') }}</th>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Service') }}</th>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Hostname') }}</th>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('IP') }}</th>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('State') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-brand-ink/8 bg-white">
                                                        @foreach ($dockerContainers as $container)
                                                            <tr>
                                                                <td class="px-4 py-3 font-mono text-sm text-brand-ink">{{ $container['name'] ?? '—' }}</td>
                                                                <td class="px-4 py-3 font-mono text-sm text-brand-moss">{{ $container['service'] ?? '—' }}</td>
                                                                <td class="px-4 py-3 font-mono text-sm text-brand-moss">{{ $container['orb_hostname'] ?? $container['hostname'] ?? '—' }}</td>
                                                                <td class="px-4 py-3 font-mono text-sm text-brand-moss">{{ $container['ipv4'] ?? '—' }}</td>
                                                                <td class="px-4 py-3 font-mono text-sm text-brand-moss">{{ $container['state'] ?? '—' }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        @else
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Namespace') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $kubernetesRuntime['namespace'] ?? __('default') }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Manifest') }}</dt>
                                    <dd class="mt-2 text-sm text-brand-ink">{{ isset($kubernetesRuntime['manifest_yaml']) ? __('Generated') : __('Not generated yet') }}</dd>
                                </div>
                                @if (! empty($kubernetesRuntime['workspace_path']))
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Local workspace') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $kubernetesRuntime['workspace_path'] }}</dd>
                                    </div>
                                @endif
                            </dl>
                        @endif

                        @if ($site->usesLocalDockerHostRuntime())
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 space-y-4">
                                <div>
                                    <h4 class="text-sm font-semibold text-brand-ink">{{ __('Runtime controls') }}</h4>
                                    <p class="mt-1 text-sm text-brand-moss">{{ __('Manage the local runtime backing this site directly from here.') }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="runRuntimeAction('rebuild')" class="rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">{{ __('Rebuild') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('start')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Start') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('stop')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Stop') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('restart')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Restart') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('inspect')" class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-800 hover:bg-sky-100">{{ __('Refresh details') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('errors')" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">{{ __('Errors') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('status')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Status') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('logs')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Logs') }}</button>
                                    <button type="button" wire:click="openConfirmActionModal('runRuntimeAction', ['destroy'], @js(__('Destroy runtime')), @js(__('Destroy the managed local runtime artifacts and containers for this site?')), @js(__('Destroy runtime')), true)" class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">{{ __('Destroy') }}</button>
                                </div>

                                @if ($runtimeErrorConsole)
                                    @include('livewire.partials.deployment-activity-console', [
                                        'title' => __('Runtime errors'),
                                        'meta' => $runtimeErrorConsole['meta'],
                                        'transcript' => $runtimeErrorConsole['transcript'],
                                        'maxHeight' => '20rem',
                                    ])
                                @endif

                                @if ($runtimeOperationConsoles->isNotEmpty())
                                    <div class="space-y-3">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Recent runtime operations') }}</p>
                                        @foreach ($runtimeOperationConsoles as $runtimeConsole)
                                            @include('livewire.partials.deployment-activity-console', [
                                                'title' => $runtimeConsole['title'],
                                                'meta' => $runtimeConsole['meta'],
                                                'transcript' => $runtimeConsole['transcript'],
                                                'maxHeight' => '18rem',
                                            ])
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif
                    </section>
                </x-server-workspace-tab-panel>
            @endif

            {{-- Logs panel ------------------------------------------------------------------------- --}}
            <x-server-workspace-tab-panel id="site-panel-logs" labelled-by="site-tab-logs" :hidden="$activeTab !== 'logs'">
                <section class="dply-card overflow-hidden p-6 sm:p-8">
                    <livewire:sites.site-log-viewer :server="$server" :site="$site" wire:key="site-log-show-{{ $site->id }}" />
                </section>
            </x-server-workspace-tab-panel>

            {{-- SSL panel -------------------------------------------------------------------------- --}}
            @if ($showSslTab)
                <x-server-workspace-tab-panel id="site-panel-ssl" labelled-by="site-tab-ssl" :hidden="$activeTab !== 'ssl'">
                    <section class="dply-card overflow-hidden p-6 sm:p-8 space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('Preview & SSL') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Preview hostname reachability and the latest certificate state for this site.') }}</p>
                            </div>
                            <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">
                                {{ $site->currentSslSummary() }}
                            </span>
                        </div>

                        <dl class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Preview hostname') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $previewDomain?->hostname ?? __('No preview domain') }}</dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Preview DNS') }}</dt>
                                <dd class="mt-2 text-sm text-brand-ink">{{ $previewDomain?->dns_status ?? __('Not configured') }}</dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Latest certificate') }}</dt>
                                <dd class="mt-2 text-sm text-brand-ink">
                                    @if ($latestCertificate)
                                        {{ ucfirst($latestCertificate->provider_type) }} · {{ $latestCertificate->status }}
                                    @else
                                        {{ __('No certificates requested') }}
                                    @endif
                                </dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Certificate scope') }}</dt>
                                <dd class="mt-2 text-sm text-brand-ink">{{ $latestCertificate ? ucfirst($latestCertificate->scope_type) : __('—') }}</dd>
                            </div>
                            @if ($latestCertificate)
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Certificate domains') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ implode(', ', $latestCertificate->domainHostnames()) }}</dd>
                                </div>
                                @if (! empty($latestCertificate->last_output))
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Latest certificate output') }}</dt>
                                        <dd class="mt-2 whitespace-pre-wrap break-words font-mono text-xs text-brand-ink">{{ \Illuminate\Support\Str::limit($latestCertificate->last_output, 800) }}</dd>
                                    </div>
                                @endif
                            @endif
                        </dl>

                        @if ($latestCertificate && in_array($latestCertificate->status, [
                            \App\Models\SiteCertificate::STATUS_FAILED,
                            \App\Models\SiteCertificate::STATUS_PENDING,
                            \App\Models\SiteCertificate::STATUS_ISSUED,
                        ], true))
                            <div class="flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    wire:click="retryCertificate('{{ $latestCertificate->id }}')"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center justify-center rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                                >
                                    <span wire:loading.remove wire:target="retryCertificate('{{ $latestCertificate->id }}')">{{ __('Retry certificate') }}</span>
                                    <span wire:loading wire:target="retryCertificate('{{ $latestCertificate->id }}')">{{ __('Retrying…') }}</span>
                                </button>
                                <a
                                    href="{{ route('sites.settings', [$server, $site, 'section' => 'certificates']) }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    {{ __('Open certificate settings') }}
                                </a>
                            </div>
                        @endif
                    </section>
                </x-server-workspace-tab-panel>
            @endif
            @endif
        </div>

        <x-slot name="modals">
            @include('livewire.partials.confirm-action-modal')
        </x-slot>
    </div>
</div>
