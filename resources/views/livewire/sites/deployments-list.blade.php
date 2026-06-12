<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail
        :items="$settingsBreadcrumbs"
        :site="$site"
        doc-contextual
        :contextual-doc-slug="$contextualDocSlug ?? null"
        class="mb-6"
    />

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ $workspaceTitle }}</p>
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
                :show-documentation="false"
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
            @php $consoleRun = $this->activeConsoleRun(); @endphp
            @if ($consoleRun !== null)
                <div
                    id="deploy-console-action-banner"
                    x-data="{}"
                    x-on:dply-console-action-focus.window="$nextTick(() => {
                        const el = document.getElementById('deploy-console-action-banner');
                        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    })"
                >
                    @include('livewire.partials.console-action-banner-static', [
                        'run' => $consoleRun,
                        'kindLabels' => (array) config('console_actions.kinds', []),
                    ])
                </div>
            @endif

            <x-ops-copilot-callout :site="$site" />

            @if ($site->server?->isDigitalOceanFunctionsHost())
                {{-- Serverless deploy hub: the journey component owns redeploy +
                     live watching, deploy-hooks is its own card below. The
                     tabbed VM UI doesn't apply here. --}}
                <livewire:serverless.journey
                    :server="$server"
                    :site="$site"
                    :embedded="true"
                    wire:key="deploy-journey-{{ $site->id }}"
                />

                <livewire:sites.deploy-hooks
                    :site="$site"
                    wire:key="deploy-hooks-{{ $site->id }}"
                />
            @elseif ($isVmDeployHub ?? false)
                @include('livewire.sites.partials.deployments._tabstrip')

                <div wire:key="deployments-panel-{{ $tab }}">
                    {{-- Tab switch shows the skeleton placeholder instantly
                         (client-side via wire:loading, no spinner) and swaps the
                         real panel in when setTab's single round-trip lands. --}}
                    <div class="hidden" wire:loading.class.remove="hidden" wire:target="setTab">
                        @include('livewire.sites.partials._panel-skeleton')
                    </div>
                    <div wire:loading.class="hidden" wire:target="setTab">
                    @if ($tab === \App\Livewire\Sites\DeploymentsList::TAB_OVERVIEW)
                        @include('livewire.sites.partials.deployments._overview-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_REPOSITORY)
                        @include('livewire.sites.partials.deployments._repository-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_DEPLOY)
                        @include('livewire.sites.partials.deployments._deploy-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_SYNC)
                        @include('livewire.sites.partials.deployments._sync-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_ENVIRONMENT)
                        @include('livewire.sites.settings.partials.environment')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_COMMITS)
                        @include('livewire.sites.partials.deployments._commits-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_FILES)
                        @include('livewire.sites.partials.deployments._files-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_BRANCHES)
                        @include('livewire.sites.partials.deployments._branches-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_PIPELINE)
                        @include('livewire.sites.partials.deployments._pipeline-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_ROLLOUT)
                        @include('livewire.sites.partials.deployments._rollout-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_RELEASES && $atomicReleases)
                        @include('livewire.sites.partials.deployments._releases-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_HISTORY)
                        @include('livewire.sites.partials.deployments._history-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_WEBHOOK)
                        @include('livewire.sites.partials.deployments._webhook-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_HOOKS)
                        @include('livewire.sites.partials.deployments._hooks-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_SETTINGS)
                        @include('livewire.sites.partials.deployments._settings-panel')
                    @else
                        @include('livewire.sites.partials.deployments._deploy-panel')
                    @endif
                    </div>
                </div>
            @else
                {{-- Fallback for runtimes that don't fit either bucket — just
                     show the history table (already brand-styled). --}}
                @include('livewire.sites.partials.deployments._history-panel')
            @endif

            <x-cli-snippet class="mt-6" :command="'dply sites:deployments '.$site->slug" />
            </main>
        </div>
    </div>

    @if ($isVmDeployHub ?? false)
        @include('livewire.partials.confirm-action-modal')
    @endif
</div>
