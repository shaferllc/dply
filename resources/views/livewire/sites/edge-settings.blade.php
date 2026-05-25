<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <x-breadcrumb-trail :items="$settingsBreadcrumbs" />

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ $workspaceTitle }}</p>
                @if ($site->edgeLiveUrl())
                    <div class="flex flex-wrap items-center gap-2">
                        <a
                            href="{{ $site->edgeLiveUrl() }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 font-mono text-[11px] text-brand-ink hover:bg-brand-sand/40"
                            title="{{ __('Open the live edge site in a new tab') }}"
                        >
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 opacity-70" />
                            {{ preg_replace('#^https?://#', '', $site->edgeLiveUrl()) }}
                        </a>
                        @can('update', $site)
                            <button
                                type="button"
                                wire:click="redeployEdge"
                                wire:loading.attr="disabled"
                                wire:target="redeployEdge"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-brand-ink px-2.5 py-1 text-[11px] font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                            >
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="redeployEdge" />
                                <span wire:loading.remove wire:target="redeployEdge">{{ __('Deploy') }}</span>
                                <span wire:loading wire:target="redeployEdge">{{ __('Queuing…') }}</span>
                            </button>
                        @endcan
                    </div>
                @endif
            </div>

            @if ($headerRoleLabel !== null)
                <div class="mt-3 flex items-center gap-2">
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
                class="mt-3"
            >
                <x-slot name="leading">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                        @svg($sectionHeader['icon'], 'h-7 w-7 text-brand-ink')
                    </span>
                </x-slot>
            </x-page-header>

            <main class="min-w-0 space-y-6 mt-8">
                @if ($sectionConsoleActionKinds !== [])
                    @include('livewire.partials.console-action-banner-static', [
                        'run' => $sectionConsoleActionRun,
                        'kindLabels' => (array) config('console_actions.kinds', []),
                    ])
                @endif

                <div role="tabpanel" id="site-settings-panel" aria-labelledby="site-settings-sidebar" class="space-y-6">
                    @if ($section === 'general')
                        @livewire('sites.edge.workspace.overview', ['server' => $server, 'site' => $site], key('edge-section-overview-'.$site->id))
                    @elseif ($section === 'edge-deploys')
                        @livewire('sites.edge.workspace.deploys', ['server' => $server, 'site' => $site], key('edge-section-deploys-'.$site->id))
                    @elseif ($section === 'edge-domains')
                        @livewire('sites.edge.workspace.domains', ['server' => $server, 'site' => $site], key('edge-section-domains-'.$site->id))
                    @elseif ($section === 'edge-build')
                        @livewire('sites.edge.workspace.build', ['server' => $server, 'site' => $site], key('edge-section-build-'.$site->id))
                    @elseif ($section === 'edge-deploy-triggers')
                        @livewire('sites.edge.workspace.deploy-triggers', ['server' => $server, 'site' => $site], key('edge-section-deploy-triggers-'.$site->id))
                    @elseif ($section === 'edge-delivery')
                        @livewire('sites.edge.workspace.delivery', ['server' => $server, 'site' => $site], key('edge-section-delivery-'.$site->id))
                    @elseif ($section === 'edge-routing')
                        @include('livewire.sites.partials.edge.routing')
                    @elseif ($section === 'edge-previews')
                        @livewire('sites.edge.workspace.previews', ['server' => $server, 'site' => $site], key('edge-section-previews-'.$site->id))
                    @elseif ($section === 'edge-billing')
                        @livewire('sites.edge.workspace.billing', ['server' => $server, 'site' => $site], key('edge-section-billing-'.$site->id))
                    @elseif ($section === 'edge-traffic')
                        @livewire('sites.edge.workspace.traffic', ['server' => $server, 'site' => $site], key('edge-section-traffic-'.$site->id))
                    @elseif ($section === 'edge-logs')
                        @livewire('sites.edge.workspace.logs', ['server' => $server, 'site' => $site], key('edge-section-logs-'.$site->id))
                    @elseif ($section === 'danger')
                        @livewire('sites.edge.workspace.danger', ['server' => $server, 'site' => $site], key('edge-section-danger-'.$site->id))
                    @endif
                </div>
            </main>
        </div>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
