<div class="contents" @if ($watchingDeploymentId) wire:poll.3s="pollWatchingDeployment" @endif>
    <x-production-data-banner :connection="$connection" :writes-unlocked="$writesUnlocked">
        <x-slot:actions>
            <button type="button" wire:click="refresh" class="rounded-lg bg-amber-950/10 px-3 py-1.5 text-sm font-semibold hover:bg-amber-950/15">
                {{ __('Refresh') }}
            </button>
            <button type="button" wire:click="requestDeploy" class="rounded-lg bg-amber-950 px-3 py-1.5 text-sm font-semibold text-amber-50 hover:bg-amber-950/90">
                {{ __('Deploy') }}
            </button>
        </x-slot:actions>
    </x-production-data-banner>
    <x-production-data-nav :connection="$connection" />

    @include('components.production-write-confirm-modal')

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Production'), 'href' => route('live.sites.index'), 'icon' => 'exclamation-triangle'],
            ['label' => __('Sites'), 'href' => route('live.sites.index'), 'icon' => 'globe-alt'],
            ['label' => $site['name'] ?? $remoteSiteId, 'icon' => 'globe-alt'],
        ]" />

        @if ($error)
            <x-alert tone="danger">{{ $error }}</x-alert>
        @endif

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-brand-ink">{{ $site['name'] ?? $remoteSiteId }}</h1>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ $site['server_name'] ?? '' }}
                    @if (! empty($site['status']))
                        · {{ $site['status'] }}
                    @endif
                </p>
            </div>
        </div>

        <x-server-workspace-tablist>
            @foreach ($allTabs as $tabKey)
                <x-server-workspace-tab
                    :active="$tab === $tabKey"
                    :wire-click="'setTab(\''.$tabKey.'\')'"
                >
                    {{ __(str_replace('_', ' ', ucfirst($tabKey))) }}
                    @unless (in_array($tabKey, $implementedTabs, true))
                        <span class="ms-1 text-[10px] font-normal uppercase text-brand-mist">{{ __('API') }}</span>
                    @endunless
                </x-server-workspace-tab>
            @endforeach
        </x-server-workspace-tablist>

        <x-server-workspace-tab-panel>
            @if ($tab === 'overview')
                <dl class="grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        'id' => __('ID'),
                        'slug' => __('Slug'),
                        'type' => __('Type'),
                        'runtime' => __('Runtime'),
                        'runtime_version' => __('Runtime version'),
                        'deploy_strategy' => __('Deploy strategy'),
                        'document_root' => __('Document root'),
                        'git_repository_url' => __('Repository'),
                        'git_branch' => __('Branch'),
                        'ssl_status' => __('SSL'),
                        'last_deploy_at' => __('Last deploy'),
                    ] as $key => $label)
                        <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ $label }}</dt>
                            <dd class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $site[$key] ?? '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            @elseif ($tab === 'deployments')
                @if ($watchingDeployment)
                    <div class="mb-4 rounded-xl border border-amber-600/30 bg-amber-50 p-4 text-sm text-amber-950">
                        <p class="font-semibold">{{ __('Watching deployment :id', ['id' => $watchingDeployment['id'] ?? '']) }}</p>
                        <p class="mt-1">{{ __('Status') }}: {{ $watchingDeployment['status'] ?? '—' }}</p>
                        @if (! empty($watchingDeployment['log_output']))
                            <pre class="mt-3 max-h-64 overflow-auto rounded-lg bg-brand-ink p-3 text-xs text-brand-cream">{{ $watchingDeployment['log_output'] }}</pre>
                        @endif
                    </div>
                @endif

                <div class="dply-card overflow-hidden">
                    @if (count($deployments) === 0)
                        <div class="px-6 py-10 text-center text-sm text-brand-moss">{{ __('No deployments returned.') }}</div>
                    @else
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead class="bg-brand-sand/30 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                <tr>
                                    <th class="px-4 py-3">{{ __('ID') }}</th>
                                    <th class="px-4 py-3">{{ __('Status') }}</th>
                                    <th class="px-4 py-3">{{ __('Trigger') }}</th>
                                    <th class="px-4 py-3">{{ __('SHA') }}</th>
                                    <th class="px-4 py-3">{{ __('Started') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10">
                                @foreach ($deployments as $deployment)
                                    <tr>
                                        <td class="px-4 py-3 font-mono text-xs">{{ $deployment['id'] ?? '—' }}</td>
                                        <td class="px-4 py-3">{{ $deployment['status'] ?? '—' }}</td>
                                        <td class="px-4 py-3">{{ $deployment['trigger'] ?? '—' }}</td>
                                        <td class="px-4 py-3 font-mono text-xs">{{ $deployment['git_sha'] ?? '—' }}</td>
                                        <td class="px-4 py-3 text-brand-moss">{{ $deployment['started_at'] ?? $deployment['created_at'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @elseif ($tab === 'env')
                <div class="space-y-3">
                    <x-alert tone="warning">
                        {{ __('Full .env content from production is loaded into this local session. Treat it as secret.') }}
                    </x-alert>
                    <textarea
                        wire:model="envContent"
                        rows="20"
                        class="w-full rounded-xl border border-brand-ink/15 bg-white p-4 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        spellcheck="false"
                    ></textarea>
                    <div class="flex justify-end">
                        <x-danger-button type="button" wire:click="requestSaveEnv" wire:loading.attr="disabled">
                            {{ __('Save to production') }}
                        </x-danger-button>
                    </div>
                </div>
            @else
                <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-6 py-16 text-center">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Not available over live API yet') }}</p>
                    <p class="mx-auto mt-2 max-w-md text-sm text-brand-moss">
                        {{ __('This workspace tab is part of the Production facade. Use the real production UI for :tab until the API covers it.', ['tab' => $tab]) }}
                    </p>
                </div>
            @endif
        </x-server-workspace-tab-panel>
    </div>
</div>
