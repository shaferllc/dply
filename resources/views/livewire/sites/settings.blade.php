@php
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
        \App\Models\SiteDeployHook::PHASE_BEFORE_CLONE => __('Before clone'),
        \App\Models\SiteDeployHook::PHASE_AFTER_CLONE => __('After clone'),
        \App\Models\SiteDeployHook::PHASE_AFTER_ACTIVATE => __('After activate'),
    ];
    $runtimeMode = $site->runtimeTargetMode();
    $runtimePlatform = $site->runtimeTargetPlatform();
    $runtimeFamily = $site->runtimeTargetFamily();
    $isContainerWorkspace = in_array($runtimeMode, ['docker', 'kubernetes', 'serverless'], true);
    $settingsSidebarItems = \App\Support\SiteSettingsSidebar::items($site, $server);
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

    // Per-section header metadata (title, description, icon) — drives the
    // <x-page-header> on each settings section so the operator sees a specific
    // orientation ("HTTP basic authentication", "Routing", …) rather than the
    // generic "Site workspace" copy on every tab.
    $sectionHeader = \App\Support\SiteSettingsHeader::for($site, $server, $section);

    // Authorization snapshot for the header. Drives the role badge and the
    // description fallback for read-only / deployer roles. Resolved once so the
    // view doesn't re-call Gate::allows for every conditional.
    $headerUser = auth()->user();
    $headerOrg = $headerUser?->currentOrganization();
    $headerCanUpdateSite = (bool) $headerUser?->can('update', $site);
    $headerCanDeleteSite = (bool) $headerUser?->can('delete', $site);
    $headerIsDeployer = (bool) $headerOrg?->userIsDeployer($headerUser);
    $headerIsAdmin = (bool) $headerOrg?->hasAdminAccess($headerUser);
    $headerRoleLabel = match (true) {
        $headerIsAdmin => null, // admins/owners get no badge — full power is the default
        $headerIsDeployer => __('Deployer'),
        $headerCanUpdateSite => __('Editor'),
        default => __('Read-only'),
    };
    $headerRoleTone = match (true) {
        $headerIsDeployer => 'bg-amber-100 text-amber-900 ring-amber-200/60',
        $headerCanUpdateSite => 'bg-emerald-100 text-emerald-900 ring-emerald-200/60',
        default => 'bg-slate-100 text-slate-700 ring-slate-200/60',
    };

    // For read-only / deployer roles, swap the section's "Manage / Configure / …"
    // copy with a role-aware sentence so the user knows up-front why the editing
    // affordances are missing or disabled. Admin and editor roles see the
    // section's native description as written.
    $sectionDescription = $headerCanUpdateSite
        ? $sectionHeader['description']
        : ($headerIsDeployer
            ? __('Review this section — settings are read-only for the Deployer role. Use Deploy actions to ship changes.')
            : __('You have read-only access to this section — settings cannot be changed from this account.'));
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
            ['label' => __('Zero downtime'), 'value' => $site->deploy_strategy === 'atomic' ? __('Enabled') : __('Disabled')],
        ];
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    @php
        $settingsBreadcrumbs = [
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Servers'), 'href' => route('servers.index'), 'icon' => 'server-stack'],
        ];
        if ($server->workspace) {
            $settingsBreadcrumbs[] = [
                'label' => $server->workspace->name,
                'href' => route('projects.resources', $server->workspace),
                'icon' => 'rectangle-group',
            ];
        }
        $settingsBreadcrumbs[] = [
            'label' => $server->name,
            'href' => route('servers.overview', $server),
            'icon' => 'server-stack',
        ];
        $settingsBreadcrumbs[] = [
            'label' => $site->name,
            'href' => $section === 'general' ? null : route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']),
            'icon' => 'globe-alt',
        ];
        if ($section !== 'general') {
            // Match the breadcrumb tail to the section the user is on (e.g. "HTTP basic
            // authentication") instead of the generic "Site workspace" — keeps trail
            // and page header aligned.
            $settingsBreadcrumbs[] = ['label' => $sectionHeader['title'], 'icon' => 'cog-6-tooth'];
        }
    @endphp
    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <x-breadcrumb-trail :items="$settingsBreadcrumbs" />

            {{-- Page-level workspace eyebrow. Pairs the runtime family (Edge / Cloud /
                 Container / etc.) with the resource noun and lives ABOVE the per-section
                 page header so operators reading top-to-bottom see "Site workspace · General"
                 rather than landing on a bare "General" with no orientation. --}}
            <p class="mt-3 text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ $workspaceTitle }}</p>

            @if ($headerRoleLabel !== null)
                {{-- Role badge sits above the page-header title so a viewer/deployer
                     sees the access level before they look for save actions that
                     aren't there. Admins/owners get no badge — full power is the
                     default reading. --}}
                <div class="mb-2 flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset {{ $headerRoleTone }}"
                          title="{{ __('Your access level for this :resource', ['resource' => strtolower($resourceNoun)]) }}">
                        @if ($headerIsDeployer)
                            <x-heroicon-m-rocket-launch class="h-3 w-3" aria-hidden="true" />
                        @elseif ($headerCanUpdateSite)
                            <x-heroicon-m-pencil-square class="h-3 w-3" aria-hidden="true" />
                        @else
                            <x-heroicon-m-eye class="h-3 w-3" aria-hidden="true" />
                        @endif
                        {{ $headerRoleLabel }}
                    </span>
                </div>
            @endif

            <x-page-header
                :title="$sectionHeader['title']"
                :description="$sectionDescription"
                doc-route="docs.index"
                toolbar
                flush
            >
                <x-slot name="leading">
                    {{-- Section icon: the same icon family the sidebar uses, so the title
                         and the active sidebar item visually agree. --}}
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                        @svg($sectionHeader['icon'], 'h-7 w-7 text-brand-ink')
                    </span>
                </x-slot>
                {{-- Web server config / Insights / Monitor links removed from the section
                     header — they're already in the sidebar / dedicated routes. The
                     header keeps just the Documentation link (rendered by <x-page-header>
                     when doc-route is set) so the action area stays focused on docs. --}}
            </x-page-header>

            <main class="min-w-0 space-y-6 mt-8">
                {{-- Single console-actions banner. Each Settings section declares the kinds
                     it cares about in config/console_actions.php#section_kinds; sections
                     without an entry (notifications, logs, environment, …) render no
                     banner so unrelated runs don't leak across tabs. Newer runs supersede
                     older ones; dismiss hides the current one until the next run starts. --}}
                @php
                    $sectionKinds = (array) (config('console_actions.section_kinds.'.$section, []));
                    $consoleActionRun = $sectionKinds === [] ? null : \App\Models\ConsoleAction::query()
                        ->where('subject_type', $site->getMorphClass())
                        ->where('subject_id', $site->id)
                        ->whereIn('kind', $sectionKinds)
                        ->whereNull('dismissed_at')
                        ->orderByDesc('created_at')
                        ->first();
                @endphp
                @if ($sectionKinds !== [])
                    @include('livewire.partials.console-action-banner-static', [
                        'run' => $consoleActionRun,
                        'kindLabels' => (array) config('console_actions.kinds', []),
                    ])
                @endif

                <div role="tabpanel" id="site-settings-panel" aria-labelledby="site-settings-sidebar" class="space-y-6">
                    @if ($section === 'general' && $site->usesContainerRuntime())
                        @include('livewire.sites.partials.container-dashboard')
                    @endif

                    @if ($section === 'general')
                        {{-- Read-only overview. Edit affordances live elsewhere:
                             primary hostname → Routing > Domains (pencil on the row);
                             everything else → Settings tab. --}}
                        <section class="dply-card overflow-hidden">
                            <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
                                <div class="border-b border-brand-ink/10 bg-brand-sand/15 p-6 lg:border-b-0 lg:border-r">
                                    <div class="flex items-start gap-3">
                                        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                                            <x-heroicon-o-globe-alt class="h-5 w-5" />
                                        </span>
                                        <div class="min-w-0">
                                            <h2 class="text-lg font-semibold text-brand-ink">{{ $generalOverviewTitle }}</h2>
                                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                                {{ __('At-a-glance summary. Edit the primary hostname from Routing > Domains; everything else lives in Settings.') }}
                                            </p>
                                        </div>
                                    </div>
                                    @if ($testingHostname !== '')
                                        <div class="mt-5 rounded-xl border border-brand-ink/10 bg-white p-4">
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ $runtimeMode === 'vm' ? __('Testing URL') : __('Temporary hostname') }}</p>
                                            <p class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $testingHostname }}</p>
                                        </div>
                                    @endif
                                </div>

                                <div class="p-6 sm:p-8">
                                    <div class="grid gap-5">
                                        <div>
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ $primaryHostnameLabel }}</p>
                                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                                <span class="break-all font-mono text-sm text-brand-ink">{{ $settings_primary_domain !== '' ? $settings_primary_domain : '—' }}</span>
                                                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing', 'tab' => 'domains']) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage underline decoration-brand-sage/30 hover:decoration-brand-sage">
                                                    <x-heroicon-o-pencil-square class="h-3 w-3" />
                                                    {{ __('Edit in Routing') }}
                                                </a>
                                            </div>
                                        </div>

                                        <div>
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ $documentRootLabel }}</p>
                                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                                <span class="break-all font-mono text-sm text-brand-ink">{{ $settings_document_root !== '' ? $settings_document_root : '—' }}</span>
                                                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'settings']) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage underline decoration-brand-sage/30 hover:decoration-brand-sage">
                                                    <x-heroicon-o-pencil-square class="h-3 w-3" />
                                                    {{ __('Edit in Settings') }}
                                                </a>
                                            </div>
                                        </div>

                                        <dl class="grid grid-cols-1 gap-4 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm sm:grid-cols-2">
                                            @foreach ($summaryCards as $card)
                                                <div>
                                                    <dt class="text-brand-mist">{{ $card['label'] }}</dt>
                                                    <dd class="mt-1 break-all font-medium text-brand-ink">{{ $card['value'] }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="dply-card overflow-hidden">
                            <div class="flex flex-col gap-4 border-b border-brand-ink/10 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                                        <x-heroicon-o-chart-bar class="h-5 w-5" />
                                    </span>
                                    <div class="min-w-0">
                                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Status') }}</h2>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('At-a-glance deploy, runtime, and certificate state. Detailed editors live on the dedicated tabs.') }}</p>
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-code-bracket-square class="h-3.5 w-3.5" />
                                        {{ __('Deploy') }}
                                    </a>
                                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-cube-transparent class="h-3.5 w-3.5" />
                                        {{ __('Runtime') }}
                                    </a>
                                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'certificates']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-shield-check class="h-3.5 w-3.5" />
                                        {{ __('Certificates') }}
                                    </a>
                                </div>
                            </div>

                            <div class="p-6 sm:p-8">
                                <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    @if ($this->latestDeployment !== null)
                                        @php
                                            // Tone-coded badge: failed deploys rose, running sky, success emerald.
                                            // Tests assert the bg-rose-100 class for failed deploys so the badge
                                            // colour is part of the contract, not just a decorative cue.
                                            $latestStatus = (string) $this->latestDeployment->status;
                                            $latestTone = match ($latestStatus) {
                                                'failed' => 'bg-rose-100 text-rose-800',
                                                'running' => 'bg-sky-100 text-sky-800',
                                                'success' => 'bg-emerald-100 text-emerald-800',
                                                default => 'bg-brand-sand/60 text-brand-ink',
                                            };
                                        @endphp
                                        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ __('Last deploy') }}</dt>
                                            <dd class="mt-2 text-sm font-medium text-brand-ink">
                                                <a href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $this->latestDeployment]) }}"
                                                    wire:navigate
                                                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize {{ $latestTone }} hover:opacity-90">
                                                    {{ $latestStatus }}
                                                </a>
                                                @if ($this->latestDeployment->started_at)
                                                    <span class="ml-1 text-xs font-normal text-brand-mist">· {{ $this->latestDeployment->started_at->diffForHumans(null, true) }}</span>
                                                @endif
                                            </dd>
                                            <dd class="mt-1 text-xs">
                                                <a href="{{ route('sites.deployments.index', ['server' => $server, 'site' => $site]) }}" wire:navigate class="font-medium text-brand-sage hover:underline">{{ __('All deploys') }}</a>
                                            </dd>
                                        </div>
                                    @endif
                                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ __('Runtime') }}</dt>
                                        <dd class="mt-2 text-sm font-medium text-brand-ink">
                                            @if ($site->runtimeKey())
                                                <span class="capitalize">{{ $site->runtimeKey() }}</span>@if ($site->runtimeVersion())
                                                    <span class="font-mono text-brand-mist"> · {{ $site->runtimeVersion() }}</span>
                                                @endif
                                            @else
                                                <span class="text-brand-mist">—</span>
                                            @endif
                                        </dd>
                                    </div>
                                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ __('Preflight') }}</dt>
                                        <dd class="mt-2 text-sm font-medium">
                                            @if ($preflightErrors->isEmpty() && $preflightWarnings->isEmpty())
                                                <span class="inline-flex items-center gap-1.5 text-emerald-700">
                                                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-600"></span>
                                                    {{ __('Ready') }}
                                                </span>
                                            @elseif ($preflightErrors->isNotEmpty())
                                                <span class="inline-flex items-center gap-1.5 text-rose-700">
                                                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-rose-600"></span>
                                                    {{ trans_choice('{1} :count blocker|[2,*] :count blockers', $preflightErrors->count(), ['count' => $preflightErrors->count()]) }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 text-amber-700">
                                                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                                    {{ trans_choice('{1} :count warning|[2,*] :count warnings', $preflightWarnings->count(), ['count' => $preflightWarnings->count()]) }}
                                                </span>
                                            @endif
                                        </dd>
                                    </div>
                                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ __('SSL') }}</dt>
                                        <dd class="mt-2 text-sm font-medium text-brand-ink">{{ $site->currentSslSummary() }}</dd>
                                    </div>
                                </dl>

                                @if (in_array($site->runtime, ['node', 'static'], true))
                                    <div class="mt-5 rounded-xl border border-brand-sage/30 bg-brand-sage/10 p-3 text-xs text-brand-ink">
                                        <span class="font-semibold text-brand-forest">{{ __('Edge-eligible') }}</span> —
                                        <span class="text-brand-moss">{{ __('this :runtime site can deploy globally on dply edge — managed HTTPS, auto-scaling, no VM to babysit.', ['runtime' => $site->runtime]) }}</span>
                                        <a href="{{ route('edge.create') }}" wire:navigate class="ml-1 font-medium text-brand-forest underline decoration-brand-sage/40 hover:decoration-brand-sage">{{ __('Deploy to dply edge') }} →</a>
                                    </div>
                                @endif
                            </div>
                        </section>

                        <section class="dply-card overflow-hidden">
                            <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
                                <div class="border-b border-brand-ink/10 bg-brand-sand/15 p-6 lg:border-b-0 lg:border-r">
                                    <div class="flex items-start gap-3">
                                        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                                            <x-heroicon-o-identification class="h-5 w-5" />
                                        </span>
                                        <div class="min-w-0">
                                            <h2 class="text-lg font-semibold text-brand-ink">{{ $detailsTitle }}</h2>
                                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                                {{ $detailsDescription }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="p-6 sm:p-8">
                                    @php
                                        $diskUsageBytes = data_get($site->meta, 'disk_usage.bytes');
                                    @endphp
                                    <dl class="grid grid-cols-1 gap-5 text-sm sm:grid-cols-2">
                                        <div>
                                            <dt class="text-brand-mist">{{ __('Created at') }}</dt>
                                            <dd class="mt-1 font-medium text-brand-ink">{{ $site->created_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-brand-mist">{{ __('Site ID') }}</dt>
                                            <dd class="mt-1 font-mono text-xs font-medium text-brand-ink">{{ $site->id }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-brand-mist">{{ __('Stack') }}</dt>
                                            <dd class="mt-1 font-medium text-brand-ink">{{ $site->type->label() }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-brand-mist">{{ __('Disk usage') }}</dt>
                                            <dd class="mt-1 font-medium text-brand-ink">
                                                {{ is_numeric($diskUsageBytes) ? \Illuminate\Support\Number::fileSize((int) $diskUsageBytes) : __('Not recorded yet') }}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </section>

                        @if (data_get($site->meta, 'notes'))
                            <section class="dply-card overflow-hidden">
                                <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
                                    <div class="border-b border-brand-ink/10 bg-brand-sand/15 p-6 lg:border-b-0 lg:border-r">
                                        <div class="flex items-start gap-3">
                                            <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                                                <x-heroicon-o-pencil-square class="h-5 w-5" />
                                            </span>
                                            <div class="min-w-0">
                                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Site notes') }}</h2>
                                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'settings']) }}" wire:navigate class="font-medium text-brand-sage underline decoration-brand-sage/30 hover:decoration-brand-sage">{{ __('Edit in Settings') }}</a>
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="p-6 sm:p-8">
                                        <p class="whitespace-pre-wrap text-sm leading-relaxed text-brand-ink">{{ data_get($site->meta, 'notes') }}</p>
                                    </div>
                                </div>
                            </section>
                        @endif

                        <x-cli-snippet :commands="[
                            ['label' => __('Print primary URL'), 'command' => 'dply:site:url '.$site->slug],
                            ['label' => __('Diagnose site'), 'command' => 'dply:site:doctor '.$site->slug],
                            ['label' => __('Rename site'), 'command' => 'dply:site:rename '.$site->slug.' --name=\'New name\' --slug=new-slug'],
                            ['label' => __('Export full config'), 'command' => 'dply:site:export-config '.$site->slug.' --to=site.json'],
                            ['label' => __('Export deploy manifest'), 'command' => 'dply:site:export-manifest '.$site->slug.' --to=manifest.json'],
                            ['label' => __('List all sites'), 'command' => 'dply:site:list'],
                        ]" />

                        @php
                            // Recent deployments with structured phase_results (set by the deploy
                            // runner). Predates-the-runner rows are excluded so the panel only
                            // surfaces drill-into-able deploys. Capped at 10 — Deploys page has
                            // pagination for older history.
                            $deployments = $site->deployments
                                ->filter(fn (\App\Models\SiteDeployment $d): bool => is_array($d->phase_results) && $d->phase_results !== [])
                                ->take(10)
                                ->values();
                        @endphp
                        @if ($deployments->isNotEmpty())
                            <div class="mt-6">
                                @include('livewire.sites.partials.recent-deployments')
                            </div>
                        @endif
                    @elseif ($section === 'settings')
                        @include('livewire.sites.settings.partials.settings-tab')
                    @elseif ($section === 'routing')
                        @include('livewire.sites.settings.partials.routing')
                    @elseif ($section === 'dns')
                        @include('livewire.sites.settings.partials.dns')
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
                                    <a href="{{ route('sites.commits', [$server, $site]) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Commits') }}</a>
                                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'logs']) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open site logs') }}</a>
                                    <a href="{{ route('servers.logs', $server) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open server logs') }}</a>
                                </div>
                            </div>

                            @include('livewire.sites.settings.partials.engine-http-cache')

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
                                <form wire:submit="saveZeroDowntimeDeployment" class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                                    <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0 flex-1">
                                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Zero downtime deployment') }}</h3>
                                            <p class="mt-1 text-sm text-brand-moss">{{ __('When enabled, each deploy goes to a new release directory, then traffic switches to it in one step so the app stays up during builds. Disable to run simple git-based deploys in the deploy path.') }}</p>
                                            <x-input-error :messages="$errors->get('zero_downtime_enabled')" class="mt-2" />
                                        </div>
                                        <label class="flex shrink-0 items-center gap-2 text-sm font-medium text-brand-ink">
                                            <input type="checkbox" wire:model="zero_downtime_enabled" class="rounded border-slate-300 text-brand-forest shadow-sm focus:ring-brand-forest">
                                            {{ __('Enable') }}
                                        </label>
                                    </div>
                                    <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/30 px-5 py-3">
                                        <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                                    </div>
                                </form>

                                <form wire:submit="saveDeploymentSettings" class="mt-6 space-y-4 rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                                    @if ($zero_downtime_enabled)
                                        <div class="space-y-4 rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                                            <div>
                                                <h3 class="text-base font-semibold text-brand-ink">{{ __('After deploy verification') }}</h3>
                                                <p class="mt-1 text-sm text-brand-moss">{{ __('Optional HTTP(S) check from the server to a local address with your primary hostname as the Host header after the new release is active. Defaults to http://127.0.0.1. Requires a primary domain and a route that returns the expected status (for example Laravel /up).') }}</p>
                                            </div>
                                            <label class="flex items-center gap-2 text-sm font-medium text-brand-ink">
                                                <input type="checkbox" wire:model="deploy_health_enabled" class="rounded border-slate-300 text-brand-forest shadow-sm focus:ring-brand-forest">
                                                {{ __('Run health check after each atomic deploy') }}
                                            </label>
                                            <label class="flex items-center gap-2 text-sm font-medium text-brand-ink">
                                                <input type="checkbox" wire:model="deploy_health_auto_rollback" class="rounded border-slate-300 text-brand-forest shadow-sm focus:ring-brand-forest" @disabled(! $deploy_health_enabled)>
                                                {{ __('Automatically point current back at the previous release if the check fails') }}
                                            </label>
                                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                                <div>
                                                    <x-input-label for="deploy_health_scheme" value="{{ __('URL scheme') }}" />
                                                    <select id="deploy_health_scheme" wire:model="deploy_health_scheme" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm" @disabled(! $deploy_health_enabled)>
                                                        <option value="http">http</option>
                                                        <option value="https">https</option>
                                                    </select>
                                                    <x-input-error :messages="$errors->get('deploy_health_scheme')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="deploy_health_host" value="{{ __('Target host') }}" />
                                                    <x-text-input id="deploy_health_host" wire:model="deploy_health_host" class="font-mono text-sm" placeholder="127.0.0.1" :disabled="! $deploy_health_enabled" />
                                                    <x-input-error :messages="$errors->get('deploy_health_host')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="deploy_health_port" value="{{ __('Target port (optional)') }}" />
                                                    <x-text-input id="deploy_health_port" type="number" wire:model="deploy_health_port" class="font-mono text-sm" placeholder="80 / 443 / custom" min="1" max="65535" :disabled="! $deploy_health_enabled" />
                                                    <p class="mt-1 text-xs text-brand-moss">{{ __('Leave empty to use the default port for the scheme (80 or 443).') }}</p>
                                                    <x-input-error :messages="$errors->get('deploy_health_port')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="deploy_health_path" value="{{ __('Health path') }}" />
                                                    <x-text-input id="deploy_health_path" wire:model="deploy_health_path" class="font-mono text-sm" placeholder="/health" :disabled="! $deploy_health_enabled" />
                                                    <x-input-error :messages="$errors->get('deploy_health_path')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="deploy_health_expect_status" value="{{ __('Expected HTTP status') }}" />
                                                    <x-text-input id="deploy_health_expect_status" type="number" wire:model="deploy_health_expect_status" class="w-24" min="100" max="599" :disabled="! $deploy_health_enabled" />
                                                    <x-input-error :messages="$errors->get('deploy_health_expect_status')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="deploy_health_attempts" value="{{ __('Attempts') }}" />
                                                    <x-text-input id="deploy_health_attempts" type="number" wire:model="deploy_health_attempts" class="w-24" min="1" max="30" :disabled="! $deploy_health_enabled" />
                                                    <x-input-error :messages="$errors->get('deploy_health_attempts')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="deploy_health_delay_ms" value="{{ __('Delay between attempts (ms)') }}" />
                                                    <x-text-input id="deploy_health_delay_ms" type="number" wire:model="deploy_health_delay_ms" class="w-28" min="0" max="10000" step="50" :disabled="! $deploy_health_enabled" />
                                                    <x-input-error :messages="$errors->get('deploy_health_delay_ms')" class="mt-1" />
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    <div>
                                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Rollout and web server') }}</h3>
                                        <p class="mt-1 text-sm text-brand-moss">{{ __('Releases to keep, environment group, PHP-FPM, cron, Supervisor, and extra Nginx directives. Stack-specific options (for example Octane) appear when detection matches.') }}</p>
                                    </div>

                                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                        <div>
                                            <x-input-label for="releases_to_keep" value="Releases to keep" />
                                            <x-text-input id="releases_to_keep" type="number" wire:model="releases_to_keep" class="mt-1 w-28" min="1" max="50" />
                                            <p class="mt-2 text-sm text-brand-moss">{{ __('Applies when zero downtime deployment is enabled.') }}</p>
                                        </div>
                                        <div>
                                            <x-input-label for="deployment_environment" value="Environment group" />
                                            <x-text-input id="deployment_environment" wire:model="deployment_environment" class="mt-1 block w-full text-sm" />
                                            <p class="mt-2 text-sm text-brand-moss">{{ __('Used when resolving key/value environment variables for deploys.') }}</p>
                                        </div>
                                        @if ($site->shouldShowPhpOctaneRolloutSettings() && $site->shouldShowOctaneRuntimeUi())
                                            <div>
                                                <x-input-label for="octane_port" value="Octane port" />
                                                <x-text-input id="octane_port" wire:model="octane_port" placeholder="8000" class="mt-1 block w-full font-mono text-sm" />
                                                <p class="mt-1 text-xs text-brand-moss">{{ __('Mirrors Runtime settings; used for Supervisor `octane:start` command line.') }}</p>
                                            </div>
                                            <div>
                                                <x-input-label for="octane_server" value="{{ __('Octane application server') }}" />
                                                <select id="octane_server" wire:model="octane_server" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                                    @foreach (\App\Models\Site::OCTANE_SERVERS as $server)
                                                        <option value="{{ $server }}">{{ str($server)->replace('_', ' ')->title() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif
                                        @if ($site->shouldShowPhpOctaneRolloutSettings() && $site->shouldShowLaravelReverbRuntimeUi())
                                            <div>
                                                <x-input-label for="laravel_reverb_port_deploy" value="{{ __('Reverb port') }}" />
                                                <x-text-input id="laravel_reverb_port_deploy" type="number" wire:model="laravel_reverb_port" placeholder="8080" class="mt-1 block w-full max-w-xs font-mono text-sm" min="1" max="65535" />
                                                <p class="mt-1 text-xs text-brand-moss">{{ __('Mirrors Laravel stack settings; used for Supervisor and managed web server WebSocket proxies.') }}</p>
                                            </div>
                                            <div>
                                                <x-input-label for="laravel_reverb_ws_path_deploy" value="{{ __('Reverb WebSocket path') }}" />
                                                <x-text-input id="laravel_reverb_ws_path_deploy" wire:model="laravel_reverb_ws_path" placeholder="/app" class="mt-1 block w-full max-w-xs font-mono text-sm" />
                                            </div>
                                        @endif
                                        @if ($site->shouldShowRailsRuntimeSettings())
                                            <div class="sm:col-span-2">
                                                <x-input-label for="rails_env_deploy" value="RAILS_ENV" />
                                                <x-text-input id="rails_env_deploy" wire:model="rails_env" class="mt-1 block w-full max-w-md font-mono text-sm" placeholder="production" />
                                                <p class="mt-1 text-xs text-brand-moss">{{ __('Same value as Settings → Runtime. Stored for deploy scripts and operator reference.') }}</p>
                                                <x-input-error :messages="$errors->get('rails_env')" class="mt-1" />
                                            </div>
                                        @endif
                                        @if (! $this->shouldShowSystemUserPanel())
                                            <div>
                                                <x-input-label for="php_fpm_user" value="PHP-FPM pool user" />
                                                <x-text-input id="php_fpm_user" wire:model="php_fpm_user" class="mt-1 block w-full text-sm" placeholder="www-data" />
                                            </div>
                                        @endif
                                        <div class="lg:col-span-2 rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                                            <p class="text-sm font-semibold text-brand-ink">{{ __('Rollback context') }}</p>
                                            <p class="mt-1 text-sm text-brand-moss">{{ __('Deploy history and release rollback stay on the site overview page so operators can launch, inspect, and recover from one place.') }}</p>
                                        </div>
                                    </div>

                                    <div class="grid gap-3">
                                        <div>
                                            <label class="flex items-center gap-2 text-sm text-brand-ink">
                                                <input type="checkbox" wire:model="laravel_scheduler" class="rounded border-slate-300">
                                                {{ $site->runtimeSchedulerRolloutFormLabel() }}
                                            </label>
                                            @if ($site->runtimeSchedulerCheckboxHelp())
                                                <p class="mt-1 pl-6 text-xs text-brand-moss">{{ $site->runtimeSchedulerCheckboxHelp() }}</p>
                                            @endif
                                        </div>
                                        <label class="flex items-center gap-2 text-sm text-brand-ink">
                                            <input type="checkbox" wire:model="restart_supervisor_programs_after_deploy" class="rounded border-slate-300">
                                            {{ __('Restart Supervisor programs after successful deploy') }}
                                        </label>
                                        <div>
                                            <x-input-label for="nginx_extra_raw" value="Extra Nginx inside server block (advanced)" />
                                            <textarea id="nginx_extra_raw" wire:model="nginx_extra_raw" rows="4" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="# location /foo { ... }"></textarea>
                                        </div>
                                    </div>

                                    <x-primary-button type="submit">{{ __('Save rollout settings') }}</x-primary-button>
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
                                                            <span class="flex flex-wrap items-center gap-2">
                                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $step->phaseBadgeClass() }}">{{ $step->phase ?? 'build' }}</span>
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

                        <x-cli-snippet :commands="[
                            ['label' => __('Trigger deploy'), 'command' => 'dply:site:deploy '.$site->slug],
                            ['label' => __('Abort running deploy'), 'command' => 'dply:site:abort-deploy '.$site->slug],
                            ['label' => __('Run a single phase'), 'command' => 'dply:site:run-phase '.$site->slug.' build'],
                            ['label' => __('Recent deploy history'), 'command' => 'dply:site:deploy-history '.$site->slug],
                            ['label' => __('Inspect a deploy'), 'command' => 'dply:site:show-deploy DEPLOYMENT_ID --output'],
                        ]" />
                    @elseif ($section === 'repository')
                        @include('livewire.sites.settings.partials.repository')
                    @elseif ($section === 'runtime')
                        @include('livewire.sites.settings.partials.runtime')
                    @elseif ($section === 'runtime-php')
                        @include('livewire.sites.settings.partials.runtime.php')
                    @elseif ($section === 'runtime-ruby')
                        @include('livewire.sites.settings.partials.runtime.ruby')
                    @elseif ($section === 'runtime-static')
                        @include('livewire.sites.settings.partials.runtime.static')
                    @elseif ($section === 'system-user')
                        @include('livewire.sites.settings.partials.system-user')
                    @elseif ($section === 'laravel-stack')
                        @include('livewire.sites.settings.partials.laravel-stack')
                    @elseif ($section === 'rails-stack')
                        @include('livewire.sites.settings.partials.rails.workspace')
                    @elseif ($section === 'wordpress')
                        @livewire('sites.wordpress.wordpress-section', ['site' => $site], key('wordpress-section-'.$site->id))
                    @elseif ($section === 'environment')
                        @include('livewire.sites.settings.partials.environment')
                    @elseif ($section === 'logs')
                        @include('livewire.sites.settings.partials.logs')
                    @elseif ($section === 'notifications')
                        @include('livewire.sites.settings.partials.notifications')
                    @elseif ($section === 'basic-auth')
                        @include('livewire.sites.settings.partials.basic-auth')
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
            panelClass="dply-modal-panel"
            focusable
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Quick SSL') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add SSL for this hostname') }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('Create a certificate request without leaving the routing workspace. Use this when the hostname already resolves here and is ready for HTTP validation.') }}
                </p>
            </div>

            <div class="space-y-5 px-6 py-6">
                <div class="rounded-xl border border-brand-ink/10 bg-slate-50/70 px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Hostname') }}</p>
                    <p class="mt-2 font-mono text-sm text-brand-ink">{{ $quick_ssl_domain_hostname ?: __('No hostname selected') }}</p>
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

        <x-modal
            name="laravel-ssh-setup-modal"
            :show="false"
            maxWidth="lg"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel"
            focusable
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Remote setup') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Run this command on the server?') }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('This executes once over SSH in your site’s deploy directory. Ensure backups and that you trust this environment.') }}
                </p>
            </div>

            <div class="space-y-4 px-6 py-6">
                @if ($this->laravelSshSetupPendingCommandPreview())
                    <div class="rounded-xl border border-brand-ink/10 bg-slate-50/70 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Command') }}</p>
                        <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap break-all font-mono text-xs text-brand-ink">{{ $this->laravelSshSetupPendingCommandPreview() }}</pre>
                    </div>
                @endif
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeLaravelSshSetupModal">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <x-primary-button type="button" wire:click="confirmLaravelSshSetup" wire:loading.attr="disabled" wire:target="confirmLaravelSshSetup">
                    <span wire:loading.remove wire:target="confirmLaravelSshSetup">{{ __('Run command') }}</span>
                    <span wire:loading wire:target="confirmLaravelSshSetup" class="inline-flex items-center justify-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Running…') }}
                    </span>
                </x-primary-button>
            </div>
        </x-modal>

        <x-modal
            name="site-system-user-assign-modal"
            :show="false"
            maxWidth="lg"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel"
            focusable
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('System user') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Assign existing user') }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('This updates file ownership under this site’s repository path and sets the PHP-FPM pool user. Ensure you have backups.') }}
                </p>
            </div>

            <div class="px-6 py-6">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Selected user') }}</p>
                <p class="mt-2 font-mono text-sm text-brand-ink">{{ $system_user_assign_username }}</p>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeSystemUserAssignModal">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="queueAssignSystemUser" wire:loading.attr="disabled" wire:target="queueAssignSystemUser">
                    <span wire:loading.remove wire:target="queueAssignSystemUser">{{ __('Confirm') }}</span>
                    <span wire:loading wire:target="queueAssignSystemUser" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Queueing…') }}
                    </span>
                </x-primary-button>
            </div>
        </x-modal>

        <x-modal
            name="site-reset-permissions-modal"
            :show="false"
            maxWidth="2xl"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel"
            focusable
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <div class="flex gap-4">
                    <div class="shrink-0 rounded-full bg-brand-forest/10 p-2 text-brand-forest">
                        <x-heroicon-o-information-circle class="h-7 w-7" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-xl font-semibold text-brand-ink">{{ __('Are you sure?') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Please read carefully before proceeding.') }}</p>
                    </div>
                </div>
            </div>

            <div class="max-h-[min(70vh,32rem)] space-y-5 overflow-y-auto px-6 py-6 text-sm leading-6 text-brand-ink">
                <div>
                    <p class="font-semibold text-brand-ink">{{ __('What will happen') }}</p>
                    <p class="mt-2 text-brand-moss">
                        {{ __('Choosing Reset will run a one-time job over SSH on this site’s repository path. Ownership is set to the effective system user and the web server group, then directories and files receive typical secure modes (755 / 644). If :storage and :cache exist, those trees use 775 / 664 so Laravel can write logs and compiled files.', ['storage' => 'storage/', 'cache' => 'bootstrap/cache/']) }}
                    </p>
                    <p class="mt-3 text-brand-moss">
                        {{ __('In this case, ownership will be user :user and group :group.', ['user' => $site->effectiveSystemUser($this->server), 'group' => config('site_settings.vm_site_file_web_group', 'www-data')]) }}
                    </p>
                </div>

                <div>
                    <p class="font-semibold text-brand-ink">{{ __('Why you might need this') }}</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-brand-moss">
                        <li>{{ __('Accidental chmod/chown changes broke deploys or HTTP access.') }}</li>
                        <li>{{ __('The site shows errors because PHP or the web server cannot read or write expected paths.') }}</li>
                        <li>{{ __('You want a known-good permission baseline before debugging further.') }}</li>
                    </ul>
                </div>

                <div>
                    <p class="font-semibold text-brand-ink">{{ __('Considerations') }}</p>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-brand-moss">
                        <li>{{ __('Custom permission tweaks under this path will be overwritten.') }}</li>
                        <li>{{ __('The change is immediate on the server and may disrupt a site that relied on non-standard permissions.') }}</li>
                        <li>{{ __('There is no automatic undo; restore from backups if you need the previous state.') }}</li>
                        <li>{{ __('This targets the repository path only; it does not change pool config elsewhere on the server.') }}</li>
                    </ol>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeSystemUserResetPermissionsModal">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="queueResetSitePermissions" wire:loading.attr="disabled" wire:target="queueResetSitePermissions">
                    <span wire:loading.remove wire:target="queueResetSitePermissions">{{ __('Reset') }}</span>
                    <span wire:loading wire:target="queueResetSitePermissions" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" class="h-4 w-4" />
                        {{ __('Queueing…') }}
                    </span>
                </x-primary-button>
            </div>
        </x-modal>
    </x-slot>

    {{-- IMPORTANT: keep the confirm-action-modal include OUTSIDE the modals slot.
         Slot content is captured by the layout on the initial GET only — Livewire
         AJAX updates re-render only the component (no layout), so anything inside
         <x-slot name="modals"> never updates after first paint. The confirm modal
         is Livewire-driven (its visibility flips on $showConfirmActionModal), so
         it has to live in the component's main render output to actually appear
         when the operator clicks a "destructive action" trigger. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
