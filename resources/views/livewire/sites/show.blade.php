@php
    $functionsHost = $server->hostCapabilities()->supportsFunctionDeploy();
    $supportsMachinePhp = $server->hostCapabilities()->supportsMachinePhpManagement();
    $supportsNginxProvisioning = $server->hostCapabilities()->supportsNginxProvisioning();
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
                    <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                        {{ __('Open project') }}
                    </a>
                @endif
                @if ($showWebserverConfigEditor)
                    <a href="{{ route('sites.webserver-config', [$server, $site]) }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                        {{ __('Web server config') }}
                    </a>
                @endif
                @if ($readyForWorkspace)
                    <a href="{{ route('sites.insights', [$server, $site]) }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                        {{ __('Insights') }}
                        @if ($openSiteInsightsCount > 0)
                            <span class="inline-flex min-w-[1.25rem] justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[11px] font-semibold leading-none text-white" title="{{ trans_choice(':count open finding|:count open findings', $openSiteInsightsCount, ['count' => $openSiteInsightsCount]) }}">{{ $openSiteInsightsCount }}</span>
                        @endif
                    </a>
                    <a href="{{ route('sites.monitor', [$server, $site]) }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                        {{ __('Monitor') }}
                    </a>
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
            <div class="space-y-6 lg:flex lg:items-start lg:gap-8 lg:space-y-0">
                <aside class="lg:sticky lg:top-8 lg:w-[17rem] lg:flex-none">
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-base font-semibold text-slate-900">{{ optional($site->primaryDomain())->hostname ?? $site->name }}</p>
                                        @if ($site->isSuspended())
                                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-900">{{ __('Suspended') }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm text-slate-500">{{ $server->ip_address ?? __('No IP recorded') }}</p>
                                </div>
                                @if ($site->visitUrl())
                                    <a href="{{ $site->visitUrl() }}" target="_blank" rel="noreferrer" class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                                    </a>
                                @endif
                            </div>
                        </div>
                        <nav class="p-4">
                            <ul class="space-y-1.5">
                                @foreach ($sidebarItems as $item)
                                    @php
                                        $isExternal = $item['external'] ?? false;
                                        $href = $item['href'] ?? '#'.$item['id'];
                                    @endphp
                                    <li>
                                        <a
                                            href="{{ $href }}"
                                            @if ($isExternal) target="_blank" rel="noreferrer" @endif
                                            class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900"
                                        >
                                            <x-dynamic-component :component="$item['icon']" class="h-4 w-4 shrink-0" />
                                            <span>{{ $item['label'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </nav>
                    </div>
                </aside>

                <main class="min-w-0 space-y-6 lg:flex-1">
            <div id="general" class="bg-white p-6 shadow-sm sm:rounded-lg">
                <h3 class="font-medium text-slate-900 mb-3">Status</h3>
                @if ($site->workspace)
                    <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        <p class="font-medium text-slate-900">{{ __('Project context') }}</p>
                        <p class="mt-1">
                            {{ __('This site rolls up into the :project project.', ['project' => $site->workspace->name]) }}
                            <a href="{{ route('projects.operations', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open project operations') }}</a>
                            {{ __('for grouped health and activity, or') }}
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('open project delivery') }}</a>
                            {{ __('to coordinate releases and shared variables.') }}
                        </p>
                    </div>
                @endif
                <p class="text-sm text-slate-600 mb-3">
                    {{ __('Show this site on a public') }}
                    <a href="{{ route('status-pages.index') }}" class="text-slate-800 font-medium hover:underline">{{ __('status page') }}</a>.
                </p>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-slate-500">Provisioning</dt><dd class="font-medium capitalize">{{ $site->statusLabel() }}</dd></div>
                    @if ($site->isSuspended())
                        <div><dt class="text-slate-500">{{ __('Public traffic') }}</dt><dd class="font-medium text-amber-800">{{ __('Suspended — visitors see the suspended page') }}</dd></div>
                    @endif
                    <div><dt class="text-slate-500">Provisioning step</dt><dd class="font-medium capitalize">{{ str_replace('_', ' ', $site->provisioningState() ?? 'queued') }}</dd></div>
                    <div><dt class="text-slate-500">Contract revision</dt><dd class="font-mono text-xs break-all">{{ \Illuminate\Support\Str::limit((string) ($foundationStatus['current_runtime_revision'] ?? '—'), 20) }}</dd></div>
                    <div><dt class="text-slate-500">Runtime drift</dt><dd class="font-medium {{ ($foundationStatus['runtime_drifted'] ?? false) ? 'text-amber-700' : 'text-emerald-700' }}">{{ ($foundationStatus['runtime_drifted'] ?? false) ? __('Detected') : __('In sync') }}</dd></div>
                    @if ($site->usesDockerRuntime())
                        <div><dt class="text-slate-500">Published URL</dt><dd class="font-mono text-xs break-all">{{ $runtimePublication['url'] ?? ($runtimePublication['hostname'] ?? 'Not published yet') }}</dd></div>
                        <div><dt class="text-slate-500">Container service</dt><dd class="font-medium">{{ $runtimePublication['docker_service'] ?? 'Not recorded yet' }}</dd></div>
                        <div><dt class="text-slate-500">Working directory</dt><dd class="font-mono text-xs break-all">{{ $site->effectiveRepositoryPath() }}</dd></div>
                        <div><dt class="text-slate-500">Published path</dt><dd class="font-mono text-xs break-all">{{ $site->document_root }}</dd></div>
                        <div><dt class="text-slate-500">Runtime mode</dt><dd class="font-medium">{{ ucfirst((string) ($runtimeTarget['mode'] ?? 'docker')) }}</dd></div>
                    @else
                        <div><dt class="text-slate-500">SSL</dt><dd class="font-medium capitalize">{{ $site->ssl_status }}</dd></div>
                        <div><dt class="text-slate-500">Document root (configured)</dt><dd class="font-mono text-xs break-all">{{ $site->document_root }}</dd></div>
                        <div><dt class="text-slate-500">Deploy path</dt><dd class="font-mono text-xs break-all">{{ $site->effectiveRepositoryPath() }}</dd></div>
                        <div><dt class="text-slate-500">Web root</dt><dd class="font-mono text-xs break-all">{{ $site->effectiveDocumentRoot() }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('Zero downtime') }}</dt><dd class="font-medium">{{ $site->deploy_strategy === 'atomic' ? __('Enabled') : __('Disabled') }}</dd></div>
                        @if ($site->runtimeKey())
                            <div>
                                <dt class="text-slate-500">{{ __('Runtime') }}</dt>
                                <dd class="font-medium">
                                    <span class="capitalize">{{ $site->runtimeKey() }}</span>@if ($site->runtimeVersion())
                                        <span class="font-mono text-slate-500"> · {{ $site->runtimeVersion() }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endif
                        @if ($site->internal_port)
                            <div>
                                <dt class="text-slate-500">{{ __('Internal port') }}</dt>
                                <dd class="font-mono text-xs">127.0.0.1:{{ $site->internal_port }}</dd>
                            </div>
                        @endif
                    @endif
                    @if (!empty($site->meta['site_health_last_check_at']))
                        <div><dt class="text-slate-500">URL health (scheduler)</dt><dd class="font-medium">
                            @if (!empty($site->meta['site_health_last_ok']))
                                <span class="text-green-700">OK</span>
                            @else
                                <span class="text-red-700">Failed</span>
                            @endif
                            <span class="text-slate-500 text-xs font-normal"> · {{ $site->meta['site_health_last_check_at'] ?? '' }}</span>
                        </dd></div>
                    @endif
                </dl>

                <div class="mt-6 grid gap-4 lg:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <h4 class="text-sm font-semibold text-slate-900">{{ __('Launch preflight') }}</h4>
                        <p class="mt-1 text-sm text-slate-600">{{ __('Shared deployment checks for config, publication, and attached resources.') }}</p>
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
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <h4 class="text-sm font-semibold text-slate-900">{{ __('Attached resources') }}</h4>
                        <p class="mt-1 text-sm text-slate-600">{{ __('What this site expects around the app runtime, even when provisioning stays attach-only.') }}</p>
                        <div class="mt-3 space-y-2">
                            @foreach ($resourceBindings as $binding)
                                @include('livewire.sites.partials.resource-binding-row', [
                                    'binding' => $binding,
                                    'configuredClass' => 'bg-emerald-100 text-emerald-700',
                                ])
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <h3 class="font-medium text-slate-900 mb-3">{{ $site->usesDockerRuntime() ? __('Published hostname') : __('Testing URL') }}</h3>

                @if ($testingHostname !== '')
                    <div class="rounded-lg border border-sky-200 bg-sky-50 p-4">
                        <p class="text-sm font-medium text-sky-900">
                            {{ $site->isReadyForTraffic() ? __('Temporary hostname ready') : __('Temporary hostname assigned') }}
                        </p>
                        <p class="mt-2 break-all font-mono text-sm text-sky-950">{{ $testingHostname }}</p>
                        <p class="mt-2 text-sm text-sky-900">
                            @if ($site->isReadyForTraffic())
                                {{ $site->usesDockerRuntime() ? __('Use this hostname to verify the container app before adding custom domains.') : __('Use this URL to test the site before the customer domain points here.') }}
                            @else
                                {{ __('Dply will keep checking this hostname and mark the site ready once it starts responding.') }}
                            @endif
                        </p>
                    </div>
                @elseif (($testingHostnameMeta['status'] ?? null) === 'failed')
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        <p class="font-medium">{{ __('Temporary hostname could not be created') }}</p>
                        <p class="mt-1">{{ $testingHostnameMeta['error'] ?? __('Check the global DigitalOcean token and the configured testing domains.') }}</p>
                    </div>
                @else
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        {{ __('No temporary testing hostname is configured for this site yet.') }}
                    </div>
                @endif

                @if (! empty($site->meta['ssl_last_requested_domains']))
                    <p class="mt-3 text-xs text-slate-500">
                        {{ __('SSL currently targets: :domains', ['domains' => implode(', ', (array) $site->meta['ssl_last_requested_domains'])]) }}
                    </p>
                @endif
            </div>

                <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">{{ $site->usesDockerRuntime() ? __('App workspace') : __('Site settings') }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ $site->usesDockerRuntime() ? __('Runtime, deployments, environment, networking, automation, and destructive actions now live in the dedicated app workspace.') : __('Routing, certificates, deploy settings, runtime, environment, webhooks, and destructive actions now live in the dedicated site settings workspace.') }}</p>
                        </div>
                        <a href="{{ route('sites.settings', ['server' => $server, 'site' => $site, 'section' => $site->usesDockerRuntime() ? 'runtime' : 'routing']) }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 shadow-sm transition-colors hover:bg-slate-50">
                            {{ $site->usesDockerRuntime() ? __('Open runtime workspace') : __('Open routing settings') }}
                        </a>
                    </div>
                </div>

                <div id="repository" class="bg-white p-6 shadow-sm sm:rounded-lg space-y-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="font-medium text-slate-900">{{ __('Deploy operations') }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ __('Use the dedicated settings workspace for repository and runtime configuration. This page keeps the deploy actions and output close together.') }}</p>
                        </div>
                        <a href="{{ route('sites.settings', ['server' => $server, 'site' => $site, 'section' => 'deploy']) }}" wire:navigate class="text-sm font-medium text-slate-700 underline">
                            {{ __('Open deploy settings') }}
                        </a>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-2">
                        <button type="button" wire:click="deployNow" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800 disabled:opacity-50">
                            <span wire:loading.remove wire:target="deployNow">Deploy now (sync)</span>
                            <span wire:loading wire:target="deployNow" class="inline-flex items-center gap-2">
                                <x-spinner variant="white" size="sm" />
                                Deploying…
                            </span>
                        </button>
                        <button type="button" wire:click="queueDeploy" class="px-4 py-2 border border-slate-300 rounded-md text-sm text-slate-700 bg-white hover:bg-slate-50">Queue deploy (queue worker)</button>
                    </div>
                </div>

                @if ($site->deploy_strategy === 'atomic' && $supportsReleaseRollback)
                    <div id="commits" class="bg-white p-6 shadow-sm sm:rounded-lg space-y-3">
                        <h3 class="font-medium text-slate-900">Releases &amp; rollback</h3>
                        @if ($site->releases->isEmpty())
                            <p class="text-sm text-slate-500">No recorded releases yet. Deploy once with atomic strategy.</p>
                        @else
                            <ul class="text-sm space-y-2">
                                @foreach ($site->releases as $rel)
                                    <li class="flex justify-between items-center border border-slate-100 rounded px-3 py-2">
                                        <div>
                                            <span class="font-mono text-xs">{{ $rel->folder }}</span>
                                            @if ($rel->is_active)<span class="text-green-600 text-xs ml-2">active</span>@endif
                                            @if ($rel->git_sha)<div class="font-mono text-xs text-slate-500">{{ $rel->git_sha }}</div>@endif
                                        </div>
                                        @if (! $rel->is_active)
                                            <button type="button" wire:click="confirmRollbackRelease('{{ $rel->id }}')" class="text-slate-800 text-xs hover:underline">Rollback</button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

            @if ($site->deploy_strategy === 'atomic' && $supportsReleaseRollback)
                <div id="commits" class="bg-white p-6 shadow-sm sm:rounded-lg space-y-3">
                    <h3 class="font-medium text-slate-900">Releases &amp; rollback</h3>
                    @if ($site->releases->isEmpty())
                        <p class="text-sm text-slate-500">No recorded releases yet. Deploy once with atomic strategy.</p>
                    @else
                        <ul class="text-sm space-y-2">
                            @foreach ($site->releases as $rel)
                                <li class="flex justify-between items-center border border-slate-100 rounded px-3 py-2">
                                    <div>
                                        <span class="font-mono text-xs">{{ $rel->folder }}</span>
                                        @if ($rel->is_active)<span class="text-green-600 text-xs ml-2">active</span>@endif
                                        @if ($rel->git_sha)<div class="font-mono text-xs text-slate-500">{{ $rel->git_sha }}</div>@endif
                                    </div>
                                    @if (! $rel->is_active)
                                        <button type="button" wire:click="confirmRollbackRelease('{{ $rel->id }}')" class="text-slate-800 text-xs hover:underline">Rollback</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div id="logs" class="bg-white p-6 shadow-sm sm:rounded-lg">
                <livewire:sites.site-log-viewer :server="$server" :site="$site" wire:key="site-log-show-{{ $site->id }}" />
            </div>

            @if (! $site->usesDockerRuntime() && ($previewDomain || $site->certificates->isNotEmpty()))
                <div class="bg-white p-6 shadow-sm sm:rounded-lg space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-slate-900">{{ __('Preview & SSL') }}</h3>
                            <p class="text-sm text-slate-600">{{ __('Preview hostname reachability and the latest certificate state for this site.') }}</p>
                        </div>
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-600">
                            {{ $site->currentSslSummary() }}
                        </span>
                    </div>

                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Preview hostname') }}</dt>
                            <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $previewDomain?->hostname ?? __('No preview domain') }}</dd>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Preview DNS') }}</dt>
                            <dd class="mt-2 text-sm text-slate-900">{{ $previewDomain?->dns_status ?? __('Not configured') }}</dd>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Latest certificate') }}</dt>
                            <dd class="mt-2 text-sm text-slate-900">
                                @if ($latestCertificate)
                                    {{ ucfirst($latestCertificate->provider_type) }} · {{ $latestCertificate->status }}
                                @else
                                    {{ __('No certificates requested') }}
                                @endif
                            </dd>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Certificate scope') }}</dt>
                            <dd class="mt-2 text-sm text-slate-900">{{ $latestCertificate ? ucfirst($latestCertificate->scope_type) : __('—') }}</dd>
                        </div>
                        @if ($latestCertificate)
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:col-span-2">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Certificate domains') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ implode(', ', $latestCertificate->domainHostnames()) }}</dd>
                            </div>
                            @if (! empty($latestCertificate->last_output))
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:col-span-2">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Latest certificate output') }}</dt>
                                    <dd class="mt-2 whitespace-pre-wrap break-words font-mono text-xs text-slate-900">{{ \Illuminate\Support\Str::limit($latestCertificate->last_output, 800) }}</dd>
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
                                class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="retryCertificate('{{ $latestCertificate->id }}')">{{ __('Retry certificate') }}</span>
                                <span wire:loading wire:target="retryCertificate('{{ $latestCertificate->id }}')">{{ __('Retrying...') }}</span>
                            </button>
                            <a
                                href="{{ route('sites.settings', [$server, $site, 'section' => 'certificates']) }}"
                                wire:navigate
                                class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
                            >
                                {{ __('Open certificate settings') }}
                            </a>
                        </div>
                    @endif
                </div>
            @endif

            @if ($site->usesFunctionsRuntime() || $site->usesDockerRuntime() || $site->usesKubernetesRuntime())
                <div class="bg-white p-6 shadow-sm sm:rounded-lg space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-slate-900">{{ __('Runtime target') }}</h3>
                            <p class="text-sm text-slate-600">{{ __('The latest managed deploy details for this runtime target.') }}</p>
                        </div>
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-600">
                            {{ $site->runtimeTargetLabel() }}
                        </span>
                    </div>

                    <dl class="grid gap-4 sm:grid-cols-3">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Platform') }}</dt>
                            <dd class="mt-2 text-sm text-slate-900">{{ ucfirst((string) ($runtimeTarget['platform'] ?? 'unknown')) }}</dd>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Mode') }}</dt>
                            <dd class="mt-2 text-sm text-slate-900">{{ ucfirst((string) ($runtimeTarget['mode'] ?? 'unknown')) }}</dd>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Status') }}</dt>
                            <dd class="mt-2 text-sm text-slate-900">{{ ucfirst(str_replace('_', ' ', (string) ($runtimeTarget['status'] ?? 'unknown'))) }}</dd>
                        </div>
                    </dl>

                    @if ($preflightChecks->isNotEmpty())
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <h4 class="text-sm font-semibold text-slate-900">{{ __('Deployment foundation') }}</h4>
                            <dl class="mt-3 grid gap-4 sm:grid-cols-3">
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Current revision') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-xs text-slate-900">{{ $foundationStatus['current_runtime_revision'] ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Last applied revision') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-xs text-slate-900">{{ $foundationStatus['last_applied_runtime_revision'] ?? __('Not applied yet') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Drift') }}</dt>
                                    <dd class="mt-2 text-sm {{ ($foundationStatus['runtime_drifted'] ?? false) ? 'text-amber-700' : 'text-emerald-700' }}">{{ ($foundationStatus['runtime_drifted'] ?? false) ? __('Detected') : __('In sync') }}</dd>
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
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Publication status') }}</dt>
                                <dd class="mt-2 text-sm text-slate-900">{{ ucfirst((string) ($runtimePublication['status'] ?? 'pending')) }}</dd>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Publication hostname') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $runtimePublication['hostname'] ?? '—' }}</dd>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Published URL') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $runtimePublication['url'] ?? '—' }}</dd>
                            </div>
                        </dl>
                    @endif

                    @if ($site->usesFunctionsRuntime())
                        <dl class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Runtime') }}</dt>
                                <dd class="mt-2 font-mono text-sm text-slate-900">{{ $serverlessRuntime['runtime'] ?? '—' }}</dd>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Entrypoint') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $serverlessRuntime['entrypoint'] ?? '—' }}</dd>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Revision') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $serverlessRuntime['last_revision_id'] ?? __('Not deployed yet') }}</dd>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Latest artifact') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $serverlessRuntime['artifact_path'] ?? __('Not built yet') }}</dd>
                            </div>
                            @if (! empty($serverlessRuntime['function_arn']))
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:col-span-2">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Function ARN') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $serverlessRuntime['function_arn'] }}</dd>
                                </div>
                            @endif
                            @if (! empty($serverlessRuntime['function_url']))
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:col-span-2">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Function URL') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $serverlessRuntime['function_url'] }}</dd>
                                </div>
                            @endif
                            @if (! empty($serverlessRuntime['action_url']))
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:col-span-2">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Published action URL') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $serverlessRuntime['action_url'] }}</dd>
                                </div>
                            @endif
                        </dl>
                    @elseif ($site->usesDockerRuntime())
                        <dl class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Last generated compose file') }}</dt>
                                <dd class="mt-2 text-sm text-slate-900">{{ isset($dockerRuntime['compose_yaml']) ? __('Available') : __('Not generated yet') }}</dd>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Managed Dockerfile') }}</dt>
                                <dd class="mt-2 text-sm text-slate-900">{{ isset($dockerRuntime['dockerfile']) ? __('Available') : __('Not generated yet') }}</dd>
                            </div>
                            @if (! empty($dockerRuntime['workspace_path']))
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:col-span-2">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Local workspace') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $dockerRuntime['workspace_path'] }}</dd>
                                </div>
                            @endif
                            @if ($dockerContainers->isNotEmpty() || $runtimePublication !== [])
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:col-span-2 space-y-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Docker discovery') }}</dt>
                                            <dd class="mt-1 text-sm text-slate-600">{{ __('Saved from the live Docker runtime so hostname, IP, and container identity stay referenceable later.') }}</dd>
                                        </div>
                                        @if (! empty($dockerRuntimeDetails['collected_at']))
                                            <p class="font-mono text-[11px] text-slate-500">{{ __('Collected :time', ['time' => $dockerRuntimeDetails['collected_at']]) }}</p>
                                        @endif
                                    </div>

                                    <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Hostname') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $runtimePublication['hostname'] ?? '—' }}</dd>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Container IP') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $runtimePublication['container_ip'] ?? '—' }}</dd>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Container name') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $runtimePublication['container_name'] ?? '—' }}</dd>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Service') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $runtimePublication['docker_service'] ?? '—' }}</dd>
                                        </div>
                                    </dl>

                                    @if ($dockerContainers->isNotEmpty())
                                        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                                            <div class="border-b border-slate-200 px-4 py-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Containers') }}</p>
                                            </div>
                                            <div class="overflow-x-auto">
                                                <table class="min-w-full divide-y divide-slate-200 text-left">
                                                    <thead class="bg-slate-50">
                                                        <tr>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Name') }}</th>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Service') }}</th>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Hostname') }}</th>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('IP') }}</th>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('State') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-slate-200 bg-white">
                                                        @foreach ($dockerContainers as $container)
                                                            <tr>
                                                                <td class="px-4 py-3 font-mono text-sm text-slate-900">{{ $container['name'] ?? '—' }}</td>
                                                                <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ $container['service'] ?? '—' }}</td>
                                                                <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ $container['orb_hostname'] ?? $container['hostname'] ?? '—' }}</td>
                                                                <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ $container['ipv4'] ?? '—' }}</td>
                                                                <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ $container['state'] ?? '—' }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </dl>
                    @else
                        <dl class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Namespace') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $kubernetesRuntime['namespace'] ?? __('default') }}</dd>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Manifest') }}</dt>
                                <dd class="mt-2 text-sm text-slate-900">{{ isset($kubernetesRuntime['manifest_yaml']) ? __('Generated') : __('Not generated yet') }}</dd>
                            </div>
                            @if (! empty($kubernetesRuntime['workspace_path']))
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:col-span-2">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Local workspace') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $kubernetesRuntime['workspace_path'] }}</dd>
                                </div>
                            @endif
                        </dl>
                    @endif

                    @if ($site->usesLocalDockerHostRuntime())
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 space-y-4">
                            <div>
                                <h4 class="font-medium text-slate-900">{{ __('Runtime controls') }}</h4>
                                <p class="mt-1 text-sm text-slate-600">{{ __('Manage the real local runtime behind this site without going through the old SSH bridge.') }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="runRuntimeAction('rebuild')" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">{{ __('Rebuild') }}</button>
                                <button type="button" wire:click="runRuntimeAction('start')" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('Start') }}</button>
                                <button type="button" wire:click="runRuntimeAction('stop')" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('Stop') }}</button>
                                <button type="button" wire:click="runRuntimeAction('restart')" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('Restart') }}</button>
                                <button type="button" wire:click="runRuntimeAction('inspect')" class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-medium text-sky-700 hover:bg-sky-100">{{ __('Refresh Docker details') }}</button>
                                <button type="button" wire:click="runRuntimeAction('errors')" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-100">{{ __('Errors') }}</button>
                                <button type="button" wire:click="runRuntimeAction('status')" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('Status') }}</button>
                                <button type="button" wire:click="runRuntimeAction('logs')" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('Logs') }}</button>
                                <button type="button" wire:click="openConfirmActionModal('runRuntimeAction', ['destroy'], @js(__('Destroy runtime')), @js(__('Destroy the managed local runtime artifacts and containers for this site?')), @js(__('Destroy runtime')), true)" class="rounded-xl border border-red-200 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">{{ __('Destroy') }}</button>
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
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Recent runtime operations') }}</p>
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
                </div>
            @endif

            <div id="deployment-log" class="bg-white p-6 shadow-sm sm:rounded-lg space-y-3" wire:poll.10s>
                <h3 class="font-medium text-slate-900">Deployment log</h3>
                @if ($site->workspace)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        <p class="font-medium text-slate-900">{{ __('Project delivery context') }}</p>
                        <p class="mt-1">
                            {{ __('Use this log for site-specific output, then') }}
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('open project delivery') }}</a>
                            {{ __('to coordinate shared deploy batches, compare related site rollouts, and review project-level delivery notes for :project.', ['project' => $site->workspace->name]) }}
                        </p>
                    </div>
                @endif
                @if ($deploymentConsoles->isEmpty())
                    <p class="text-sm text-slate-500">No deployments yet.</p>
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

                </main>
            </div>
            @endif
        </div>

        <x-slot name="modals">
            @include('livewire.partials.confirm-action-modal')
        </x-slot>
    </div>
</div>
