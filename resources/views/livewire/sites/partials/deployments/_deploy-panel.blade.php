@php
    $latest = $latestDeployment ?? null;
    $isRunning = $latest && $latest->status === 'running';
    $deployedSha = $latest?->git_sha;
    $shortSha = $deployedSha ? \Illuminate\Support\Str::limit($deployedSha, 7, '') : null;
    $totalDurationMs = $latest ? $latest->phaseTotalDurationMs() : 0;
    // Phase timeline derived from the site's pipeline (Clone → Build →
    // Activate → Release) overlaid with this deployment's recorded steps.
    $timelinePhases = \App\Support\Sites\SiteDeployTimeline::forDeployment($site, $latest);
@endphp

<div class="space-y-6" @if ($isRunning) wire:poll.5s @endif>
    @if ($this->deployLockInfo ?? null)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <div class="flex flex-wrap items-center gap-2">
                <x-heroicon-m-bolt class="h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                <strong class="font-semibold">{{ __('Deployment in progress') }}</strong>
                @if (! empty($this->deployLockInfo['deployment_id']))
                    <span class="font-mono text-xs text-amber-800">#{{ $this->deployLockInfo['deployment_id'] }}</span>
                @endif
            </div>
            <p class="mt-1 text-amber-800">{{ __('Queued deploys may appear as skipped until this run finishes.') }}</p>
            <button
                type="button"
                wire:click="openConfirmActionModal('releaseDeployLock', [], @js(__('Clear deploy lock')), @js(__('Force-clear the deploy lock? Only if no worker is actually deploying.')), @js(__('Clear lock')), true)"
                class="mt-2 text-xs font-semibold text-amber-900 underline hover:text-amber-700"
            >{{ __('Clear lock') }}</button>
        </div>
    @endif

    <section class="dply-card overflow-hidden">
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:px-8">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Ship the current branch') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    @if ($latest)
                        @if ($isRunning)
                            {{ __('A deploy is currently running. Watch the phase timeline below.') }}
                        @elseif ($latest->status === 'success')
                            {{ __('Last deploy succeeded :time.', ['time' => ($latest->finished_at ?? $latest->created_at)?->diffForHumans()]) }}
                        @elseif ($latest->status === 'failed')
                            {{ __('Last deploy failed :time. Check the phase timeline below.', ['time' => ($latest->finished_at ?? $latest->created_at)?->diffForHumans()]) }}
                        @else
                            {{ __('Latest deploy: :status', ['status' => $latest->status]) }}
                        @endif
                    @else
                        {{ __('No deploys yet. Trigger one to deploy the current branch.') }}
                    @endif
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2 sm:ml-auto">
                <button type="button" wire:click="deployNow" wire:loading.attr="disabled" wire:target="deployNow" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60">
                    <x-heroicon-o-rocket-launch class="h-3.5 w-3.5" wire:loading.remove wire:target="deployNow" />
                    <span wire:loading wire:target="deployNow"><x-spinner variant="white" size="sm" /></span>
                    <span wire:loading.remove wire:target="deployNow">{{ __('Deploy now') }}</span>
                    <span wire:loading wire:target="deployNow">{{ __('Deploying…') }}</span>
                </button>
                <button type="button" wire:click="queueDeploy" wire:loading.attr="disabled" wire:target="queueDeploy" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 disabled:opacity-50">
                    <x-heroicon-o-queue-list class="h-3.5 w-3.5" />
                    {{ __('Queue deploy') }}
                </button>
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-x-6 gap-y-4 border-b border-brand-ink/10 bg-white px-6 py-5 text-sm sm:grid-cols-4 sm:px-8">
            <div class="min-w-0">
                <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Deployed commit') }}</dt>
                <dd class="mt-1 truncate">
                    @if ($shortSha)
                        <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 font-mono text-xs font-semibold text-brand-sage" title="{{ $deployedSha }}">{{ $shortSha }}</span>
                    @else
                        <span class="text-brand-mist">{{ __('No deploys yet') }}</span>
                    @endif
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Status') }}</dt>
                <dd class="mt-1">
                    @if ($latest)
                        <span @class([
                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset',
                            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $latest->status === 'success',
                            'bg-rose-50 text-rose-800 ring-rose-200' => $latest->status === 'failed',
                            'bg-amber-50 text-amber-900 ring-amber-200' => $latest->status === 'running',
                            'bg-brand-sand/60 text-brand-ink ring-brand-ink/10' => ! in_array($latest->status, ['success', 'failed', 'running']),
                        ])>{{ $latest->status }}</span>
                    @else
                        <span class="text-brand-mist">—</span>
                    @endif
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Duration') }}</dt>
                <dd class="mt-1 font-mono text-xs text-brand-ink">
                    @if ($totalDurationMs > 0)
                        {{ number_format($totalDurationMs / 1000, 1) }}s
                    @elseif ($latest?->started_at && $latest?->finished_at)
                        {{ $latest->started_at->diffInSeconds($latest->finished_at) }}s
                    @else
                        <span class="font-sans text-brand-mist">—</span>
                    @endif
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Trigger') }}</dt>
                <dd class="mt-1 text-brand-ink">{{ $latest?->trigger ?: '—' }}</dd>
            </div>
        </dl>

        <div class="px-6 py-6 sm:px-8">
            @if ($latest === null)
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-8 text-center text-sm text-brand-moss">
                    {{ __('No phase timeline yet — your first deploy will appear here.') }}
                </div>
            @else
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Phase timeline') }}</p>
                    <a
                        href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $latest]) }}"
                        wire:navigate
                        class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:text-brand-sage hover:underline"
                    >
                        {{ __('View full log') }}
                        <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </div>

                <ol class="mt-3 space-y-2">
                    @foreach ($timelinePhases as $phase)
                        @php
                            $st = $phase['status'];
                            $stepCount = count($phase['steps']);
                            $durTxt = $phase['duration_ms'] > 0 ? number_format($phase['duration_ms'] / 1000, 1).'s' : null;
                        @endphp
                        <li @class([
                            'rounded-2xl border px-4 py-3 transition-colors',
                            'border-emerald-200 bg-emerald-50/50' => $st === 'success',
                            'border-rose-200 bg-rose-50/50' => $st === 'failed',
                            'border-amber-200 bg-amber-50/50' => $st === 'running',
                            'border-brand-ink/10 bg-brand-sand/10' => in_array($st, ['skipped', 'pending'], true),
                        ])>
                            <div class="flex items-center gap-3">
                                <span @class([
                                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-1 ring-inset font-semibold text-xs',
                                    'bg-emerald-100 text-emerald-800 ring-emerald-200' => $st === 'success',
                                    'bg-rose-100 text-rose-800 ring-rose-200' => $st === 'failed',
                                    'bg-amber-100 text-amber-800 ring-amber-200' => $st === 'running',
                                    'bg-white text-brand-mist ring-brand-ink/10' => in_array($st, ['skipped', 'pending'], true),
                                ])>
                                    @switch ($st)
                                        @case('success')
                                            <x-heroicon-m-check class="h-4 w-4" aria-hidden="true" />
                                            @break
                                        @case('failed')
                                            <x-heroicon-m-x-mark class="h-4 w-4" aria-hidden="true" />
                                            @break
                                        @case('running')
                                            <x-heroicon-m-arrow-path class="h-4 w-4 animate-spin" aria-hidden="true" />
                                            @break
                                        @case('skipped')
                                            <x-heroicon-m-minus class="h-4 w-4" aria-hidden="true" />
                                            @break
                                        @default
                                            {{ $loop->iteration }}
                                    @endswitch
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
                                        <span class="font-semibold text-brand-ink">{{ $phase['label'] }}</span>
                                        <span class="text-[11px] text-brand-moss">
                                            @switch ($st)
                                                @case('success')
                                                    {{ trans_choice('{1} :count step|[2,*] :count steps', $stepCount, ['count' => $stepCount]) }}@if ($durTxt) · <span class="font-mono">{{ $durTxt }}</span>@endif
                                                    @break
                                                @case('failed')
                                                    <span class="font-semibold text-rose-700">{{ __('Failed') }}</span>@if ($durTxt) · <span class="font-mono">{{ $durTxt }}</span>@endif
                                                    @break
                                                @case('running')
                                                    {{ __('Running…') }}
                                                    @break
                                                @case('skipped')
                                                    {{ __('No steps') }}
                                                    @break
                                                @default
                                                    {{ __('Not started') }}
                                            @endswitch
                                        </span>
                                    </p>
                                </div>
                            </div>

                            @if ($phase['steps'] !== [])
                                <ul class="mt-2 space-y-1 pl-11">
                                    @foreach ($phase['steps'] as $step)
                                        @php($stepFailed = ! $step['ok'] && ! $step['skipped'])
                                        <li>
                                            <div class="flex items-center gap-2 text-xs">
                                                <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[9px] font-bold {{ $step['glyph_classes'] }}">{{ $step['glyph'] }}</span>
                                                <span class="min-w-0 truncate {{ $stepFailed ? 'font-medium text-rose-800' : 'text-brand-ink' }}">{{ $step['label'] }}</span>
                                                @if ($step['skipped'])
                                                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.1em] text-amber-900">{{ __('skipped') }}</span>
                                                @elseif ($step['duration_ms'] > 0)
                                                    <span class="font-mono text-brand-mist">{{ $step['duration_ms'] >= 1000 ? number_format($step['duration_ms'] / 1000, 1).'s' : $step['duration_ms'].'ms' }}</span>
                                                @endif
                                            </div>
                                            @if ($stepFailed && $step['output'] !== '')
                                                <pre class="mt-1.5 max-h-48 overflow-auto rounded-lg bg-brand-ink p-3 font-mono text-[11px] leading-relaxed text-rose-100/95">{{ $step['output'] }}</pre>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ol>

                @if ($latest->exit_code !== null && $latest->exit_code !== 0)
                    <p class="mt-4 font-mono text-xs text-rose-700">{{ __('exit :code', ['code' => $latest->exit_code]) }}</p>
                @endif
            @endif
        </div>
    </section>
</div>
