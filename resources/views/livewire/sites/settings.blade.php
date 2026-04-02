@php
    $functionsHost = $server->hostCapabilities()->supportsFunctionDeploy();
    $supportsMachinePhp = $server->hostCapabilities()->supportsMachinePhpManagement();
    $supportsNginxProvisioning = $server->hostCapabilities()->supportsNginxProvisioning();
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
    ];
    $deployHookPhaseLabels = [
        \App\Models\SiteDeployHook::PHASE_BEFORE_CLONE => __('Before clone'),
        \App\Models\SiteDeployHook::PHASE_AFTER_CLONE => __('After clone'),
        \App\Models\SiteDeployHook::PHASE_AFTER_ACTIVATE => __('After activate'),
    ];
    $runtimeMode = $site->runtimeTargetMode();
    $runtimePlatform = $site->runtimeTargetPlatform();
    $runtimeFamily = $site->runtimeTargetFamily();
    $isContainerWorkspace = in_array($runtimeMode, ['docker', 'kubernetes', 'serverless'], true);
    $settingsSidebarItems = $isContainerWorkspace
        ? [
            ['id' => 'general', 'label' => __('Overview'), 'icon' => 'heroicon-o-home'],
            ['id' => 'runtime', 'label' => __('Runtime'), 'icon' => 'heroicon-o-cube-transparent'],
            ['id' => 'deploy', 'label' => __('Deployments'), 'icon' => 'heroicon-o-code-bracket-square'],
            ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line'],
            ['id' => 'routing', 'label' => __('Networking'), 'icon' => 'heroicon-o-globe-alt'],
            ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list'],
            ['id' => 'webhooks', 'label' => __('Automation'), 'icon' => 'heroicon-o-bolt'],
            ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-archive-box'],
        ]
        : [
            ['id' => 'general', 'label' => __('General'), 'icon' => 'heroicon-o-rectangle-stack'],
            ['id' => 'routing', 'label' => __('Routing'), 'icon' => 'heroicon-o-share'],
            ['id' => 'certificates', 'label' => __('Certificates'), 'icon' => 'heroicon-o-shield-check'],
            ['id' => 'deploy', 'label' => __('Deploy'), 'icon' => 'heroicon-o-code-bracket-square'],
            ['id' => 'runtime', 'label' => __('Runtime'), 'icon' => 'heroicon-o-cube-transparent'],
            ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line'],
            ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list'],
            ['id' => 'webhooks', 'label' => __('Webhooks'), 'icon' => 'heroicon-o-clipboard-document-list'],
            ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-archive-box'],
        ];
    $routingTabIcons = [
        'domains' => 'heroicon-o-globe-alt',
        'aliases' => 'heroicon-o-link',
        'redirects' => 'heroicon-o-arrow-uturn-right',
        'preview' => 'heroicon-o-sparkles',
        'tenants' => 'heroicon-o-building-office-2',
    ];
    $previewDomain = $site->primaryPreviewDomain();
    $activeCertificate = $site->certificates->firstWhere('status', \App\Models\SiteCertificate::STATUS_ACTIVE);
    $pendingCertificate = $activeCertificate
        ? null
        : $site->certificates->first(fn ($certificate) => in_array($certificate->status, [
            \App\Models\SiteCertificate::STATUS_PENDING,
            \App\Models\SiteCertificate::STATUS_ISSUED,
            \App\Models\SiteCertificate::STATUS_INSTALLING,
            \App\Models\SiteCertificate::STATUS_FAILED,
        ], true));
    $latestCertificate = $activeCertificate ?? $pendingCertificate ?? $site->certificates->first();
    $serverlessRuntime = $site->usesFunctionsRuntime() ? $site->serverlessConfig() : [];
    $dockerRuntime = $site->usesDockerRuntime() && is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [];
    $kubernetesRuntime = $site->usesKubernetesRuntime() && is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
    $runtimeTarget = $site->runtimeTarget();
    $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
    $foundationStatus = is_array($deploymentContract->status ?? null) ? $deploymentContract->status : [];
    $foundationSecrets = collect($deploymentContract->secretArrays() ?? [])->filter(fn ($entry) => is_array($entry))->values();
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
    $resourceBindings = collect($deploymentContract->resourceBindingArrays() ?? [])->filter(fn ($entry) => is_array($entry))->values();
    $preflightChecks = collect($deploymentPreflight['checks'] ?? [])->filter(fn ($entry) => is_array($entry))->values();
    $preflightErrors = collect($deploymentPreflight['errors'] ?? [])->filter(fn ($entry) => is_string($entry))->values();
    $preflightWarnings = collect($deploymentPreflight['warnings'] ?? [])->filter(fn ($entry) => is_string($entry))->values();
    $dockerRuntimeDetails = $site->usesDockerRuntime() && is_array($dockerRuntime['runtime_details'] ?? null) ? $dockerRuntime['runtime_details'] : [];
    $dockerContainers = collect($dockerRuntimeDetails['containers'] ?? [])->filter(fn ($entry) => is_array($entry))->values();
    $runtimeLogs = collect($runtimeTarget['logs'] ?? [])->filter(fn ($entry) => is_array($entry))->reverse()->values();
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
    $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
    $resourceNounLower = strtolower($resourceNoun);
    $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
    $workspacePrefix = match (true) {
        str_contains($runtimeFamily, 'edge') || str_contains($runtimePlatform, 'edge') => __('Edge'),
        in_array($runtimePlatform, ['aws', 'digitalocean'], true) => __('Cloud'),
        $runtimeMode === 'docker' => __('Container'),
        $runtimeMode === 'kubernetes' => __('Kubernetes'),
        $runtimeMode === 'serverless' => __('Function'),
        default => null,
    };
    $workspaceTitle = $workspacePrefix ? $workspacePrefix.' '.$resourceNounLower.' '.__('workspace') : $resourceNoun.' '.__('workspace');
    $workspaceDescription = $workspacePrefix
        ? __('Manage this :resource from one workspace tuned for its :prefix runtime path, with General as the default landing section.', ['resource' => strtolower($resourceNoun), 'prefix' => strtolower($workspacePrefix)])
        : __('Manage this :resource from one workspace with General as the default landing section.', ['resource' => strtolower($resourceNoun)]);
    $generalOverviewTitle = $runtimeMode === 'vm' ? __('Site domain') : __('Primary hostname');
    $generalOverviewDescription = $runtimeMode === 'vm'
        ? __('Update the primary domain and web directory for this site here. Changing the primary hostname updates the site record Dply uses for routing and future server automation.')
        : __('Update the primary hostname and working directory for this app here. Changing the primary hostname updates the routing and publication details Dply uses for future automation.');
    if ($runtimeMode === 'docker') {
        $workspaceDescription = __('Operate this container app from one workspace tuned for runtime operations, environment, deployments, and networking.');
    }
    $projectSettingsTitle = $resourceNoun.' '.__('project settings');
    $projectSettingsDescription = __('Choose which project this :resource belongs to for grouped resources, shared variables, operations, and coordinated delivery.', ['resource' => strtolower($resourceNoun)]);
    $detailsTitle = $resourceNoun.' '.__('details');
    $detailsDescription = __('Use this reference block for the stable :resource metadata operators usually need when checking ownership, age, and basic inventory.', ['resource' => strtolower($resourceNoun)]);
    $primaryHostnameLabel = $runtimeMode === 'vm' ? __('Root domain') : __('Primary hostname');
    $documentRootLabel = $runtimeMode === 'vm' ? __('Web directory') : __('Published path');
    $documentRootPlaceholder = $runtimeMode === 'vm' ? '/var/www/app/public' : '/var/www/app/public';
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
            ['label' => __('Deploy strategy'), 'value' => $site->deploy_strategy],
        ];
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <nav class="mb-6 text-sm text-slate-500" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="transition-colors hover:text-slate-900">{{ __('Dashboard') }}</a></li>
            <li class="text-slate-400" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="transition-colors hover:text-slate-900">{{ __('Servers') }}</a></li>
            <li class="text-slate-400" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.sites', $server) }}" wire:navigate class="transition-colors hover:text-slate-900">{{ $server->name }}</a></li>
            <li class="text-slate-400" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="transition-colors hover:text-slate-900">{{ $site->name }}</a></li>
            <li class="text-slate-400" aria-hidden="true">/</li>
            <li class="font-medium text-slate-900">{{ $workspaceTitle }}</li>
        </ol>
    </nav>

    <div class="space-y-6">
        @if ($flash_success)
            <div class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">{{ $flash_success }}</div>
        @endif
        @if ($flash_error)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">{{ $flash_error }}</div>
        @endif

        <x-page-header
            :title="$workspaceTitle"
            :description="$workspaceDescription"
            flush
        >
            <x-slot name="actions">
                <div class="flex items-center gap-3">
                    <a
                        href="{{ route('sites.insights', [$server, $site]) }}"
                        wire:navigate
                        class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 shadow-sm transition-colors hover:bg-slate-50"
                    >
                        {{ __('Insights') }}
                    </a>
                </div>
            </x-slot>
        </x-page-header>

        <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
            @include('livewire.sites.settings.partials.sidebar')

            <main class="min-w-0 space-y-6 lg:col-span-9">
                <div role="tabpanel" id="site-settings-panel" aria-labelledby="site-settings-sidebar" class="space-y-6">
                    @if ($section === 'general')
                        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <form wire:submit="saveGeneralSettings">
                                <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
                                    <div class="border-b border-slate-200 bg-slate-50 p-6 lg:border-b-0 lg:border-r">
                                        <h2 class="text-lg font-semibold text-slate-900">{{ $generalOverviewTitle }}</h2>
                                        <p class="mt-3 text-sm leading-6 text-slate-600">
                                            {{ $generalOverviewDescription }}
                                        </p>
                                        @if ($testingHostname !== '')
                                            <div class="mt-5 rounded-xl border border-slate-200 bg-white p-4">
                                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ $runtimeMode === 'vm' ? __('Testing URL') : __('Temporary hostname') }}</p>
                                                <p class="mt-2 break-all font-mono text-sm text-slate-900">{{ $testingHostname }}</p>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="p-6 sm:p-8">
                                        <div class="grid gap-5">
                                            <div>
                                                <x-input-label for="settings_primary_domain" :value="$primaryHostnameLabel" />
                                                <x-text-input id="settings_primary_domain" wire:model="settings_primary_domain" class="mt-2 block w-full font-mono text-sm" placeholder="app.example.com" />
                                                <x-input-error :messages="$errors->get('settings_primary_domain')" class="mt-2" />
                                            </div>

                                            <div>
                                                <x-input-label for="settings_document_root" :value="$documentRootLabel" />
                                                <x-text-input id="settings_document_root" wire:model="settings_document_root" class="mt-2 block w-full font-mono text-sm" :placeholder="$documentRootPlaceholder" />
                                                <x-input-error :messages="$errors->get('settings_document_root')" class="mt-2" />
                                            </div>

                                            <dl class="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-2">
                                                @foreach ($summaryCards as $card)
                                                    <div>
                                                        <dt class="text-slate-500">{{ $card['label'] }}</dt>
                                                        <dd class="mt-1 break-all font-medium text-slate-900">{{ $card['value'] }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-6 py-4 sm:px-8">
                                    <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                                </div>
                            </form>
                        </section>

                        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <form wire:submit="saveProjectSettings">
                                <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
                                    <div class="border-b border-slate-200 bg-slate-50 p-6 lg:border-b-0 lg:border-r">
                                        <h2 class="text-lg font-semibold text-slate-900">{{ $projectSettingsTitle }}</h2>
                                        <p class="mt-3 text-sm leading-6 text-slate-600">
                                            {{ $projectSettingsDescription }}
                                        </p>
                                    </div>

                                    <div class="space-y-5 p-6 sm:p-8">
                                        <div>
                                            <x-input-label for="project_workspace_id" value="Project" />
                                            <select id="project_workspace_id" wire:model="project_workspace_id" class="mt-2 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                                                <option value="">{{ __('No project') }}</option>
                                                @foreach ($availableWorkspaces as $workspace)
                                                    <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                                                @endforeach
                                            </select>
                                            <x-input-error :messages="$errors->get('project_workspace_id')" class="mt-2" />
                                            <p class="mt-2 text-sm text-slate-600">
                                                {{ __('Project membership can be managed here or from the project resources page.') }}
                                            </p>
                                        </div>

                                        @if ($site->workspace)
                                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                                <p class="text-sm font-semibold text-slate-900">{{ __('Current project') }}</p>
                                                <p class="mt-1 text-sm text-slate-600">
                                                    {{ __('This site currently rolls up into :project.', ['project' => $site->workspace->name]) }}
                                                </p>
                                                <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                                    <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open project resources') }}</a>
                                                    <a href="{{ route('projects.operations', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open project operations') }}</a>
                                                    <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open project delivery') }}</a>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-6 py-4 sm:px-8">
                                    <x-primary-button type="submit">{{ __('Save project settings') }}</x-primary-button>
                                </div>
                            </form>
                        </section>

                        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-5">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <h2 class="text-lg font-semibold text-slate-900">{{ __('Deployment foundation') }}</h2>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('Shared preflight, revision drift, and resource attachment state for this site.') }}</p>
                                </div>
                                <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] {{ ($foundationStatus['runtime_drifted'] ?? false) ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-800' }}">
                                    {{ ($foundationStatus['runtime_drifted'] ?? false) ? __('Detected') : __('In sync') }}
                                </span>
                            </div>

                            <dl class="grid gap-4 sm:grid-cols-3">
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Current revision') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-xs text-slate-900">{{ $foundationStatus['current_runtime_revision'] ?? '—' }}</dd>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Last applied revision') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-xs text-slate-900">{{ $foundationStatus['last_applied_runtime_revision'] ?? __('Not applied yet') }}</dd>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Launch preflight') }}</dt>
                                    <dd class="mt-2 text-sm font-medium text-slate-900">{{ $preflightErrors->isEmpty() ? __('Ready to review') : __('Needs attention') }}</dd>
                                </div>
                            </dl>

                            <div class="grid gap-4 lg:grid-cols-2">
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <h3 class="text-sm font-semibold text-slate-900">{{ __('Launch preflight') }}</h3>
                                    <div class="mt-3 space-y-2">
                                        @if ($preflightErrors->isEmpty() && $preflightWarnings->isEmpty())
                                            <p class="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-800">{{ __('No blocking preflight issues.') }}</p>
                                        @endif
                                        @foreach ($preflightErrors as $error)
                                            <p class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $error }}</p>
                                        @endforeach
                                        @foreach ($preflightWarnings as $warning)
                                            <p class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ $warning }}</p>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <h3 class="text-sm font-semibold text-slate-900">{{ __('Attached resources') }}</h3>
                                    <div class="mt-3 space-y-2">
                                        @foreach ($resourceBindings as $binding)
                                            @include('livewire.sites.partials.resource-binding-row', ['binding' => $binding])
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <h3 class="text-sm font-semibold text-slate-900">{{ __('Shared secrets & config') }}</h3>
                                        <p class="mt-1 text-sm text-slate-600">{{ __('Dply-managed environment inventory for this site across env file, site variables, workspace variables, and deploy-managed credentials.') }}</p>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 ring-1 ring-slate-200">
                                            {{ trans_choice('{1} :count secret|[2,*] :count secrets', $secretEntries->count(), ['count' => $secretEntries->count()]) }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 ring-1 ring-slate-200">
                                            {{ trans_choice('{1} :count config value|[2,*] :count config values', $configEntries->count(), ['count' => $configEntries->count()]) }}
                                        </span>
                                    </div>
                                </div>
                                <p class="mt-3 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-800">
                                    {{ $secretDeliveryLabel }}
                                </p>
                                <div class="mt-4 grid gap-4 xl:grid-cols-2">
                                    <div class="space-y-2">
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Secrets') }}</p>
                                        @forelse ($secretEntries as $entry)
                                            <div class="rounded-xl border border-slate-200 bg-white px-3 py-3">
                                                <div class="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <p class="font-mono text-sm font-medium text-slate-900">{{ $entry['key'] }}</p>
                                                        <p class="mt-1 text-xs text-slate-500">
                                                            {{ str($entry['scope'] ?? 'site')->headline() }} · {{ str_replace('_', ' ', (string) ($entry['source'] ?? 'managed')) }} · {{ str($entry['classification'] ?? 'config')->headline() }}
                                                        </p>
                                                    </div>
                                                    <span class="rounded-full bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-700">
                                                        {{ __('Redacted') }}
                                                    </span>
                                                </div>
                                            </div>
                                        @empty
                                            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-3 py-3 text-sm text-slate-600">
                                                {{ __('No shared secrets are inventoried yet.') }}
                                            </div>
                                        @endforelse
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Config values') }}</p>
                                        @forelse ($configEntries as $entry)
                                            <div class="rounded-xl border border-slate-200 bg-white px-3 py-3">
                                                <div class="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <p class="font-mono text-sm font-medium text-slate-900">{{ $entry['key'] }}</p>
                                                        <p class="mt-1 text-xs text-slate-500">
                                                            {{ str($entry['scope'] ?? 'site')->headline() }} · {{ str_replace('_', ' ', (string) ($entry['source'] ?? 'managed')) }}
                                                        </p>
                                                    </div>
                                                    <p class="max-w-[16rem] break-all text-right font-mono text-xs text-slate-700">
                                                        {{ \Illuminate\Support\Str::limit((string) ($entry['value'] ?? ''), 80) }}
                                                    </p>
                                                </div>
                                            </div>
                                        @empty
                                            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-3 py-3 text-sm text-slate-600">
                                                {{ __('No non-secret config values are inventoried yet.') }}
                                            </div>
                                        @endforelse
                                    </div>
                                </div>
                            </div>

                            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($preflightChecks as $check)
                                    <div class="rounded-xl border px-3 py-2 text-sm {{ ($check['level'] ?? 'ok') === 'error' ? 'border-red-200 bg-red-50 text-red-800' : (($check['level'] ?? 'ok') === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-sky-200 bg-sky-50 text-sky-800') }}">
                                        <span class="font-medium">{{ str($check['key'] ?? 'check')->headline() }}</span>
                                        <p class="mt-1 text-xs leading-5">{{ $check['message'] ?? '' }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
                                <div class="border-b border-slate-200 bg-slate-50/70 p-6 lg:border-b-0 lg:border-r">
                                        <h2 class="text-lg font-semibold text-slate-900">{{ $detailsTitle }}</h2>
                                    <p class="mt-3 text-sm leading-6 text-slate-600">
                                            {{ $detailsDescription }}
                                    </p>
                                </div>

                                <div class="p-6 sm:p-8">
                                    @php
                                        $diskUsageBytes = data_get($site->meta, 'disk_usage.bytes');
                                    @endphp
                                    <dl class="grid grid-cols-1 gap-5 text-sm sm:grid-cols-2">
                                        <div>
                                            <dt class="text-slate-500">{{ __('Created at') }}</dt>
                                            <dd class="mt-1 font-medium text-slate-900">{{ $site->created_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">{{ __('Site ID') }}</dt>
                                            <dd class="mt-1 font-medium text-slate-900">{{ $site->id }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">{{ __('Stack') }}</dt>
                                            <dd class="mt-1 font-medium text-slate-900">{{ $site->type->label() }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">{{ __('Disk usage') }}</dt>
                                            <dd class="mt-1 font-medium text-slate-900">
                                                {{ is_numeric($diskUsageBytes) ? \Illuminate\Support\Number::fileSize((int) $diskUsageBytes) : __('Not recorded yet') }}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-5">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <h2 class="text-lg font-semibold text-slate-900">{{ __('Operations summary') }}</h2>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('Keep the most important deploy, runtime, and certificate context visible on General while deeper editing lives in focused sections.') }}</p>
                                </div>
                                <div class="flex flex-wrap gap-3 text-sm">
                                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy']) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Deploy') }}</a>
                                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'logs']) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Deployment log') }}</a>
                                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'certificates']) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open certificate settings') }}</a>
                                </div>
                            </div>

                            @if ($previewDomain || $site->certificates->isNotEmpty())
                                <div class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-base font-semibold text-slate-900">{{ __('Preview & SSL') }}</h3>
                                            <p class="mt-1 text-sm text-slate-600">{{ __('Preview hostname reachability and the latest certificate state for this site.') }}</p>
                                        </div>
                                        <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 ring-1 ring-slate-200">
                                            {{ $site->currentSslSummary() }}
                                        </span>
                                    </div>

                                    <dl class="grid gap-4 sm:grid-cols-2">
                                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Preview hostname') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $previewDomain?->hostname ?? __('No preview domain') }}</dd>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Preview DNS') }}</dt>
                                            <dd class="mt-2 text-sm text-slate-900">{{ $previewDomain?->dns_status ?? __('Not configured') }}</dd>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Latest certificate') }}</dt>
                                            <dd class="mt-2 text-sm text-slate-900">
                                                @if ($latestCertificate)
                                                    {{ ucfirst($latestCertificate->provider_type) }} · {{ $latestCertificate->status }}
                                                @else
                                                    {{ __('No certificates requested') }}
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Certificate scope') }}</dt>
                                            <dd class="mt-2 text-sm text-slate-900">{{ $latestCertificate ? ucfirst($latestCertificate->scope_type) : __('—') }}</dd>
                                        </div>
                                        @if ($latestCertificate)
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:col-span-2">
                                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Certificate domains') }}</dt>
                                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ implode(', ', $latestCertificate->domainHostnames()) }}</dd>
                                            </div>
                                            @if (! empty($latestCertificate->last_output))
                                                <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:col-span-2">
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
                                        </div>
                                    @endif
                                </div>
                            @endif

                            @if ($site->usesFunctionsRuntime() || $site->usesDockerRuntime() || $site->usesKubernetesRuntime())
                                <div class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-base font-semibold text-slate-900">{{ __('Runtime target') }}</h3>
                                            <p class="mt-1 text-sm text-slate-600">{{ __('The latest managed deploy details for this runtime target.') }}</p>
                                        </div>
                                        <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 ring-1 ring-slate-200">
                                            @if ($site->usesAwsLambdaRuntime())
                                                {{ __('AWS Lambda') }}
                                            @elseif ($site->usesFunctionsRuntime())
                                                {{ __('DigitalOcean Functions') }}
                                            @elseif ($site->usesDockerRuntime())
                                                {{ __('Docker host') }}
                                            @else
                                                {{ __('Kubernetes cluster') }}
                                            @endif
                                        </span>
                                    </div>

                                    @if ($site->usesFunctionsRuntime())
                                        <dl class="grid gap-4 sm:grid-cols-2">
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Runtime') }}</dt>
                                                <dd class="mt-2 font-mono text-sm text-slate-900">{{ $serverlessRuntime['runtime'] ?? '—' }}</dd>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Entrypoint') }}</dt>
                                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $serverlessRuntime['entrypoint'] ?? '—' }}</dd>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Revision') }}</dt>
                                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $serverlessRuntime['last_revision_id'] ?? __('Not deployed yet') }}</dd>
                                            </div>
                                            @if (! empty($serverlessRuntime['function_arn']))
                                                <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:col-span-2">
                                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Function ARN') }}</dt>
                                                    <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $serverlessRuntime['function_arn'] }}</dd>
                                                </div>
                                            @endif
                                        </dl>
                                    @elseif ($site->usesDockerRuntime())
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Last generated compose file') }}</p>
                                                <pre class="mt-2 max-h-64 overflow-auto rounded-xl bg-slate-950 p-3 text-xs text-sky-100">{{ $dockerRuntime['compose_yaml'] ?? __('Not generated yet') }}</pre>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Managed Dockerfile') }}</p>
                                                <pre class="mt-2 max-h-64 overflow-auto rounded-xl bg-slate-950 p-3 text-xs text-sky-100">{{ $dockerRuntime['dockerfile'] ?? __('Not generated yet') }}</pre>
                                            </div>
                                        </div>
                                    @else
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Namespace') }}</p>
                                                <p class="mt-2 text-sm font-medium text-slate-900">{{ $kubernetesRuntime['namespace'] ?? __('default') }}</p>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:col-span-2">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Manifest') }}</p>
                                                <pre class="mt-2 max-h-80 overflow-auto rounded-xl bg-slate-950 p-3 text-xs text-violet-100">{{ $kubernetesRuntime['manifest_yaml'] ?? __('Not generated yet') }}</pre>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </section>

                        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <form wire:submit="saveSiteNotes">
                                <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
                                    <div class="border-b border-slate-200 bg-slate-50/70 p-6 lg:border-b-0 lg:border-r">
                                        <h2 class="text-lg font-semibold text-slate-900">{{ __('Site notes') }}</h2>
                                        <p class="mt-3 text-sm leading-6 text-slate-600">
                                            {{ __('Keep operational notes here for details you want to save or hand off later. Avoid putting secrets or credentials in this field.') }}
                                        </p>
                                    </div>

                                    <div class="space-y-4 p-6 sm:p-8">
                                        <div>
                                            <x-input-label for="site_notes" value="Notes" />
                                            <textarea id="site_notes" wire:model="site_notes" rows="5" class="mt-2 block w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                                            <x-input-error :messages="$errors->get('site_notes')" class="mt-2" />
                                        </div>
                                    </div>
                                </div>

                                <div class="flex justify-end border-t border-slate-200 bg-slate-50/40 px-6 py-4 sm:px-8">
                                    <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                                </div>
                            </form>
                        </section>
                    @elseif ($section === 'routing')
                        @include('livewire.sites.settings.partials.routing')
                    @elseif ($section === 'certificates')
                        @include('livewire.sites.settings.partials.certificates')
                    @elseif ($section === 'deploy')
                        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-5">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <h2 class="text-lg font-semibold text-slate-900">{{ __('Deploy') }}</h2>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('Keep repository source, no-downtime rollout strategy, deploy scripts, hooks, and release/log access together here. Deploy execution itself stays on the site overview page.') }}</p>
                                </div>
                                <div class="flex flex-wrap gap-3 text-sm">
                                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy']) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open deploy actions') }}</a>
                                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'logs']) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open site logs') }}</a>
                                    <a href="{{ route('servers.logs', $server) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open server logs') }}</a>
                                </div>
                            </div>

                            <form wire:submit="saveGit" class="space-y-4">
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-sm font-semibold text-slate-900">{{ __('Repository and branch') }}</p>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('Choose what Dply checks out for each deploy and keep the legacy post-deploy command with the repo configuration so the pipeline reads from top to bottom.') }}</p>
                                </div>
                                @if ($functionsHost)
                                    <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
                                        <p class="font-medium">{{ __('Serverless deploy target') }}</p>
                                        <p class="mt-1">{{ __('Serverless deploys clone the repository on the queue worker, run the configured build command, package the build output, and publish the resulting artifact for the selected target.') }}</p>
                                    </div>

                                    <div>
                                        <x-input-label for="functions_repo_source" value="Repository source" />
                                        <select id="functions_repo_source" wire:model.live="functions_repo_source" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                            @if (count($linkedSourceControlAccounts) > 0)
                                                <option value="provider">{{ __('Connected Git provider') }}</option>
                                            @endif
                                            <option value="manual">{{ __('Manual Git URL') }}</option>
                                        </select>
                                    </div>

                                    @if ($functions_repo_source === 'provider' && count($linkedSourceControlAccounts) > 0)
                                        <div class="grid gap-3 md:grid-cols-2">
                                            <div>
                                                <x-input-label for="functions_source_control_account_id" value="Connected account" />
                                                <select id="functions_source_control_account_id" wire:model.live="functions_source_control_account_id" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                                    <option value="">{{ __('Select an account') }}</option>
                                                    @foreach ($linkedSourceControlAccounts as $account)
                                                        <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$errors->get('functions_source_control_account_id')" class="mt-1" />
                                            </div>
                                            <div>
                                                <x-input-label for="functions_repository_selection" value="Repository" />
                                                <select id="functions_repository_selection" wire:model.live="functions_repository_selection" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                                    <option value="">{{ __('Select a repository') }}</option>
                                                    @foreach ($availableFunctionsRepositories as $repository)
                                                        <option value="{{ $repository['url'] }}">{{ $repository['label'] }}</option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$errors->get('functions_repository_selection')" class="mt-1" />
                                            </div>
                                        </div>
                                    @endif
                                @endif

                                <div>
                                    <x-input-label for="git_repository_url" value="Repository URL" />
                                    <x-text-input id="git_repository_url" wire:model.blur="git_repository_url" class="mt-1 block w-full font-mono text-sm" placeholder="git@github.com:org/repo.git" />
                                    @if ($functionsHost)
                                        <p class="mt-1 text-sm text-brand-moss">{{ __('This repo is cloned locally during deploys instead of on a remote SSH machine.') }}</p>
                                    @endif
                                    <x-input-error :messages="$errors->get('git_repository_url')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="git_branch" value="Branch" />
                                    <x-text-input id="git_branch" wire:model.blur="git_branch" class="mt-1 block w-full sm:w-48" />
                                    <x-input-error :messages="$errors->get('git_branch')" class="mt-1" />
                                </div>
                                @if ($functionsHost)
                                    <div>
                                        <x-input-label for="functions_repository_subdirectory" value="Repository subdirectory" />
                                        <x-text-input id="functions_repository_subdirectory" wire:model.blur="functions_repository_subdirectory" class="mt-1 block w-full font-mono text-sm" placeholder="apps/functions" />
                                        <p class="mt-1 text-sm text-brand-moss">{{ __('Optional for monorepos.') }}</p>
                                        <x-input-error :messages="$errors->get('functions_repository_subdirectory')" class="mt-1" />
                                    </div>
                                @else
                                    <div>
                                        <x-input-label for="post_deploy_command" value="Post-deploy command" />
                                        <textarea id="post_deploy_command" wire:model="post_deploy_command" rows="3" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-sm" placeholder="composer install --no-dev && php artisan migrate --force"></textarea>
                                    </div>
                                @endif

                                @if ($functionsHost && $functionsDetection !== [])
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 space-y-3">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-semibold text-brand-ink">{{ __('Detected setup') }}</p>
                                                <p class="mt-1 text-sm text-brand-moss">{{ __('Dply inspected the configured repository and inferred a starting runtime/build setup for this target.') }}</p>
                                            </div>
                                            <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss ring-1 ring-brand-ink/10">
                                                {{ strtoupper((string) ($functionsDetection['confidence'] ?? 'low')) }}
                                            </span>
                                        </div>
                                        <dl class="grid gap-3 md:grid-cols-2">
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Framework') }}</p>
                                                <p class="mt-1 text-sm font-medium text-brand-ink">{{ str((string) ($functionsDetection['framework'] ?? 'unknown'))->replace('_', ' ')->title() }}</p>
                                            </div>
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Language') }}</p>
                                                <p class="mt-1 text-sm font-medium text-brand-ink">{{ str((string) ($functionsDetection['language'] ?? 'unknown'))->replace('_', ' ')->title() }}</p>
                                            </div>
                                        </dl>
                                        @if (count($functionsDetection['warnings'] ?? []) > 0)
                                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 space-y-2">
                                                @foreach (($functionsDetection['warnings'] ?? []) as $warning)
                                                    <p>{{ $warning }}</p>
                                                @endforeach
                                            </div>
                                        @endif
                                        <details class="rounded-xl border border-brand-ink/10 bg-white p-4" @if(($functionsDetection['unsupported_for_target'] ?? false) || (($functionsDetection['confidence'] ?? '') === 'low')) open @endif>
                                            <summary class="cursor-pointer list-none text-sm font-semibold text-brand-ink">{{ __('Advanced runtime overrides') }}</summary>
                                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                                <div>
                                                    <x-input-label for="functions_runtime" value="Serverless runtime" />
                                                    <x-text-input id="functions_runtime" wire:model="functions_runtime" class="mt-1 block w-full font-mono text-sm" />
                                                    <x-input-error :messages="$errors->get('functions_runtime')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="functions_entrypoint" value="HTTP entrypoint" />
                                                    <x-text-input id="functions_entrypoint" wire:model="functions_entrypoint" class="mt-1 block w-full font-mono text-sm" />
                                                    <x-input-error :messages="$errors->get('functions_entrypoint')" class="mt-1" />
                                                </div>
                                                <div class="md:col-span-2">
                                                    <x-input-label for="functions_build_command" value="Build command" />
                                                    <textarea id="functions_build_command" wire:model="functions_build_command" rows="3" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-sm" placeholder="npm install && npm run build"></textarea>
                                                    <x-input-error :messages="$errors->get('functions_build_command')" class="mt-1" />
                                                </div>
                                                <div class="md:col-span-2">
                                                    <x-input-label for="functions_artifact_output_path" value="Build output path" />
                                                    <x-text-input id="functions_artifact_output_path" wire:model="functions_artifact_output_path" class="mt-1 block w-full font-mono text-sm" placeholder="dist" />
                                                    <p class="mt-1 text-sm text-brand-moss">{{ __('Relative to the repo checkout or subdirectory.') }}</p>
                                                    <x-input-error :messages="$errors->get('functions_artifact_output_path')" class="mt-1" />
                                                </div>
                                            </div>
                                        </details>
                                    </div>
                                @endif

                                <div class="flex flex-wrap gap-3">
                                    <x-primary-button type="submit">{{ __('Save repository settings') }}</x-primary-button>
                                    @if (! $functionsHost)
                                        <button type="button" wire:click="generateDeployKey" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Generate deploy key') }}</button>
                                    @endif
                                </div>
                            </form>

                            @if ($functionsHost)
                                @php
                                    $functionsConfig = $site->serverlessConfig();
                                    $serverlessTargetLabel = $server->isAwsLambdaHost() ? __('AWS Lambda') : __('DigitalOcean Functions');
                                @endphp
                                <div class="grid gap-3 rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 md:grid-cols-2">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Target') }}</p>
                                        <p class="mt-1 text-sm font-medium text-brand-ink">{{ $serverlessTargetLabel }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Runtime') }}</p>
                                        <p class="mt-1 font-mono text-sm text-brand-ink">{{ $functionsConfig['runtime'] ?? $functions_runtime ?? '—' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Entrypoint') }}</p>
                                        <p class="mt-1 font-mono text-sm text-brand-ink">{{ $functionsConfig['entrypoint'] ?? $functions_entrypoint ?? '—' }}</p>
                                    </div>
                                    <div class="md:col-span-2">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Latest managed artifact') }}</p>
                                        <p class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $functionsConfig['artifact_path'] ?? __('Not built yet') }}</p>
                                    </div>
                                    @if (! empty($functionsConfig['function_arn']))
                                        <div class="md:col-span-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Function ARN') }}</p>
                                            <p class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $functionsConfig['function_arn'] }}</p>
                                        </div>
                                    @endif
                                    @if (! empty($functionsConfig['function_url']))
                                        <div class="md:col-span-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Function URL') }}</p>
                                            <p class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $functionsConfig['function_url'] }}</p>
                                        </div>
                                    @endif
                                    @if (! empty($functionsConfig['action_url']))
                                        <div class="md:col-span-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Published action URL') }}</p>
                                            <p class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $functionsConfig['action_url'] }}</p>
                                        </div>
                                    @endif
                                </div>
                            @elseif ($site->git_deploy_key_public)
                                <div>
                                    <p class="text-sm text-brand-moss">{{ __('Public key (add to your Git provider deploy keys):') }}</p>
                                    <pre class="mt-2 overflow-x-auto rounded-xl bg-slate-900 p-3 text-xs text-green-400">{{ $site->git_deploy_key_public }}</pre>
                                </div>
                            @endif

                            @if (! $functionsHost)
                                <form wire:submit="saveDeploymentSettings" class="space-y-4 rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h3 class="text-base font-semibold text-brand-ink">{{ __('No downtime and rollout settings') }}</h3>
                                            <p class="mt-1 text-sm text-brand-moss">{{ __('Atomic deploys keep a releases history, run scripts in the new release, then flip `current` only after success. Simple deploys update the live path directly.') }}</p>
                                        </div>
                                        <span class="inline-flex items-center rounded-full bg-brand-sand/50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss ring-1 ring-brand-ink/10">
                                            {{ $deploy_strategy === 'atomic' ? __('No downtime') : __('Simple') }}
                                        </span>
                                    </div>

                                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                        <div>
                                            <x-input-label value="Deploy strategy" />
                                            <select wire:model="deploy_strategy" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                                <option value="simple">{{ __('Simple (git in deploy path)') }}</option>
                                                <option value="atomic">{{ __('Atomic (releases + current symlink)') }}</option>
                                            </select>
                                            <p class="mt-2 text-sm text-brand-moss">{{ __('Choose `Atomic` for no-downtime releases with rollback-friendly history.') }}</p>
                                        </div>
                                        <div>
                                            <x-input-label for="releases_to_keep" value="Releases to keep" />
                                            <x-text-input id="releases_to_keep" type="number" wire:model="releases_to_keep" class="mt-1 w-28" min="1" max="50" />
                                            <p class="mt-2 text-sm text-brand-moss">{{ __('Applies when atomic deploys are enabled.') }}</p>
                                        </div>
                                        <div>
                                            <x-input-label for="deployment_environment" value="Environment group" />
                                            <x-text-input id="deployment_environment" wire:model="deployment_environment" class="mt-1 block w-full text-sm" />
                                            <p class="mt-2 text-sm text-brand-moss">{{ __('Used when resolving key/value environment variables for deploys.') }}</p>
                                        </div>
                                        <div>
                                            <x-input-label for="octane_port" value="Octane port" />
                                            <x-text-input id="octane_port" wire:model="octane_port" placeholder="8000" class="mt-1 block w-full font-mono text-sm" />
                                        </div>
                                        <div>
                                            <x-input-label for="php_fpm_user" value="PHP-FPM pool user" />
                                            <x-text-input id="php_fpm_user" wire:model="php_fpm_user" class="mt-1 block w-full text-sm" placeholder="www-data" />
                                        </div>
                                        <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                                            <p class="text-sm font-semibold text-brand-ink">{{ __('Rollback context') }}</p>
                                            <p class="mt-1 text-sm text-brand-moss">{{ __('Deploy history and release rollback stay on the site overview page so operators can launch, inspect, and recover from one place.') }}</p>
                                        </div>
                                    </div>

                                    <div class="grid gap-3">
                                        <label class="flex items-center gap-2 text-sm text-brand-ink">
                                            <input type="checkbox" wire:model="laravel_scheduler" class="rounded border-slate-300">
                                            {{ __('Laravel scheduler (schedule:run every minute via server crontab)') }}
                                        </label>
                                        <label class="flex items-center gap-2 text-sm text-brand-ink">
                                            <input type="checkbox" wire:model="restart_supervisor_programs_after_deploy" class="rounded border-slate-300">
                                            {{ __('Restart Supervisor programs after successful deploy') }}
                                        </label>
                                        <div>
                                            <x-input-label for="nginx_extra_raw" value="Extra Nginx inside server block (advanced)" />
                                            <textarea id="nginx_extra_raw" wire:model="nginx_extra_raw" rows="4" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="# location /foo { ... }"></textarea>
                                        </div>
                                    </div>

                                    <x-primary-button type="submit">{{ __('Save deploy strategy') }}</x-primary-button>
                                </form>
                            @endif

                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                                <div>
                                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Scripts') }}</h3>
                                    <p class="mt-1 text-sm text-brand-moss">{{ __('Lay out the deploy as pre, main, and post stages so custom steps stay visible around the core checkout and activation flow.') }}</p>
                                </div>

                                <div class="grid gap-4 xl:grid-cols-3">
                                    <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                                        <p class="text-sm font-semibold text-brand-ink">{{ __('Pre-deploy script') }}</p>
                                        <p class="mt-1 text-sm text-brand-moss">{{ __('Ordered pipeline steps run after code is cloned and before the main activation flow completes.') }}</p>
                                        <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Current steps') }}</p>
                                        @if ($site->deploySteps->isNotEmpty())
                                            <ol class="mt-2 space-y-2 text-sm">
                                                @foreach ($site->deploySteps->sortBy('sort_order') as $step)
                                                    <li class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                                            <span>
                                                                <span class="font-mono text-xs text-brand-ink">{{ $step->step_type }}</span>
                                                                <span class="text-xs text-brand-moss">· {{ (int) ($step->timeout_seconds ?? 900) }}s</span>
                                                                @if ($step->custom_command)
                                                                    <span class="text-brand-moss">— {{ \Illuminate\Support\Str::limit($step->custom_command, 60) }}</span>
                                                                @endif
                                                            </span>
                                                            <span class="flex gap-3 text-xs">
                                                                <button type="button" wire:click="moveDeployStepUp({{ $step->id }})" class="text-brand-moss hover:underline">{{ __('Up') }}</button>
                                                                <button type="button" wire:click="moveDeployStepDown({{ $step->id }})" class="text-brand-moss hover:underline">{{ __('Down') }}</button>
                                                                <button type="button" wire:click="deleteDeployPipelineStep({{ $step->id }})" class="text-red-700 hover:underline">{{ __('Remove') }}</button>
                                                            </span>
                                                        </div>
                                                    </li>
                                                @endforeach
                                            </ol>
                                        @else
                                            <p class="mt-2 text-sm text-brand-moss">{{ __('No extra pre-deploy steps yet.') }}</p>
                                        @endif
                                    </div>

                                    <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                                        <p class="text-sm font-semibold text-brand-ink">{{ __('Main deploy script') }}</p>
                                        <div class="mt-3 space-y-2 text-sm text-brand-moss">
                                            <p>{{ __('1. Prepare deploy directory / release target') }}</p>
                                            <p>{{ __('2. Clone or update the configured branch') }}</p>
                                            <p>{{ __('3. Run ordered pipeline steps') }}</p>
                                            <p>{{ __('4. Activate the release when atomic deploys are enabled') }}</p>
                                        </div>
                                    </div>

                                    <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                                        <p class="text-sm font-semibold text-brand-ink">{{ __('Post-deploy script') }}</p>
                                        <p class="mt-1 text-sm text-brand-moss">{{ __('Legacy post-deploy command runs after the pipeline. Keep this empty if the ordered steps already express everything.') }}</p>
                                        <pre class="mt-3 overflow-x-auto rounded-xl bg-slate-950 p-3 text-xs text-emerald-100">{{ $post_deploy_command !== '' ? $post_deploy_command : __('No post-deploy command configured.') }}</pre>
                                    </div>
                                </div>

                                @if (! $functionsHost)
                                    <form wire:submit="addDeployPipelineStep" class="flex flex-wrap items-end gap-3">
                                        <div>
                                            <label for="new_deploy_step_type" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Step') }}</label>
                                            <select id="new_deploy_step_type" wire:model="new_deploy_step_type" class="min-w-[220px] rounded-md border-slate-300 shadow-sm text-sm">
                                                @foreach (\App\Models\SiteDeployStep::typeLabels() as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="min-w-[220px] flex-1">
                                            <label for="new_deploy_step_command" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('npm script / custom command') }}</label>
                                            <input type="text" id="new_deploy_step_command" wire:model="new_deploy_step_command" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-sm" placeholder="build or full shell for custom" />
                                            <x-input-error :messages="$errors->get('new_deploy_step_command')" class="mt-1" />
                                        </div>
                                        <div>
                                            <label for="new_deploy_step_timeout" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Timeout (s)') }}</label>
                                            <input type="number" id="new_deploy_step_timeout" wire:model="new_deploy_step_timeout" min="30" max="3600" class="w-24 rounded-md border-slate-300 shadow-sm text-sm" />
                                        </div>
                                        <x-primary-button type="submit">{{ __('Add step') }}</x-primary-button>
                                    </form>
                                @endif
                            </section>

                            @if ($site->usesDockerRuntime())
                                @php
                                    $dockerRuntime = is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [];
                                @endphp
                                <div class="space-y-4 rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                                    <div>
                                        <p class="text-sm font-semibold text-brand-ink">{{ __('Runtime target') }}</p>
                                        <p class="mt-1 text-sm text-brand-moss">{{ __('Deploys sync the repository to the Docker host, write these managed files, and run `docker compose up -d --build`.') }}</p>
                                    </div>
                                    <div class="grid gap-4 xl:grid-cols-2">
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Compose file') }}</p>
                                            <pre class="mt-2 max-h-80 overflow-auto rounded-xl bg-slate-950 p-3 text-xs text-sky-100">{{ $dockerRuntime['compose_yaml'] ?? __('Not generated yet') }}</pre>
                                        </div>
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Managed Dockerfile') }}</p>
                                            <pre class="mt-2 max-h-80 overflow-auto rounded-xl bg-slate-950 p-3 text-xs text-emerald-100">{{ $dockerRuntime['dockerfile'] ?? __('Not generated yet') }}</pre>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            @if ($site->usesKubernetesRuntime())
                                @php
                                    $kubernetesRuntime = is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
                                @endphp
                                <div class="space-y-4 rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-semibold text-brand-ink">{{ __('Runtime target') }}</p>
                                            <p class="mt-1 text-sm text-brand-moss">{{ __('This runtime currently stores the generated manifest and namespace so the cluster apply step stays inspectable while live execution work continues.') }}</p>
                                        </div>
                                        <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss ring-1 ring-brand-ink/10">
                                            {{ $kubernetesRuntime['namespace'] ?? __('default') }}
                                        </span>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Manifest') }}</p>
                                        <pre class="mt-2 max-h-96 overflow-auto rounded-xl bg-slate-950 p-3 text-xs text-violet-100">{{ $kubernetesRuntime['manifest_yaml'] ?? __('Not generated yet') }}</pre>
                                    </div>
                                </div>
                            @endif

                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                                <div>
                                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Deploy hooks') }}</h3>
                                    <p class="mt-1 text-sm text-brand-moss">{{ __('before_clone runs in the deploy base directory. after_clone runs in the new release. after_activate runs after the current symlink updates on atomic deploys.') }}</p>
                                </div>

                                <div class="grid gap-4 xl:grid-cols-3">
                                    @foreach ($deployHookPhaseLabels as $phase => $phaseLabel)
                                        <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                                            <p class="text-sm font-semibold text-brand-ink">{{ $phaseLabel }}</p>
                                            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-brand-moss">{{ $phase }}</p>
                                            @php
                                                $phaseHooks = $site->deployHooks->where('phase', $phase)->sortBy('sort_order');
                                            @endphp
                                            @if ($phaseHooks->isNotEmpty())
                                                <ul class="mt-3 space-y-3 text-sm">
                                                    @foreach ($phaseHooks as $hook)
                                                        <li class="rounded-xl border border-brand-ink/10 bg-white p-3">
                                                            <div class="mb-2 flex justify-between gap-3">
                                                                <span class="font-medium text-brand-ink">#{{ $hook->sort_order }} <span class="font-normal text-brand-moss">· {{ (int) ($hook->timeout_seconds ?? config('dply.default_deploy_hook_timeout_seconds', 900)) }}s</span></span>
                                                                <button type="button" wire:click="deleteDeployHook({{ $hook->id }})" class="text-red-700 hover:underline">{{ __('Remove') }}</button>
                                                            </div>
                                                            <pre class="overflow-x-auto rounded-xl bg-slate-900 p-3 text-xs text-green-400">{{ \Illuminate\Support\Str::limit($hook->script, 500) }}</pre>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @else
                                                <p class="mt-3 text-sm text-brand-moss">{{ __('No hooks in this stage yet.') }}</p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                <form wire:submit="addDeployHook" class="space-y-3">
                                    <select wire:model="new_hook_phase" class="rounded-md border-slate-300 text-sm">
                                        <option value="before_clone">before_clone</option>
                                        <option value="after_clone">after_clone</option>
                                        <option value="after_activate">after_activate</option>
                                    </select>
                                    <div class="flex flex-wrap items-end gap-3">
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Sort order') }}</label>
                                            <x-text-input type="number" wire:model="new_hook_order" class="w-24 text-sm" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Timeout (s)') }}</label>
                                            <input type="number" wire:model="new_hook_timeout_seconds" min="30" max="3600" class="w-24 rounded-md border-slate-300 shadow-sm text-sm" />
                                        </div>
                                    </div>
                                    <textarea wire:model="new_hook_script" rows="4" class="w-full rounded-md border-slate-300 font-mono text-xs" placeholder="#!/usr/bin/env bash"></textarea>
                                    <x-primary-button type="submit">{{ __('Add hook') }}</x-primary-button>
                                </form>
                            </section>

                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                                <div>
                                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Deploy script variables') }}</h3>
                                    <p class="mt-1 text-sm text-brand-moss">{{ __('Use these placeholders in custom steps, legacy post-deploy commands, and hook scripts when you need deploy context without hard-coding site-specific values.') }}</p>
                                </div>

                                <dl class="grid gap-3 md:grid-cols-2">
                                    @foreach ($deployVariableReference as $token => $description)
                                        <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                                            <dt class="font-mono text-sm text-brand-ink">{{ $token }}</dt>
                                            <dd class="mt-2 text-sm text-brand-moss">{{ $description }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </section>
                        </section>
                    @elseif ($section === 'runtime')
                        @include('livewire.sites.settings.partials.runtime')
                    @elseif ($section === 'environment')
                        @include('livewire.sites.settings.partials.environment')
                    @elseif ($section === 'logs')
                        @include('livewire.sites.settings.partials.logs')
                    @elseif ($section === 'webhooks')
                        @include('livewire.sites.settings.partials.webhooks')
                    @elseif ($section === 'danger')
                        @include('livewire.sites.settings.partials.danger')
                    @endif
                </div>
            </main>
        </div>
    </div>

    <x-slot name="modals">
        <x-modal
            name="quick-domain-ssl-modal"
            :show="false"
            maxWidth="lg"
            overlayClass="bg-brand-ink/30"
            panelClass="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-2xl"
            focusable
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Quick SSL') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add SSL for this domain') }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('Create a certificate request without leaving the Domains workspace. This is best for customer-facing domains that are otherwise ready to serve traffic.') }}
                </p>
            </div>

            <div class="space-y-5 px-6 py-6">
                <div class="rounded-xl border border-brand-ink/10 bg-slate-50/70 px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Domain') }}</p>
                    <p class="mt-2 font-mono text-sm text-brand-ink">{{ $quick_ssl_domain_hostname ?: __('No domain selected') }}</p>
                    <x-input-error :messages="$errors->get('quick_ssl_domain_hostname')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="quick_ssl_provider_type" :value="__('Certificate provider')" />
                    <select
                        id="quick_ssl_provider_type"
                        wire:model="quick_ssl_provider_type"
                        class="mt-2 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    >
                        <option value="{{ \App\Models\SiteCertificate::PROVIDER_LETSENCRYPT }}">{{ __("Let's Encrypt") }}</option>
                        <option value="{{ \App\Models\SiteCertificate::PROVIDER_ZEROSSL }}">{{ __('ZeroSSL') }}</option>
                    </select>
                    <p class="mt-2 text-xs leading-5 text-brand-moss">
                        @if ($quick_ssl_provider_type === \App\Models\SiteCertificate::PROVIDER_ZEROSSL)
                            {{ __('This quick path uses ZeroSSL HTTP file validation, then installs the downloaded certificate on the host.') }}
                        @else
                            {{ __('This quick path uses an HTTP challenge and starts the request immediately after you confirm.') }}
                        @endif
                    </p>
                    <x-input-error :messages="$errors->get('quick_ssl_provider_type')" class="mt-2" />
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeQuickDomainSslModal">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <x-primary-button type="button" wire:click="quickAddDomainSsl" wire:loading.attr="disabled" wire:target="quickAddDomainSsl">
                    <span wire:loading.remove wire:target="quickAddDomainSsl">
                        {{ $quick_ssl_provider_type === \App\Models\SiteCertificate::PROVIDER_ZEROSSL ? __('Save request') : __('Add SSL') }}
                    </span>
                    <span wire:loading wire:target="quickAddDomainSsl" class="inline-flex items-center justify-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Working…') }}
                    </span>
                </x-primary-button>
            </div>
        </x-modal>
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
