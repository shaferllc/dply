<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <nav class="mb-6 text-sm text-brand-moss" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Servers') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.sites', $server) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem]" title="{{ $server->name }}">{{ $server->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem]" title="{{ $site->name }}">{{ $site->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="font-medium text-brand-ink">{{ __('Deployments') }}</li>
        </ol>
    </nav>

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-page-header
                :title="__('Deployments')"
                :description="__('Every deployment recorded for this site, newest first. Click a row to drill into per-step output.')"
                doc-route="docs.index"
                flush
                compact
            />

            @if ($site->server?->isDigitalOceanFunctionsHost())
                {{-- The live deploy journey is the redeploy surface: it shows
                     the latest deploy, a Redeploy button, and watches a deploy
                     run — redeploy → watch → history, all in one tab. --}}
                <livewire:serverless.journey
                    :server="$server"
                    :site="$site"
                    :embedded="true"
                    wire:key="deploy-journey-{{ $site->id }}"
                />
            @else
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand-ink/10 bg-brand-sand/30 px-4 py-3">
                    <p class="text-sm text-brand-moss">{{ __('Trigger a fresh deploy of the current repository state.') }}</p>
                    <button type="button" wire:click="redeploy" wire:loading.attr="disabled" wire:target="redeploy"
                            class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60">
                        <span wire:loading.remove wire:target="redeploy">{{ __('Deploy / redeploy') }}</span>
                        <span wire:loading wire:target="redeploy">{{ __('Starting deploy…') }}</span>
                    </button>
                </div>
            @endif

            <div class="flex flex-wrap items-end gap-3">
        <div>
            <label for="status_filter" class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Status') }}</label>
            <select id="status_filter" wire:model.live="statusFilter" class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                <option value="">{{ __('Any') }}</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s }}">{{ $s }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="trigger_filter" class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Trigger') }}</label>
            <select id="trigger_filter" wire:model.live="triggerFilter" class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                <option value="">{{ __('Any') }}</option>
                @foreach ($triggers as $t)
                    <option value="{{ $t }}">{{ $t }}</option>
                @endforeach
            </select>
        </div>
        @if ($statusFilter !== '' || $triggerFilter !== '')
            <button type="button" wire:click="clearFilters" class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                {{ __('Clear filters') }}
            </button>
        @endif
        <p class="ml-auto text-xs text-slate-500">{{ trans_choice('{1} :count deployment|[2,*] :count deployments', $deployments->total(), ['count' => $deployments->total()]) }}</p>
    </div>

    @if ($deployments->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-600">
            {{ __('No deployments match the current filters.') }}
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Started') }}</th>
                        <th class="px-4 py-3">{{ __('Finished') }}</th>
                        <th class="px-4 py-3">{{ __('Duration') }}</th>
                        <th class="px-4 py-3">{{ __('Trigger') }}</th>
                        <th class="px-4 py-3">{{ __('Commit') }}</th>
                        <th class="px-4 py-3">{{ __('Phases') }}</th>
                        <th class="px-4 py-3">{{ __('Deploy ID') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($deployments as $deployment)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em]',
                                    'bg-emerald-100 text-emerald-900' => $deployment->status === 'success',
                                    'bg-rose-100 text-rose-900' => $deployment->status === 'failed',
                                    'bg-amber-100 text-amber-900' => $deployment->status === 'running',
                                    'bg-slate-100 text-slate-700' => ! in_array($deployment->status, ['success', 'failed', 'running']),
                                ])>{{ $deployment->status }}</span>
                                @if ($deployment->exit_code !== null && $deployment->exit_code !== 0)
                                    <span class="mt-1 block font-mono text-[10px] text-rose-700">{{ __('exit :code', ['code' => $deployment->exit_code]) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                @if ($deployment->started_at)
                                    <span title="{{ $deployment->started_at->toIso8601String() }}">{{ $deployment->started_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                @if ($deployment->finished_at)
                                    <span title="{{ $deployment->finished_at->toIso8601String() }}">{{ $deployment->finished_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-600">
                                @if ($deployment->phaseTotalDurationMs() > 0)
                                    {{ number_format($deployment->phaseTotalDurationMs() / 1000, 1) }}s
                                @elseif ($deployment->started_at && $deployment->finished_at)
                                    {{ $deployment->started_at->diffInSeconds($deployment->finished_at) }}s
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-700">{{ $deployment->trigger ?: '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-600">
                                @if ($deployment->git_sha)
                                    <span title="{{ $deployment->git_sha }}">{{ \Illuminate\Support\Str::limit($deployment->git_sha, 7, '') }}</span>
                                @else
                                    <span class="font-sans text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @foreach (['build', 'swap', 'release', 'restart'] as $phase)
                                        @if ($deployment->hasPhase($phase))
                                            <span @class([
                                                'inline-flex items-center rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.1em]',
                                                'bg-emerald-50 text-emerald-800' => $deployment->phaseOk($phase),
                                                'bg-rose-50 text-rose-800' => ! $deployment->phaseOk($phase),
                                            ])>{{ $phase }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment]) }}" wire:navigate class="select-all rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-500 hover:bg-slate-200 hover:text-slate-700">{{ $deployment->id }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $deployments->links() }}
        </div>
    @endif

            <x-cli-snippet class="mt-6" :command="'dply:site:deploy-history '.$site->slug" />
        </main>
    </div>
</div>
