<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('History') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployment history') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ trans_choice('{0} No deployments yet|{1} :count deployment|[2,*] :count deployments', $deployments->total(), ['count' => $deployments->total()]) }}
                </p>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-3 border-b border-brand-ink/10 bg-white px-6 py-4 sm:px-8">
        <div>
            <label for="status_filter" class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Status') }}</label>
            <select id="status_filter" wire:model.live="statusFilter" class="mt-1 rounded-lg border border-brand-ink/15 py-2 pl-3 pr-10 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30">
                <option value="">{{ __('Any') }}</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s }}">{{ $s }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="trigger_filter" class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Trigger') }}</label>
            <select id="trigger_filter" wire:model.live="triggerFilter" class="mt-1 rounded-lg border border-brand-ink/15 py-2 pl-3 pr-10 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30">
                <option value="">{{ __('Any') }}</option>
                @foreach ($triggers as $t)
                    <option value="{{ $t }}">{{ $t }}</option>
                @endforeach
            </select>
        </div>
        @if ($statusFilter !== '' || $triggerFilter !== '')
            <button type="button" wire:click="clearFilters" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                <x-heroicon-m-x-mark class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Clear filters') }}
            </button>
        @endif
    </div>

    @if ($deployments->isEmpty())
        <div class="px-6 py-12 text-center text-sm text-brand-moss sm:px-8">
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
                <li class="group relative transition-colors hover:bg-brand-sand/15">
                    <a
                        href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment]) }}"
                        wire:navigate
                        class="absolute inset-0 z-0"
                        aria-label="{{ __('View deployment :id', ['id' => $deployment->id]) }}"
                    ></a>

                    <div class="flex items-start gap-3 px-6 py-4 sm:gap-4 sm:px-8">
                        {{-- Status dot on the rail --}}
                        <span @class([
                            'mt-1 flex h-2.5 w-2.5 shrink-0 rounded-full ring-4',
                            'bg-emerald-500 ring-emerald-100' => $isSuccess,
                            'bg-rose-500 ring-rose-100' => $isFailed,
                            'bg-amber-500 ring-amber-100 animate-pulse' => $isRunning,
                            'bg-amber-400 ring-amber-100' => $isBillingBlocked,
                            'bg-brand-mist ring-brand-sand/50' => ! $isSuccess && ! $isFailed && ! $isRunning && ! $isBillingBlocked,
                        ])></span>

                        <div class="min-w-0 flex-1">
                            {{-- Headline: status + when + duration --}}
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset',
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
                                    <span class="font-mono text-[10px] text-rose-700">{{ __('exit :code', ['code' => $deployment->exit_code]) }}</span>
                                @endif

                                @if ($duration !== null)
                                    <span class="ml-auto inline-flex items-center gap-1 whitespace-nowrap font-mono text-xs text-brand-moss">
                                        <x-heroicon-m-clock class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                                        {{ $duration }}
                                    </span>
                                @endif
                            </div>

                            {{-- Meta row: trigger + commit + phases --}}
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <span class="inline-flex items-center rounded-full bg-brand-sand/50 px-2 py-0.5 text-[11px] font-medium text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $deployment->trigger ?: '—' }}</span>

                                @if ($deployment->git_sha)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/50 px-2 py-0.5 font-mono text-[11px] font-semibold text-brand-sage ring-1 ring-inset ring-brand-ink/10" title="{{ $deployment->git_sha }}">
                                        <x-heroicon-m-code-bracket class="h-3 w-3" aria-hidden="true" />
                                        {{ \Illuminate\Support\Str::limit($deployment->git_sha, 7, '') }}
                                    </span>
                                @endif

                                @foreach (['clone', 'build', 'swap', 'activate', 'release', 'restart', 'serverless'] as $phase)
                                    @if ($deployment->hasPhase($phase) && $deployment->phaseSteps($phase) !== [])
                                        <span @class([
                                            'inline-flex items-center rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.1em] ring-1 ring-inset',
                                            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $deployment->phaseOk($phase),
                                            'bg-rose-50 text-rose-800 ring-rose-200' => ! $deployment->phaseOk($phase),
                                        ])>{{ $phase }}</span>
                                    @endif
                                @endforeach
                            </div>

                            {{-- Footer: deploy id + failure helper --}}
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="font-mono text-[10px] text-brand-mist">{{ $deployment->id }}</span>
                                @if ($isFailed && ops_copilot_active())
                                    <a
                                        href="{{ route('fleet.copilot', ['site' => $site->id]) }}"
                                        wire:navigate
                                        class="relative z-10 inline-flex items-center gap-1 whitespace-nowrap text-[11px] font-semibold text-brand-forest hover:text-brand-sage"
                                    >
                                        {{ __('Explain failure') }}
                                        <x-heroicon-m-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                    </a>
                                @endif
                            </div>
                        </div>

                        <x-heroicon-m-chevron-right class="mt-1 h-4 w-4 shrink-0 text-brand-mist transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                    </div>
                </li>
            @endforeach
        </ol>

        @if ($deployments->hasPages())
            <div class="border-t border-brand-ink/10 bg-white px-6 py-4 sm:px-8">
                {{ $deployments->links() }}
            </div>
        @endif
    @endif
</section>
