<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Deployments'),
        'currentIcon' => 'rocket-launch',
        'contextualDocSlug' => $contextualDocSlug ?? null,
    ])

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <section class="dply-card min-w-0 overflow-hidden p-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-rocket-launch"
                    :title="__('Deployments')"
                    :note="$isFunctionsDeployHub ?? false
                        ? __('Deploy, sync related functions, and review history.')
                        : __('Deploy, review history, and manage release settings.')"
                >
                    <x-slot:actions>
                        @include('livewire.sites.partials.header-role-badge')
                    </x-slot:actions>
                </x-workspace-panel-head>

            <main class="min-w-0">
            @php $consoleRun = $this->activeConsoleRun(); @endphp
            @if ($consoleRun !== null)
                <div
                    id="deploy-console-action-banner"
                    class="border-b border-brand-ink/10"
                    x-data="{}"
                    x-on:dply-console-action-focus.window="$nextTick(() => {
                        const el = document.getElementById('deploy-console-action-banner');
                        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    })"
                >
                    @include('livewire.partials.console-action-banner-static', [
                        'run' => $consoleRun,
                        'kindLabels' => (array) config('console_actions.kinds', []),
                        'embedded' => true,
                    ])
                </div>
            @endif

            @if ($isDeployHub ?? false)
                <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                @include('livewire.sites.partials.deployments._tabstrip')
            </div>

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
                        @if ($isFunctionsDeployHub ?? false)
                            <livewire:serverless.journey
                                :server="$server"
                                :site="$site"
                                :embedded="true"
                                wire:key="deploy-journey-{{ $site->id }}"
                            />
                        @else
                            @include('livewire.sites.partials.deployments._deploy-panel')
                        @endif
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_SYNC)
                        @include('livewire.sites.partials.deployments._sync-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_ENVIRONMENT)
                        @include('livewire.sites.settings.partials.environment', ['envMergedChrome' => true])
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
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_RELEASES && ($isFunctionsDeployHub ?? false))
                        {{-- A function's releases are the host's stored
                             revisions — same question, different substrate. --}}
                        @livewire('serverless.rollback-panel', ['site' => $site], key('serverless-rollback-'.$site->id))
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_RELEASES && $atomicReleases)
                        @include('livewire.sites.partials.deployments._releases-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_HISTORY)
                        @include('livewire.sites.partials.deployments._history-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_WEBHOOK)
                        @include('livewire.sites.partials.deployments._webhook-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_HOOKS)
                        @include('livewire.sites.partials.deployments._hooks-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_SCHEDULE)
                        @include('livewire.sites.partials.deployments._schedule-panel')
                    @elseif ($tab === \App\Livewire\Sites\DeploymentsList::TAB_SETTINGS)
                        @include('livewire.sites.partials.deployments._settings-panel')
                    @elseif ($isFunctionsDeployHub ?? false)
                        <livewire:serverless.journey
                            :server="$server"
                            :site="$site"
                            :embedded="true"
                            wire:key="deploy-journey-fallback-{{ $site->id }}"
                        />
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

            <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-4 sm:px-6">
                <x-cli-snippet :commands="$isFunctionsDeployHub ?? false
                    ? [
                        ['label' => __('Deploy'), 'command' => 'dply site deploy --site '.$site->id.' --follow'],
                        ['label' => __('List deployments'), 'command' => 'dply site deployments '.$site->slug],
                    ]
                    : [
                        ['label' => __('Deploy'), 'command' => 'dply sites:deploy '.$site->slug],
                        ['label' => __('List deployments'), 'command' => 'dply sites:deployments '.$site->slug],
                        ['label' => __('List commits'), 'command' => 'dply sites:commits '.$site->slug],
                    ]" />
            </div>
            </main>
            </section>
        </div>
    </div>

    @if ($isDeployHub ?? false)
        @include('livewire.partials.confirm-action-modal')
    @endif
</div>
