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
            <li><a href="{{ route('sites.deployments.index', ['server' => $server, 'site' => $site, 'tab' => 'history']) }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Deployments') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="font-mono text-xs font-medium text-brand-ink">{{ $deployment->id }}</li>
        </ol>
    </nav>

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <section class="dply-card overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deployment') }}</p>
                            <h1 class="mt-0.5 flex flex-wrap items-baseline gap-2 text-lg font-semibold text-brand-ink">
                                <span class="font-mono text-base">{{ $deployment->id }}</span>
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset',
                                    'bg-emerald-50 text-emerald-800 ring-emerald-200' => $deployment->status === 'success',
                                    'bg-rose-50 text-rose-800 ring-rose-200' => $deployment->status === 'failed',
                                    'bg-amber-50 text-amber-900 ring-amber-200' => $deployment->status === 'running',
                                    'bg-brand-sand/60 text-brand-ink ring-brand-ink/10' => ! in_array($deployment->status, ['success', 'failed', 'running']),
                                ])>{{ $deployment->status }}</span>
                            </h1>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                <a href="{{ route('sites.deployments.index', ['server' => $server, 'site' => $site, 'tab' => 'history']) }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ __('Back to all deployments') }}</a>
                            </p>
                        </div>
                    </div>
                    <button type="button" wire:click="toggleOutput" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                        @if ($showOutput)
                            <x-heroicon-m-eye-slash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Hide step output') }}
                        @else
                            <x-heroicon-m-eye class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Show step output') }}
                        @endif
                    </button>
                </div>

                @php
                    $durationMs = $deployment->phaseTotalDurationMs();
                    if ($durationMs <= 0 && $deployment->started_at && $deployment->finished_at) {
                        $durationMs = $deployment->started_at->diffInMilliseconds($deployment->finished_at);
                    }
                    $stepCount = collect($phases)->sum(fn ($p) => count($deployment->phaseSteps($p)));
                @endphp
                <dl class="grid grid-cols-2 gap-x-6 gap-y-4 bg-white px-6 py-5 text-sm sm:grid-cols-4 sm:px-8">
                    <div class="min-w-0">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Trigger') }}</dt>
                        <dd class="mt-1 text-brand-ink">{{ $deployment->trigger ?: '—' }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Commit') }}</dt>
                        <dd class="mt-1 break-all font-mono text-xs">
                            @if ($deployment->git_sha)
                                <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 font-semibold text-brand-sage" title="{{ $deployment->git_sha }}">{{ $deployment->git_sha }}</span>
                            @else
                                <span class="font-sans text-brand-mist">—</span>
                            @endif
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Exit code') }}</dt>
                        <dd @class([
                            'mt-1 font-mono text-xs',
                            'text-rose-700' => $deployment->exit_code !== null && $deployment->exit_code !== 0,
                            'text-brand-ink' => $deployment->exit_code === null || $deployment->exit_code === 0,
                        ])>{{ $deployment->exit_code === null ? '—' : $deployment->exit_code }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Duration') }}</dt>
                        <dd class="mt-1 font-mono text-xs text-brand-ink">{{ $durationMs > 0 ? number_format($durationMs / 1000, 1).'s' : '—' }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Started') }}</dt>
                        <dd class="mt-1 text-brand-moss">
                            @if ($deployment->started_at)
                                <span title="{{ $deployment->started_at->toIso8601String() }}">{{ $deployment->started_at->diffForHumans() }}</span>
                            @else
                                <span class="text-brand-mist">—</span>
                            @endif
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Finished') }}</dt>
                        <dd class="mt-1 text-brand-moss">
                            @if ($deployment->finished_at)
                                <span title="{{ $deployment->finished_at->toIso8601String() }}">{{ $deployment->finished_at->diffForHumans() }}</span>
                            @else
                                <span class="text-brand-mist">—</span>
                            @endif
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Steps') }}</dt>
                        <dd class="mt-1 text-brand-ink">{{ $stepCount }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Idempotency key') }}</dt>
                        <dd class="mt-1 break-all font-mono text-xs text-brand-moss">{{ $deployment->idempotency_key ?: '—' }}</dd>
                    </div>
                </dl>
            </section>

            @if ($deployment->status === 'failed')
                <x-ops-copilot-callout :site="$site" :show="true" />
            @endif

            @if ($phaseResults === [])
                <section class="dply-card overflow-hidden">
                    <div class="px-6 py-12 text-center text-sm text-brand-moss sm:px-8">
                        {{ __('No phase results recorded for this deployment.') }}
                    </div>
                </section>
            @else
                @foreach ($phases as $phase)
                    @if ($deployment->hasPhase($phase))
                        @php($phaseOk = $deployment->phaseOk($phase))
                        @php($phaseSteps = $deployment->phaseSteps($phase))
                        @php($phaseDurationMs = collect($phaseSteps)->sum(fn ($s) => (int) ($s['duration_ms'] ?? 0)))
                        <section class="dply-card overflow-hidden">
                            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
                                <span @class([
                                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1 ring-inset',
                                    'bg-emerald-100 text-emerald-800 ring-emerald-200' => $phaseOk,
                                    'bg-rose-100 text-rose-800 ring-rose-200' => ! $phaseOk,
                                ])>
                                    @if ($phaseOk)
                                        <x-heroicon-m-check class="h-5 w-5" aria-hidden="true" />
                                    @else
                                        <x-heroicon-m-x-mark class="h-5 w-5" aria-hidden="true" />
                                    @endif
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Phase') }}</p>
                                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink capitalize">{{ str_replace(['_', '-'], ' ', $phase) }}</h2>
                                    <p class="mt-1 text-sm text-brand-moss">
                                        {{ trans_choice('{1} :count step|[2,*] :count steps', count($phaseSteps), ['count' => count($phaseSteps)]) }}
                                        @if ($phaseDurationMs > 0)
                                            · <span class="font-mono">{{ number_format($phaseDurationMs / 1000, 1) }}s</span>
                                        @endif
                                    </p>
                                </div>
                            </div>
                            <ol class="space-y-3 px-6 py-5 sm:px-8">
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
            @endif

            @if (trim((string) $deployment->log_output) !== '')
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Raw output') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deploy log') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Combined stdout/stderr captured by the deploy runner.') }}</p>
                        </div>
                    </div>
                    <pre class="max-h-96 overflow-auto bg-brand-ink p-5 font-mono text-[11px] leading-relaxed text-brand-cream/95 sm:p-6">{{ trim((string) $deployment->log_output) }}</pre>
                </section>
            @endif

            <x-cli-snippet :command="'dply:site:show-deploy '.$deployment->id.' --output'" />
        </main>
    </div>
</div>
