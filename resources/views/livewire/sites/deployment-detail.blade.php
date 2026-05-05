<div class="mx-auto max-w-5xl px-6 py-10">
    <nav class="mb-6 text-sm text-slate-500">
        <a href="{{ route('sites.show', ['server' => $server, 'site' => $site]) }}" wire:navigate class="hover:text-slate-700">{{ $site->name }}</a>
        <span class="mx-2 text-slate-400">/</span>
        <span class="text-slate-700">{{ __('Deployments') }}</span>
        <span class="mx-2 text-slate-400">/</span>
        <span class="font-mono text-xs text-slate-700">{{ $deployment->id }}</span>
    </nav>

    <header class="mb-6 flex flex-wrap items-baseline justify-between gap-3 border-b border-slate-200 pb-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('Deployment') }} <span class="font-mono text-base text-slate-500">{{ $deployment->id }}</span></h1>
            <p class="mt-1 text-sm text-slate-600">
                <span @class([
                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em]',
                    'bg-emerald-100 text-emerald-900' => $deployment->status === 'success',
                    'bg-rose-100 text-rose-900' => $deployment->status === 'failed',
                    'bg-amber-100 text-amber-900' => $deployment->status === 'running',
                    'bg-slate-100 text-slate-700' => ! in_array($deployment->status, ['success', 'failed', 'running']),
                ])>{{ $deployment->status }}</span>
                @if ($deployment->trigger)
                    <span class="ml-2 text-slate-500">trigger: {{ $deployment->trigger }}</span>
                @endif
                @if ($deployment->started_at)
                    <span class="ml-2 text-slate-500">started {{ $deployment->started_at->toIso8601String() }}</span>
                @endif
                @if ($deployment->phaseTotalDurationMs() > 0)
                    <span class="ml-2 font-mono text-slate-500">· {{ number_format($deployment->phaseTotalDurationMs() / 1000, 1) }}s</span>
                @endif
            </p>
        </div>
        <button type="button" wire:click="toggleOutput" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
            {{ $showOutput ? __('Hide step output') : __('Show step output') }}
        </button>
    </header>

    @if ($phaseResults === [])
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-600">
            {{ __('No phase results recorded for this deployment.') }}
        </div>
    @else
        <div class="space-y-6">
            @foreach ($phases as $phase)
                @if ($deployment->hasPhase($phase))
                    @php($phaseOk = $deployment->phaseOk($phase))
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <header class="flex items-baseline gap-3">
                            <span @class([
                                'inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold',
                                'bg-emerald-100 text-emerald-900' => $phaseOk,
                                'bg-rose-100 text-rose-900' => ! $phaseOk,
                            ])>{{ $phaseOk ? '✓' : '✗' }}</span>
                            <h2 class="text-lg font-semibold text-slate-900 capitalize">{{ $phase }}</h2>
                            <span class="text-xs text-slate-500">{{ count($deployment->phaseSteps($phase)) }} step(s)</span>
                        </header>
                        <ul class="mt-4 space-y-3">
                            @foreach ($deployment->phaseSteps($phase) as $step)
                                @include('livewire.sites.partials.deployment-detail-step', [
                                    'step' => $step,
                                    'showOutput' => $showOutput,
                                    'glyph' => $deployment->stepGlyph($step),
                                    'glyphClasses' => $deployment->stepClasses($step),
                                ])
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endforeach
        </div>
    @endif

    <x-cli-snippet class="mt-6" :command="'dply:site:show-deploy '.$deployment->id.' --output'" />
</div>
