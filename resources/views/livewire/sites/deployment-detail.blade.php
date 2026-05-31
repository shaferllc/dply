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
            <li><a href="{{ route('sites.deployments.index', ['server' => $server, 'site' => $site]) }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Deployments') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="font-mono text-xs font-medium text-brand-ink">{{ $deployment->id }}</li>
        </ol>
    </nav>

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <header class="flex flex-wrap items-baseline justify-between gap-3 border-b border-slate-200 pb-4">
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
                        <a href="{{ route('sites.deployments.index', ['server' => $server, 'site' => $site]) }}" wire:navigate class="ml-2 text-slate-500 hover:text-slate-700">{{ __('All deployments') }}</a>
                    </p>
                </div>
                <button type="button" wire:click="toggleOutput" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                    {{ $showOutput ? __('Hide step output') : __('Show step output') }}
                </button>
            </header>

            @if ($deployment->status === 'failed')
                <x-ops-copilot-callout :site="$site" :show="true" class="mt-6" />
            @endif

            @php
                $durationMs = $deployment->phaseTotalDurationMs();
                if ($durationMs <= 0 && $deployment->started_at && $deployment->finished_at) {
                    $durationMs = $deployment->started_at->diffInMilliseconds($deployment->finished_at);
                }
                $stepCount = collect($phases)->sum(fn ($p) => count($deployment->phaseSteps($p)));
            @endphp
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm sm:grid-cols-3 lg:grid-cols-4">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Trigger') }}</dt>
                    <dd class="mt-1 text-slate-800">{{ $deployment->trigger ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Commit') }}</dt>
                    <dd class="mt-1 break-all font-mono text-xs text-slate-800" title="{{ $deployment->git_sha }}">{{ $deployment->git_sha ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Exit code') }}</dt>
                    <dd @class([
                        'mt-1 font-mono text-xs',
                        'text-rose-700' => $deployment->exit_code !== null && $deployment->exit_code !== 0,
                        'text-slate-800' => $deployment->exit_code === null || $deployment->exit_code === 0,
                    ])>{{ $deployment->exit_code === null ? '—' : $deployment->exit_code }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Duration') }}</dt>
                    <dd class="mt-1 font-mono text-xs text-slate-800">{{ $durationMs > 0 ? number_format($durationMs / 1000, 1).'s' : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Started') }}</dt>
                    <dd class="mt-1 text-slate-800">
                        @if ($deployment->started_at)
                            <span title="{{ $deployment->started_at->toIso8601String() }}">{{ $deployment->started_at->diffForHumans() }}</span>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Finished') }}</dt>
                    <dd class="mt-1 text-slate-800">
                        @if ($deployment->finished_at)
                            <span title="{{ $deployment->finished_at->toIso8601String() }}">{{ $deployment->finished_at->diffForHumans() }}</span>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Steps') }}</dt>
                    <dd class="mt-1 text-slate-800">{{ $stepCount }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('Idempotency key') }}</dt>
                    <dd class="mt-1 break-all font-mono text-xs text-slate-800">{{ $deployment->idempotency_key ?: '—' }}</dd>
                </div>
            </dl>

            @if ($phaseResults === [])
                <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-600">
                    {{ __('No phase results recorded for this deployment.') }}
                </div>
            @else
                <div class="space-y-6">
                    @foreach ($phases as $phase)
                        @if ($deployment->hasPhase($phase))
                            @php($phaseOk = $deployment->phaseOk($phase))
                            @php($phaseSteps = $deployment->phaseSteps($phase))
                            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                <header class="flex items-baseline gap-3">
                                    <span @class([
                                        'inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold',
                                        'bg-emerald-100 text-emerald-900' => $phaseOk,
                                        'bg-rose-100 text-rose-900' => ! $phaseOk,
                                    ])>{{ $phaseOk ? '✓' : '✗' }}</span>
                                    <h2 class="text-lg font-semibold text-slate-900 capitalize">{{ str_replace(['_', '-'], ' ', $phase) }}</h2>
                                    <span class="text-xs text-slate-500">{{ trans_choice('{1} :count step|[2,*] :count steps', count($phaseSteps), ['count' => count($phaseSteps)]) }}</span>
                                </header>
                                <ol class="mt-4 space-y-3">
                                    @foreach ($phaseSteps as $step)
                                        @include('livewire.sites.partials.deployment-detail-step', [
                                            'step' => $step,
                                            'showOutput' => $showOutput,
                                            'glyph' => $deployment->stepGlyph($step),
                                            'glyphClasses' => $deployment->stepClasses($step),
                                        ])
                                    @endforeach
                                </ol>
                            </section>
                        @endif
                    @endforeach
                </div>
            @endif

            @if (trim((string) $deployment->log_output) !== '')
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Deploy log') }}</h2>
                    <pre class="mt-3 max-h-96 overflow-auto rounded-lg bg-slate-900 p-4 font-mono text-[11px] leading-relaxed text-slate-100">{{ trim((string) $deployment->log_output) }}</pre>
                </section>
            @endif

            <x-cli-snippet :command="'dply:site:show-deploy '.$deployment->id.' --output'" />
        </main>
    </div>
</div>
