@php
    $functionsHost = $server->hostCapabilities()->supportsFunctionDeploy();
    $supportsMachinePhp = $server->hostCapabilities()->supportsMachinePhpManagement();
    $supportsNginxProvisioning = $server->hostCapabilities()->supportsNginxProvisioning();
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
        ['id' => 'deployment-log', 'label' => __('Queue'), 'icon' => 'heroicon-o-code-bracket'],
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
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex justify-between items-center flex-wrap gap-2">
            <div>
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ $site->name }}</h2>
                <p class="text-sm text-slate-500">
                    {{ $server->name }} · {{ $site->type->label() }}
                    · {{ $server->providerDisplayLabel() }}
                    @if ($site->workspace)
                        · {{ __('Project:') }}
                        <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="font-medium text-slate-700 hover:text-slate-900">
                            {{ $site->workspace->name }}
                        </a>
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-4">
                @if ($readyForWorkspace && $site->workspace)
                    <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 text-slate-600 hover:text-slate-900 text-sm font-medium">
                        {{ __('Project') }}
                    </a>
                    <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 text-slate-600 hover:text-slate-900 text-sm font-medium">
                        {{ __('Project delivery') }}
                    </a>
                @endif
                @if ($readyForWorkspace)
                    <a href="{{ route('sites.insights', [$server, $site]) }}" wire:navigate class="inline-flex items-center gap-1.5 text-slate-600 hover:text-slate-900 text-sm font-medium">
                        {{ __('Insights') }}
                        @if ($openSiteInsightsCount > 0)
                            <span class="inline-flex min-w-[1.25rem] justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[11px] font-semibold leading-none text-white" title="{{ trans_choice(':count open finding|:count open findings', $openSiteInsightsCount, ['count' => $openSiteInsightsCount]) }}">{{ $openSiteInsightsCount }}</span>
                        @endif
                    </a>
                @endif
                <a href="{{ route('servers.show', $server) }}" class="text-slate-500 hover:text-slate-700 text-sm">← Server</a>
            </div>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($flash_success)
                <div class="p-4 rounded-md bg-green-50 text-green-800">{{ $flash_success }}</div>
            @endif
            @if ($flash_error)
                <div class="p-4 rounded-md bg-red-50 text-red-800">{{ $flash_error }}</div>
            @endif

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

            @if (! $readyForWorkspace)
                <div class="mx-auto max-w-5xl space-y-6" wire:poll.5s="pollProvisioningStatus">
                    <div class="overflow-hidden rounded-[1.75rem] border {{ $provisioningState === 'failed' ? 'border-red-200 bg-red-50/40' : 'border-slate-200 bg-white' }} shadow-sm">
                        <div class="grid gap-6 p-5 sm:p-7 lg:grid-cols-[minmax(0,1.55fr)_20rem]">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.28em] {{ $provisioningState === 'failed' ? 'text-red-700/90' : 'text-slate-500' }}">
                                    {{ __('Site status') }}
                                </p>
                                <div class="mt-5 rounded-[1.5rem] border {{ $provisioningState === 'failed' ? 'border-red-200 bg-white/90' : 'border-slate-200 bg-slate-50/70' }} px-5 py-5 sm:px-6">
                                    <div class="flex items-start gap-4 sm:gap-5">
                                    <div class="mt-0.5 flex size-14 shrink-0 self-start items-center justify-center rounded-2xl {{ $provisioningState === 'failed' ? 'bg-red-100 text-red-700 ring-1 ring-red-200' : 'bg-blue-50 text-blue-700 ring-1 ring-blue-200' }}">
                                        @if ($provisioningState === 'failed')
                                            <x-heroicon-o-exclamation-triangle class="h-7 w-7" />
                                        @else
                                            <x-heroicon-o-arrow-path class="h-7 w-7" />
                                        @endif
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="text-3xl font-semibold tracking-tight text-slate-950 sm:text-[1.9rem]">
                                            @if ($provisioningState === 'failed')
                                                {{ __('Provisioning failed') }}
                                            @else
                                                {{ __('Installing your site') }}
                                            @endif
                                        </h3>
                                        <p class="mt-2 inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $provisioningState === 'failed' ? 'bg-red-100 text-red-800 ring-1 ring-red-200' : 'bg-blue-100 text-blue-800 ring-1 ring-blue-200' }}">
                                            {{ $statusSteps[$provisioningState] ?? str_replace('_', ' ', $provisioningState) }}
                                        </p>
                                        <p class="mt-4 max-w-2xl text-sm leading-6 text-slate-600">
                                            @if ($provisioningError)
                                                {{ __('Dply hit a server-level setup problem while finishing installation. Fix the underlying access issue, then retry from this page.') }}
                                            @else
                                                {{ __('Dply is writing the web server config, attaching your temporary testing URL, and checking which hostname becomes reachable first. As soon as either the testing URL or the real domain responds, the full site workspace will appear here.') }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                </div>

                                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                                    @if ($targetUrl)
                                        <div class="rounded-2xl border border-slate-200 bg-white/85 px-4 py-4 shadow-sm">
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Testing URL') }}</p>
                                            <p class="mt-3 break-all font-mono text-sm text-slate-900">{{ $targetUrl }}</p>
                                            <p class="mt-2 text-xs leading-5 text-slate-500">{{ __('Use this first while the customer domain catches up.') }}</p>
                                        </div>
                                    @endif

                                    <div class="rounded-2xl border border-slate-200 bg-white/85 px-4 py-4 shadow-sm">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Install summary') }}</p>
                                        <dl class="mt-3 space-y-3 text-sm">
                                            <div class="flex items-start justify-between gap-3">
                                                <dt class="text-slate-500">{{ __('Primary domain') }}</dt>
                                                <dd class="max-w-[16rem] break-all text-right font-mono text-slate-900">{{ optional($site->primaryDomain())->hostname ?? '—' }}</dd>
                                            </div>
                                            <div class="flex items-start justify-between gap-3">
                                                <dt class="text-slate-500">{{ __('Web server') }}</dt>
                                                <dd class="font-medium capitalize text-slate-900">{{ $site->webserver() }}</dd>
                                            </div>
                                            <div class="flex items-start justify-between gap-3">
                                                <dt class="text-slate-500">{{ __('Current step') }}</dt>
                                                <dd class="font-medium text-slate-900">{{ $statusSteps[$provisioningState] ?? str_replace('_', ' ', $provisioningState) }}</dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>

                                @if ($provisioningError)
                                    <div class="mt-6 rounded-2xl border border-red-200 bg-white/90 p-4 shadow-sm">
                                        <div class="flex items-start justify-between gap-4">
                                            <div class="min-w-0">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-red-700">{{ __('Latest error') }}</p>
                                                <p class="mt-3 break-all font-mono text-xs leading-6 text-slate-700">{{ $provisioningError }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if ($provisioningLog->isNotEmpty())
                                    <div class="mt-6">
                                        @include('livewire.partials.deployment-activity-console', [
                                            'title' => __('Install activity'),
                                            'meta' => ($statusSteps[$provisioningState] ?? str_replace('_', ' ', $provisioningState)).' · '.__('last :count entries', ['count' => min(8, $provisioningLog->count())]),
                                            'transcript' => $provisioningTranscript,
                                        ])
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-col justify-between gap-4 rounded-[1.5rem] border border-slate-200 bg-slate-50/70 p-5 shadow-sm">
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Current step') }}</p>
                                    <p class="mt-3 text-xl font-semibold tracking-tight text-slate-950">{{ $statusSteps[$provisioningState] ?? str_replace('_', ' ', $provisioningState) }}</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-500">
                                        @if ($provisioningState === 'failed')
                                            {{ __('This install is paused until the server access issue is fixed.') }}
                                        @else
                                            {{ __('This page updates live as the installer moves through each step.') }}
                                        @endif
                                    </p>
                                </div>

                                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Completion rule') }}</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('The site is considered ready as soon as either the testing URL or the real domain responds.') }}</p>
                                </div>

                                @if ($provisioningState === 'failed')
                                    <div>
                                        <button
                                            type="button"
                                            wire:click="retryProvisioning"
                                            wire:loading.attr="disabled"
                                            class="inline-flex w-full items-center justify-center rounded-2xl bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="retryProvisioning">{{ __('Retry provisioning') }}</span>
                                            <span wire:loading wire:target="retryProvisioning">{{ __('Retrying...') }}</span>
                                        </button>
                                        <p class="mt-3 text-xs leading-5 text-slate-500">{{ __('Use this after fixing root SSH access or passwordless sudo on the server.') }}</p>
                                    </div>
                                @endif

                                <div class="border-t border-slate-200 pt-4">
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('cancelProvisioning', [], @js(__('Cancel provisioning')), @js(__('Cancel this site setup, delete the generated testing DNS record, remove any created web server config from the server, and return to add site?')), @js(__('Cancel provisioning')), true)"
                                        class="inline-flex w-full items-center justify-center rounded-2xl border border-red-200 bg-white px-4 py-3 text-sm font-medium text-red-700 shadow-sm transition hover:bg-red-50"
                                    >
                                        {{ __('Cancel provisioning') }}
                                    </button>
                                    <p class="mt-3 text-xs leading-5 text-slate-500">{{ __('This removes the temporary DNS record, cleans up generated server config, deletes the pending site, and returns you to site creation.') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
                    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">{{ __('Provisioning steps') }}</h3>
                                <p class="mt-1 text-sm text-slate-500">{{ __('A compact install timeline showing what is done, what is running, and what comes next.') }}</p>
                            </div>
                            <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                {{ max(1, $currentStepIndex + 1) }} / {{ count($statusSteps) }}
                            </div>
                        </div>
                        <div class="mt-6 space-y-4">
                            @foreach ($statusSteps as $key => $label)
                                @php
                                    $loopIndex = array_search($key, $stepKeys, true);
                                    $isDone = $loopIndex !== false && $loopIndex < $currentStepIndex;
                                    $isCurrent = $key === $provisioningState;
                                @endphp
                                <div class="flex gap-4 rounded-2xl px-1 py-1">
                                    <div class="flex flex-col items-center">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-full text-sm font-semibold shadow-sm {{ $isCurrent ? 'bg-slate-900 text-white ring-4 ring-slate-100' : ($isDone ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-500') }}">
                                            {{ $isDone ? '✓' : $loop->iteration }}
                                        </div>
                                        @if (! $loop->last)
                                            <div class="mt-2 h-12 w-px {{ $isDone ? 'bg-emerald-200' : ($isCurrent ? 'bg-slate-300' : 'bg-slate-200') }}"></div>
                                        @endif
                                    </div>
                                    <div class="pb-4 pt-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-medium text-slate-900">{{ $label }}</p>
                                            @if ($isCurrent)
                                                <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-700">{{ __('Live') }}</span>
                                            @elseif ($isDone)
                                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">{{ __('Done') }}</span>
                                            @endif
                                        </div>
                                        @if ($isCurrent)
                                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('This is the active install step right now.') }}</p>
                                        @elseif ($isDone)
                                            <p class="mt-1 text-sm leading-6 text-emerald-700">{{ __('Completed successfully.') }}</p>
                                        @else
                                            <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('This will run automatically once the earlier steps finish.') }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">{{ __('DNS and hostname readiness') }}</h3>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Dply keeps checking both URLs and moves on as soon as one of them becomes reachable.') }}</p>
                            </div>
                            <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                {{ __('Either one can win') }}
                            </div>
                        </div>

                        @if ($testingHostname !== '')
                            <div class="mt-5 rounded-2xl border border-emerald-200 bg-[linear-gradient(180deg,#f0fdf4_0%,#ffffff_100%)] p-4 shadow-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-700">{{ __('Temporary testing hostname') }}</p>
                                        <p class="mt-2 break-all font-mono text-sm text-emerald-950">{{ $testingHostname }}</p>
                                    </div>
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">{{ __('Preferred during install') }}</span>
                                </div>
                            </div>
                        @elseif (($testingHostnameMeta['status'] ?? null) === 'failed')
                            <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                <p class="font-medium">{{ __('Temporary hostname could not be created') }}</p>
                                <p class="mt-1">{{ $testingHostnameMeta['error'] ?? __('Check the global DigitalOcean token and the configured testing domains.') }}</p>
                            </div>
                        @endif

                        <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Primary domain') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ optional($site->primaryDomain())->hostname ?? '—' }}</dd>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Web server') }}</dt>
                                <dd class="mt-2 text-sm font-medium capitalize text-slate-900">{{ $site->webserver() }}</dd>
                            </div>
                        </dl>

                        @if ($hostChecks->isNotEmpty())
                            <div class="mt-5 space-y-3">
                                @foreach ($hostChecks as $check)
                                    <div class="rounded-2xl border {{ ($check['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50/70' : 'border-amber-200 bg-amber-50/70' }} p-4">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p class="font-mono text-sm font-medium text-slate-900">{{ $check['hostname'] }}</p>
                                                <p class="mt-1 text-xs leading-5 {{ ($check['ok'] ?? false) ? 'text-emerald-800' : 'text-amber-900' }}">
                                                    {{ ($check['ok'] ?? false) ? __('This hostname is reachable and can finish the install.') : ($check['error'] ?? __('This hostname has not passed checks yet.')) }}
                                                </p>
                                            </div>
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ ($check['ok'] ?? false) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                                {{ ($check['ok'] ?? false) ? __('Ready') : __('Waiting') }}
                                            </span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @else
            <div class="space-y-6 lg:flex lg:items-start lg:gap-8 lg:space-y-0">
                <aside class="lg:sticky lg:top-8 lg:w-[17rem] lg:flex-none">
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-base font-semibold text-slate-900">{{ optional($site->primaryDomain())->hostname ?? $site->name }}</p>
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
                        <div><dt class="text-slate-500">Deploy strategy</dt><dd class="font-medium">{{ $site->deploy_strategy }}</dd></div>
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

            <div id="logs" class="bg-white p-6 shadow-sm sm:rounded-lg space-y-3">
                <h3 class="font-medium text-slate-900">Webhook delivery log</h3>
                <p class="text-sm text-slate-600">Recent inbound deploy webhook attempts (signature checks, IP allow list, etc.).</p>
                @if ($site->webhookDeliveryLogs->isEmpty())
                    <p class="text-sm text-slate-500">No deliveries recorded yet.</p>
                @else
                    <ul class="text-xs font-mono space-y-1 border border-slate-100 rounded-md divide-y divide-slate-100">
                        @foreach ($site->webhookDeliveryLogs as $log)
                            <li class="px-3 py-2 flex flex-wrap gap-2 justify-between">
                                <span>{{ $log->created_at->diffForHumans() }}</span>
                                <span class="text-slate-600">{{ $log->request_ip ?? '—' }}</span>
                                <span class="text-slate-800">{{ $log->http_status }} · {{ $log->outcome }}</span>
                                @if ($log->detail)
                                    <span class="text-slate-500 w-full">{{ $log->detail }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
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
