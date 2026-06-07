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
        <x-breadcrumb-trail
            :items="$siteHeaderBreadcrumbs"
            doc-contextual
        />
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
            :show-documentation="false"
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
                            <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="redeployEdge" />
                            <span wire:loading.remove wire:target="redeployEdge">{{ __('Deploy') }}</span>
                            <span wire:loading wire:target="redeployEdge">{{ __('Queuing…') }}</span>
                        </button>
                    @endcan
                @elseif ($readyForWorkspace && $site->workspace)
                    @feature('surface.projects')
                        <x-outline-link :href="route('projects.resources', $site->workspace)" wire:navigate>
                            <x-heroicon-o-folder-open class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Open project') }}
                        </x-outline-link>
                    @endfeature
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
                <section class="dply-card overflow-hidden border-amber-200">
                    <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <x-icon-badge tone="amber">
                                <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Warning') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('PHP version mismatch') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('This site references PHP :version, but that version is not currently installed on this server.', ['version' => $sitePhpData['mismatch_version']]) }}</p>
                                <p class="mt-2 text-sm">
                                    <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="font-medium text-amber-900 underline">
                                        {{ __('Install or switch versions on the server PHP page') }}
                                    </a>
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            @if (! $readyForWorkspace)
                @if ($site->usesEdgeRuntime())
                    @include('livewire.sites.partials.show.edge-provisioning-journey')
                @elseif ($site->isScaffoldJourneyActive())
                    {{-- App install (scaffold) — its own flow, distinct from the
                         bare-site provisioning journey above. --}}
                    @include('livewire.sites.partials.show.scaffold-install-journey')
                @else
                    @include('livewire.sites.partials.show.provisioning-journey')
                @endif
            @else
                @if ($site->usesEdgeRuntime())
                    @include('livewire.sites.partials.edge.overview')
                @else
                    @include('livewire.sites.partials.show.dashboard-header')

                    {{-- App install (scaffold) running on an already-live site:
                         show the pipeline as a banner INSIDE the workspace, not a
                         full-page takeover — the site stays fully navigable. --}}
                    @if ($site->isScaffoldInstalling())
                        <div class="mt-6">
                            @include('livewire.sites.partials.show.scaffold-install-journey')
                        </div>
                    @endif

                    {{-- Repo connected but held for setup (env/resources) before the
                         first deploy — resume the wizard. Stays live; non-forcing. --}}
                    @if ($site->needsFirstDeploySetup())
                        @include('livewire.sites.partials.show.finish-setup-banner')
                    @endif

                    <x-ops-copilot-callout :site="$site" compact class="mt-6" />

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
            @endif
        </div>

        {{-- The page root is a plain <div>, not a component, so a named "modals"
             slot here is orphaned and silently dropped — which left the
             confirm-action modal (Cancel build, remove env var, …) never
             rendering. The partial teleports to <body>, so include it directly. --}}
        @include('livewire.partials.confirm-action-modal')
    </div>
</div>
