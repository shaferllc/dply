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
    <div class="dply-page-shell pt-6">
        <x-breadcrumb-trail :items="$siteHeaderBreadcrumbs" />
    </div>
    <div class="dply-page-shell pt-4">
        <x-page-header
            :title="$readyForWorkspace
                ? ($site->usesEdgeRuntime() ? __('Edge site') : __('Site workspace'))
                : ($site->usesEdgeRuntime() ? __('Edge deployment') : __('Site setup'))"
            :description="$readyForWorkspace
                ? ($site->usesEdgeRuntime()
                    ? __('Manage builds, domains, deploys, and delivery for this Edge site.')
                    : __('Manage this site from one workspace with General as the default landing section.'))
                : ($site->usesEdgeRuntime()
                    ? __('Track the git build and Edge CDN publish until this site goes live.')
                    : __('Track provisioning steps and setup until this site is ready to receive traffic.'))"
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
                @if ($readyForWorkspace && $site->usesEdgeRuntime())
                    <x-outline-link :href="route('edge.index')" wire:navigate>
                        <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('All Edge sites') }}
                    </x-outline-link>
                    @if ($liveUrlForHeader = ($edgeLiveUrl ?? $site->edgeLiveUrl()))
                        <a
                            href="{{ $liveUrlForHeader }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink hover:bg-brand-sand/40"
                            title="{{ __('Open the live edge site in a new tab') }}"
                        >
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 opacity-70" />
                            {{ preg_replace('#^https?://#', '', $liveUrlForHeader) }}
                        </a>
                    @endif
                    @can('update', $site)
                        <button
                            type="button"
                            wire:click="redeployEdge"
                            wire:loading.attr="disabled"
                            wire:target="redeployEdge"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                        >
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="redeployEdge" />
                            <span wire:loading.remove wire:target="redeployEdge">{{ __('Deploy') }}</span>
                            <span wire:loading wire:target="redeployEdge">{{ __('Queuing…') }}</span>
                        </button>
                    @endcan
                @elseif ($readyForWorkspace && $site->workspace)
                    <x-outline-link :href="route('projects.resources', $site->workspace)" wire:navigate>
                        <x-heroicon-o-folder-open class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Open project') }}
                    </x-outline-link>
                @endif
                @if ($showWebserverConfigEditor && ! $site->isCustom() && ! $site->usesEdgeRuntime())
                    <x-outline-link :href="route('sites.webserver-config', [$server, $site])" wire:navigate>
                        <x-heroicon-o-server-stack class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Web server config') }}
                    </x-outline-link>
                @endif
                @if ($readyForWorkspace && ! $site->isCustom() && ! $site->usesEdgeRuntime())
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
                @if ($site->usesEdgeRuntime())
                    @include('livewire.sites.partials.show.edge-provisioning-journey')
                @else
                    @include('livewire.sites.partials.show.provisioning-journey')
                @endif
            @else
                @if ($site->usesEdgeRuntime())
                    @include('livewire.sites.partials.edge.overview')
                @else
                    @include('livewire.sites.partials.show.dashboard-header')

                    <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="dashboard_tab">

                    @if ($activeTab === 'overview')
                        <x-server-workspace-tab-panel id="site-panel-overview" labelled-by="site-tab-overview" panel-class="space-y-6">
                            @include('livewire.sites.partials.show.overview-tab')
                        </x-server-workspace-tab-panel>
                    @endif

                    @if ($activeTab === 'deploys')
                        <x-server-workspace-tab-panel id="site-panel-deploys" labelled-by="site-tab-deploys" panel-class="space-y-6">
                            @include('livewire.sites.partials.show.deploys-tab')
                        </x-server-workspace-tab-panel>
                    @endif

                    @if ($showRuntimeTab && $activeTab === 'runtime')
                        <x-server-workspace-tab-panel id="site-panel-runtime" labelled-by="site-tab-runtime" panel-class="space-y-6">
                            @include('livewire.sites.partials.show.runtime-tab')
                        </x-server-workspace-tab-panel>
                    @endif

                    @if ($activeTab === 'logs')
                        <x-server-workspace-tab-panel id="site-panel-logs" labelled-by="site-tab-logs">
                            @include('livewire.sites.partials.show.logs-tab')
                        </x-server-workspace-tab-panel>
                    @endif

                    @if ($showSslTab && $activeTab === 'ssl')
                        <x-server-workspace-tab-panel id="site-panel-ssl" labelled-by="site-tab-ssl">
                            @include('livewire.sites.partials.show.ssl-tab')
                        </x-server-workspace-tab-panel>
                    @endif

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
        </div>

        <x-slot name="modals">
            @include('livewire.partials.confirm-action-modal')
        </x-slot>
    </div>
</div>
