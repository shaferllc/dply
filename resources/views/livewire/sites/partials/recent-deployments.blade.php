<div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="text-sm font-semibold text-slate-900">{{ __('Recent deployments') }}</h3>
        <div class="flex items-baseline gap-3">
            <p class="text-xs text-slate-500">{{ trans_choice('{1} 1 with phase data|[2,*] :count with phase data', $deployments->count(), ['count' => $deployments->count()]) }}</p>
            <a href="{{ route('sites.deployments.index', ['server' => $site->server, 'site' => $site]) }}" wire:navigate class="text-xs font-medium text-slate-700 hover:text-slate-900 hover:underline">{{ __('View all') }} →</a>
        </div>
    </div>
    <p class="mt-1 text-xs text-slate-600">{{ __('Per-phase build → swap → release → restart status from the deploy runner. Click to expand step details.') }}</p>
    <ul class="mt-3 space-y-2">
        @foreach ($deployments as $deployment)
            <li class="rounded-xl border border-slate-200 bg-white p-3">
                <details>
                    <summary class="cursor-pointer">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $deployment->status === 'success' ? 'bg-emerald-100 text-emerald-900' : ($deployment->status === 'failed' ? 'bg-rose-100 text-rose-900' : 'bg-slate-100 text-slate-700') }}">{{ $deployment->status }}</span>
                            <span class="font-mono text-[11px] text-slate-500">{{ $deployment->started_at?->diffForHumans() ?? '—' }}</span>
                            @if ($deployment->trigger)
                                <span class="text-slate-500">· {{ $deployment->trigger }}</span>
                            @endif
                            @if ($deployment->phaseTotalDurationMs() > 0)
                                <span class="font-mono text-[11px] text-slate-500">· {{ number_format($deployment->phaseTotalDurationMs() / 1000, 1) }}s</span>
                            @endif
                            <a href="{{ route('sites.deployments.show', ['server' => $site->server, 'site' => $site, 'deployment' => $deployment]) }}" wire:navigate class="ml-auto rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-500 hover:bg-slate-200 hover:text-slate-700" title="{{ __('Open deployment detail') }}">{{ $deployment->id }}</a>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-1.5 text-[10px]">
                            @foreach (['build', 'swap', 'release', 'restart'] as $phase)
                                @if ($deployment->hasPhase($phase))
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold uppercase tracking-[0.12em] {{ $deployment->phaseOk($phase) ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800' }}">
                                        {{ $phase }} ({{ count($deployment->phaseSteps($phase)) }})
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-0.5 font-semibold uppercase tracking-[0.12em] text-slate-500">{{ $phase }} —</span>
                                @endif
                            @endforeach
                        </div>
                    </summary>
                    <div class="mt-3 space-y-3">
                        @foreach (['build', 'swap', 'release', 'restart'] as $phase)
                            @if ($deployment->hasPhase($phase))
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ $phase }}</p>
                                    <ul class="mt-1 space-y-1">
                                        @foreach ($deployment->phaseSteps($phase) as $step)
                                            @include('livewire.sites.partials.recent-deployment-step', ['step' => $step])
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endforeach
                    </div>
                    <x-cli-snippet class="mt-3 text-[10px]" :command="'dply:site:show-deploy '.$deployment->id.' --output'" />
                </details>
            </li>
        @endforeach
    </ul>
</div>
