@php
    $selectCls = 'mt-0.5 rounded-md border border-brand-ink/15 bg-white py-1 pl-2 pr-7 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-1 focus:ring-brand-sage/30';
    $btnOutline = 'dply-btn dply-btn-xs dply-btn-outline';
@endphp

<section class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-clock"
        :title="__('Deployment history')"
        :count="trans_choice('{0} none|{1} :count|[2,*] :count', $deployments->total(), ['count' => $deployments->total()])"
        :note="__('Status, trigger, commit, and phase outcome for each run.')"
    />

    <div class="flex flex-wrap items-end gap-2 border-b border-brand-ink/10 bg-white px-3 py-2 sm:px-4">
        <div class="min-w-[7.5rem]">
            <label for="status_filter" class="block text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</label>
            <select id="status_filter" wire:model.live="statusFilter" class="{{ $selectCls }} w-full">
                <option value="">{{ __('Any') }}</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s }}">{{ $s }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-[7.5rem]">
            <label for="trigger_filter" class="block text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Trigger') }}</label>
            <select id="trigger_filter" wire:model.live="triggerFilter" class="{{ $selectCls }} w-full">
                <option value="">{{ __('Any') }}</option>
                @foreach ($triggers as $t)
                    <option value="{{ $t }}">{{ $t }}</option>
                @endforeach
            </select>
        </div>
        @if ($statusFilter !== '' || $triggerFilter !== '')
            <button type="button" wire:click="clearFilters" class="{{ $btnOutline }}">
                <x-heroicon-m-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Clear') }}
            </button>
        @endif

        @if ($this->failedDeploymentCount > 0)
            <button
                type="button"
                wire:click="confirmDeleteFailedDeployments"
                wire:loading.attr="disabled"
                class="{{ $btnOutline }} ml-auto text-rose-700 ring-rose-200 hover:bg-rose-50"
            >
                <x-heroicon-m-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ trans_choice('{1}Delete :count failed run|[2,*]Delete :count failed runs', $this->failedDeploymentCount, ['count' => $this->failedDeploymentCount]) }}
            </button>
        @endif
    </div>

    @if ($deployments->isEmpty())
        <div class="px-3 py-4 text-center text-xs text-brand-moss sm:px-4">
            @if ($statusFilter !== '' || $triggerFilter !== '')
                {{ __('No deployments match the current filters.') }}
            @else
                {{ __('No deployments yet. Trigger a deploy to see it here.') }}
            @endif
        </div>
    @else
        {{-- Vertical timeline: one card per deploy. The whole card navigates to
             the deploy detail via a stretched link; inner links (Explain
             failure, copy deploy id) sit above it with relative z-index. --}}
        <ol class="divide-y divide-brand-ink/10">
            @foreach ($deployments as $deployment)
                @php
                    $isSuccess = $deployment->status === 'success';
                    $isFailed = $deployment->status === 'failed';
                    $isRunning = $deployment->status === 'running';
                    $isBillingBlocked = $deployment->isBillingBlocked();
                    $duration = $deployment->phaseTotalDurationMs() > 0
                        ? number_format($deployment->phaseTotalDurationMs() / 1000, 1).'s'
                        : (($deployment->started_at && $deployment->finished_at)
                            ? $deployment->started_at->diffInSeconds($deployment->finished_at).'s'
                            : null);
                @endphp
                <li wire:key="deployment-{{ $deployment->id }}" class="group relative transition-colors hover:bg-brand-sand/15">
                    <a
                        href="{{ \App\Support\Serverless\ServerlessWorkspaceUrl::deploymentShow($site, $deployment) }}"
                        wire:navigate
                        class="absolute inset-0 z-0"
                        aria-label="{{ __('View deployment :id', ['id' => $deployment->id]) }}"
                    ></a>

                    <div class="flex items-start gap-2.5 px-3 py-2 sm:gap-3 sm:px-4">
                        {{-- Status dot on the rail --}}
                        <span @class([
                            'mt-1 flex h-2 w-2 shrink-0 rounded-full ring-4',
                            'bg-emerald-500 ring-emerald-100' => $isSuccess,
                            'bg-rose-500 ring-rose-100' => $isFailed,
                            'bg-amber-500 ring-amber-100 animate-pulse' => $isRunning,
                            'bg-amber-400 ring-amber-100' => $isBillingBlocked,
                            'bg-brand-mist ring-brand-sand/50' => ! $isSuccess && ! $isFailed && ! $isRunning && ! $isBillingBlocked,
                        ])></span>

                        <div class="min-w-0 flex-1">
                            {{-- Headline: status + when + duration --}}
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span @class([
                                    'inline-flex items-center rounded-full px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-[0.12em] ring-1 ring-inset',
                                    'bg-emerald-50 text-emerald-800 ring-emerald-200' => $isSuccess,
                                    'bg-rose-50 text-rose-800 ring-rose-200' => $isFailed,
                                    'bg-amber-50 text-amber-900 ring-amber-200' => $isRunning,
                                    'bg-amber-100 text-amber-950 ring-amber-300' => $isBillingBlocked,
                                    'bg-brand-sand/60 text-brand-ink ring-brand-ink/10' => ! $isSuccess && ! $isFailed && ! $isRunning && ! $isBillingBlocked,
                                ])>{{ $isBillingBlocked ? __('blocked — billing') : $deployment->status }}</span>

                                @if ($deployment->started_at)
                                    <span class="text-xs text-brand-moss" title="{{ $deployment->started_at->toIso8601String() }}">{{ $deployment->started_at->diffForHumans() }}</span>
                                @endif
                                @if ($deployment->exit_code !== null && $deployment->exit_code !== 0)
                                    <span class="font-mono text-2xs text-rose-700">{{ __('exit :code', ['code' => $deployment->exit_code]) }}</span>
                                @endif

                                @if ($duration !== null)
                                    <span class="ml-auto inline-flex items-center gap-1 whitespace-nowrap font-mono text-xs text-brand-moss">
                                        <x-heroicon-m-clock class="h-3 w-3 text-brand-mist" aria-hidden="true" />
                                        {{ $duration }}
                                    </span>
                                @endif
                            </div>

                            {{-- Meta row: trigger + commit + phases --}}
                            <div class="mt-1 flex flex-wrap items-center gap-1">
                                <span class="inline-flex items-center rounded-full bg-brand-sand/50 px-1.5 py-0.5 text-2xs font-medium text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $deployment->trigger ?: '—' }}</span>

                                @if ($deployment->git_sha)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/50 px-1.5 py-0.5 font-mono text-2xs font-semibold text-brand-sage ring-1 ring-inset ring-brand-ink/10" title="{{ $deployment->git_sha }}">
                                        <x-heroicon-m-code-bracket class="h-3 w-3" aria-hidden="true" />
                                        {{ \Illuminate\Support\Str::limit($deployment->git_sha, 7, '') }}
                                    </span>
                                @endif

                                @foreach (['clone', 'build', 'swap', 'activate', 'release', 'restart', 'serverless'] as $phase)
                                    @if ($deployment->hasPhase($phase) && $deployment->phaseSteps($phase) !== [])
                                        <span @class([
                                            'inline-flex items-center rounded-full px-1.5 py-0.5 text-3xs font-semibold uppercase tracking-[0.1em] ring-1 ring-inset',
                                            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $deployment->phaseOk($phase),
                                            'bg-rose-50 text-rose-800 ring-rose-200' => ! $deployment->phaseOk($phase),
                                        ])>{{ $phase }}</span>
                                    @endif
                                @endforeach
                            </div>

                            {{-- Failure excerpt: the last meaningful line of the deploy
                                 log, so a failed row says WHY without a click-through.
                                 (A pre-phase failure has no phase chips above at all.) --}}
                            @if ($isFailed)
                                @php
                                    $failExcerpt = '';
                                    foreach (array_reverse(preg_split('/\r?\n/', trim((string) $deployment->log_output)) ?: []) as $logLine) {
                                        if (trim($logLine) !== '') {
                                            $failExcerpt = trim($logLine);
                                            break;
                                        }
                                    }
                                @endphp
                                @if ($failExcerpt !== '')
                                    <p class="mt-1 truncate font-mono text-2xs text-rose-700" title="{{ \Illuminate\Support\Str::limit($failExcerpt, 600) }}">{{ \Illuminate\Support\Str::limit($failExcerpt, 220) }}</p>
                                @endif
                            @endif

                            {{-- Footer: deploy id + failure helper --}}
                            <div class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-0.5">
                                <span class="font-mono text-2xs text-brand-mist">{{ $deployment->id }}</span>
                                @if ($isFailed && ops_copilot_active())
                                    <a
                                        href="{{ route('infrastructure.copilot', ['site' => $site->id]) }}"
                                        wire:navigate
                                        class="relative z-10 inline-flex items-center gap-1 whitespace-nowrap text-2xs font-semibold text-brand-forest hover:text-brand-sage"
                                    >
                                        {{ __('Explain failure') }}
                                        <x-heroicon-m-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                    </a>
                                @endif

                                {{-- A running deploy has no delete control: the pipeline is
                                     still writing to the row. Cancel it, then delete. --}}
                                @unless ($isRunning)
                                    <button
                                        type="button"
                                        wire:click="confirmDeleteDeployment('{{ $deployment->id }}')"
                                        wire:loading.attr="disabled"
                                        class="relative z-10 inline-flex items-center gap-1 whitespace-nowrap text-2xs font-semibold text-brand-mist opacity-0 transition-opacity hover:text-rose-700 focus:opacity-100 focus-visible:outline-none group-hover:opacity-100"
                                        aria-label="{{ __('Delete deployment :id', ['id' => $deployment->id]) }}"
                                    >
                                        <x-heroicon-m-trash class="h-3 w-3" aria-hidden="true" />
                                        {{ __('Delete') }}
                                    </button>
                                @endunless
                            </div>
                        </div>

                        <x-heroicon-m-chevron-right class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-mist transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                    </div>
                </li>
            @endforeach
        </ol>

        @if ($deployments->hasPages())
            <div class="border-t border-brand-ink/10 bg-white px-3 py-2 sm:px-4">
                {{ $deployments->links() }}
            </div>
        @endif
    @endif
</section>
