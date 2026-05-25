<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <x-breadcrumb-trail :items="[
                ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
                ['label' => __('Edge'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
                ['label' => $site->name, 'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-deploys'])],
                ['label' => __('Deployment'), 'icon' => 'code-bracket-square'],
            ]" />

            <header class="mt-5 flex flex-wrap items-start justify-between gap-4 border-b border-brand-ink/10 pb-5">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Edge deployment') }}</p>
                    <h1 class="mt-1 font-mono text-lg font-semibold text-brand-ink break-all">{{ $deployment->id }}</h1>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        @php
                            $depBadge = match ($deployment->status) {
                                \App\Models\EdgeDeployment::STATUS_LIVE => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
                                \App\Models\EdgeDeployment::STATUS_FAILED => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
                                \App\Models\EdgeDeployment::STATUS_BUILDING, \App\Models\EdgeDeployment::STATUS_PUBLISHING => 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300',
                                default => 'bg-brand-sand/60 text-brand-moss',
                            };
                        @endphp
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ $depBadge }}">
                            {{ str_replace('_', ' ', (string) $deployment->status) }}
                        </span>
                        @if ($isActiveDeployment)
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">{{ __('Current production') }}</span>
                        @endif
                        @if ($deployment->pruned_at)
                            <span class="inline-flex rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Pruned') }}</span>
                        @endif
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a
                        href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-deploys']) }}"
                        wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-arrow-left class="h-3.5 w-3.5" />
                        {{ __('All deploys') }}
                    </a>
                    @if (! $isActiveDeployment && in_array($deployment->status, [\App\Models\EdgeDeployment::STATUS_LIVE, \App\Models\EdgeDeployment::STATUS_SUPERSEDED], true) && $deployment->storage_prefix !== null)
                        @can('update', $site)
                            <button
                                type="button"
                                wire:click="confirmRollbackEdgeDeployment('{{ $deployment->id }}')"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90"
                            >
                                <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" />
                                {{ __('Roll back') }}
                            </button>
                        @endcan
                    @endif
                </div>
            </header>

            <x-server-workspace-tablist :aria-label="__('Deployment sections')" class="mt-6">
                @foreach ([
                    'overview' => __('Overview'),
                    'aliases' => __('Aliases'),
                    'log' => __('Build log'),
                ] as $tabKey => $tabLabel)
                    <x-server-workspace-tab
                        as="a"
                        :href="route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment, 'tab' => $tabKey])"
                        wire:navigate
                        :active="$tab === $tabKey"
                    >
                        {{ $tabLabel }}
                    </x-server-workspace-tab>
                @endforeach
            </x-server-workspace-tablist>

            <div class="mt-6 space-y-6">
                @if ($tab === 'overview')
                    <section class="dply-card overflow-hidden">
                        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Deployment details') }}</h2>
                        </div>
                        <dl class="divide-y divide-brand-ink/8 px-6 py-2 text-sm sm:px-8">
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Deployment ID') }}</dt>
                                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ $deployment->id }}</dd>
                            </div>
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Commit') }}</dt>
                                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ $deployment->git_commit ?: '—' }}</dd>
                            </div>
                            @if (! empty($commitMeta['subject']))
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Subject') }}</dt>
                                    <dd class="min-w-0 flex-1 text-brand-ink">{{ $commitMeta['subject'] }}</dd>
                                </div>
                            @endif
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Branch') }}</dt>
                                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ $deployment->git_branch ?? $edgeBranch ?? '—' }}</dd>
                            </div>
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Published') }}</dt>
                                <dd class="min-w-0 flex-1 text-brand-ink">{{ $deployment->published_at?->toDayDateTimeString() ?? '—' }}</dd>
                            </div>
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Storage prefix') }}</dt>
                                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ $deployment->storage_prefix ?: '—' }}</dd>
                            </div>
                        </dl>
                    </section>

                    @if ($deploymentAliases !== [])
                        <section class="dply-card overflow-hidden">
                            <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                                <div>
                                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Stable aliases') }}</h2>
                                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Permanent URLs that always resolve to this build.') }}</p>
                                </div>
                                <a
                                    href="{{ route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment, 'tab' => 'aliases']) }}"
                                    wire:navigate
                                    class="text-xs font-medium text-brand-sage hover:underline"
                                >
                                    {{ __('View all aliases →') }}
                                </a>
                            </div>
                            <ul class="divide-y divide-brand-ink/8 px-6 py-2 sm:px-8">
                                @foreach (array_slice($deploymentAliases, 0, 2) as $alias)
                                    <li class="py-3 font-mono text-xs text-brand-ink">{{ $alias }}</li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if ($deployment->status === \App\Models\EdgeDeployment::STATUS_FAILED)
                        @include('livewire.sites.partials.edge.build-log-lint-callout', [
                            'buildLog' => $buildLogForLint,
                            'failureReason' => $deployment->failure_reason,
                            'site' => $site,
                            'server' => $server,
                            'deployment' => $deployment,
                        ])
                    @endif
                @elseif ($tab === 'aliases')
                    <section class="dply-card overflow-hidden">
                        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Stable per-deploy aliases') }}</h2>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('These hostnames always route to this deployment via the Edge host map — even after production moves on.') }}</p>
                        </div>
                        @if ($deploymentAliases === [])
                            <div class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
                                {{ __('No aliases yet. Aliases are generated when a deployment publishes successfully.') }}
                            </div>
                        @else
                            <ul class="divide-y divide-brand-ink/8">
                                @foreach ($deploymentAliases as $alias)
                                    <li class="px-6 py-4 sm:px-8" wire:key="edge-alias-{{ $alias }}">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="font-mono text-sm text-brand-ink break-all">{{ $alias }}</p>
                                                <a href="https://{{ $alias }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-forest hover:underline dark:text-brand-sage">
                                                    {{ __('Open') }}
                                                    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" />
                                                </a>
                                            </div>
                                            <div class="flex shrink-0 items-center gap-2" x-data="{ copied: false }">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1.5 text-[11px] font-medium text-brand-moss hover:bg-brand-sand/40"
                                                    @click="navigator.clipboard.writeText(@js('https://'.$alias)); copied = true; setTimeout(() => copied = false, 2000)"
                                                >
                                                    <x-heroicon-o-clipboard class="h-3.5 w-3.5" />
                                                    <span x-show="!copied">{{ __('Copy URL') }}</span>
                                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1.5 text-[11px] font-medium text-brand-moss hover:bg-brand-sand/40"
                                                    @click="navigator.clipboard.writeText(@js($alias)); copied = true; setTimeout(() => copied = false, 2000)"
                                                >
                                                    <span x-show="!copied">{{ __('Copy host') }}</span>
                                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                                </button>
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @elseif ($tab === 'log')
                    @if ($deployment->status === \App\Models\EdgeDeployment::STATUS_FAILED)
                        <div class="mb-4">
                            @include('livewire.sites.partials.edge.build-log-lint-callout', [
                                'buildLog' => $buildLog,
                                'failureReason' => $deployment->failure_reason,
                                'site' => $site,
                                'server' => $server,
                                'deployment' => $deployment,
                            ])
                        </div>
                    @endif
                    <section class="dply-card overflow-hidden">
                        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Build log') }}</h2>
                        </div>
                        @if ($buildLog === null || $buildLog === '')
                            <div class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
                                {{ __('No build log stored for this deployment.') }}
                            </div>
                        @else
                            <pre class="max-h-[32rem] overflow-auto bg-brand-ink px-6 py-4 font-mono text-xs leading-relaxed text-brand-cream sm:px-8">{{ $buildLog }}</pre>
                        @endif
                    </section>
                @endif
            </div>
        </div>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
