<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <div class="flex min-w-0 items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deployments') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent deployments') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Per-phase build → swap → release → restart status from the deploy runner. Expand any row for step-level detail.') }}
                </p>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <span class="hidden rounded-full bg-brand-sand/50 px-2.5 py-1 text-[11px] font-medium text-brand-moss sm:inline">
                {{ trans_choice('{1} 1 with phase data|[2,*] :count with phase data', $deployments->count(), ['count' => $deployments->count()]) }}
            </span>
            <a href="{{ route('sites.deployments.index', ['server' => $site->server, 'site' => $site]) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:text-brand-forest">
                {{ __('View all') }}
                <x-heroicon-o-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
            </a>
        </div>
    </div>

    <ul class="divide-y divide-brand-ink/5">
        @foreach ($deployments as $deployment)
            @php
                $status = (string) $deployment->status;
                [$dotClasses, $glyph] = match ($status) {
                    'success' => ['bg-emerald-100 text-emerald-700', '✓'],
                    'failed' => ['bg-rose-100 text-rose-700', '✕'],
                    'running' => ['bg-amber-100 text-amber-700', '○'],
                    'skipped' => ['bg-slate-100 text-slate-500', '·'],
                    default => ['bg-brand-sand/60 text-brand-moss', '•'],
                };
                $statusLabelClasses = match ($status) {
                    'success' => 'text-emerald-700',
                    'failed' => 'text-rose-700',
                    'running' => 'text-amber-700',
                    default => 'text-brand-moss',
                };
                $durationMs = $deployment->phaseTotalDurationMs();
                $shortSha = $deployment->git_sha ? substr((string) $deployment->git_sha, 0, 7) : null;
            @endphp
            <li>
                <details class="group">
                    <summary class="flex cursor-pointer list-none items-center gap-3 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7 [&::-webkit-details-marker]:hidden">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $dotClasses }} {{ $status === 'running' ? 'animate-pulse' : '' }}" aria-hidden="true">{{ $glyph }}</span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm">
                                <span class="text-[11px] font-semibold uppercase tracking-[0.14em] {{ $statusLabelClasses }}">{{ $status }}</span>
                                <span class="text-brand-moss">{{ $deployment->started_at?->diffForHumans() ?? '—' }}</span>
                                @if ($deployment->trigger)
                                    <span class="text-brand-mist">·</span>
                                    <span class="text-brand-moss">{{ $deployment->trigger }}</span>
                                @endif
                                @if ($durationMs > 0)
                                    <span class="text-brand-mist">·</span>
                                    <span class="font-mono text-xs text-brand-moss">{{ number_format($durationMs / 1000, 1) }}s</span>
                                @endif
                                @if ($shortSha)
                                    <span class="text-brand-mist">·</span>
                                    <span class="inline-flex items-center gap-1 font-mono text-xs text-brand-moss">
                                        <x-heroicon-o-code-bracket class="h-3 w-3" aria-hidden="true" />{{ $shortSha }}
                                    </span>
                                @endif
                            </div>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                @foreach (['clone', 'build', 'swap', 'activate', 'release', 'restart', 'serverless'] as $phase)
                                    @if ($deployment->hasPhase($phase) && $deployment->phaseSteps($phase) !== [])
                                        <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.1em] {{ $deployment->phaseOk($phase) ? 'bg-brand-sage/12 text-brand-forest' : 'bg-rose-50 text-rose-700' }}">
                                            {{ $phase }}
                                            <span class="font-mono opacity-70">{{ count($deployment->phaseSteps($phase)) }}</span>
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>

                        <a href="{{ route('sites.deployments.show', ['server' => $site->server, 'site' => $site, 'deployment' => $deployment]) }}" wire:navigate
                            class="hidden shrink-0 rounded-md bg-brand-sand/40 px-2 py-1 font-mono text-[10px] text-brand-mist hover:bg-brand-sand/70 hover:text-brand-moss sm:inline-block"
                            title="{{ __('Open deployment detail') }}"
                            x-on:click.stop>{{ \Illuminate\Support\Str::limit((string) $deployment->id, 12, '…') }}</a>

                        <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform group-open:rotate-90" aria-hidden="true" />
                    </summary>

                    <div class="space-y-3 border-t border-brand-ink/5 bg-brand-sand/10 px-6 py-4 sm:px-7">
                        @foreach (['clone', 'build', 'swap', 'activate', 'release', 'restart', 'serverless'] as $phase)
                            @if ($deployment->hasPhase($phase) && $deployment->phaseSteps($phase) !== [])
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $phase }}</p>
                                    <ul class="mt-1.5 space-y-1.5">
                                        @foreach ($deployment->phaseSteps($phase) as $step)
                                            @include('livewire.sites.partials.recent-deployment-step', ['step' => $step])
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endforeach
                        <x-cli-snippet class="text-[10px]" :command="'dply sites:deployment '.$deployment->id.' --output'" />
                    </div>
                </details>
            </li>
        @endforeach
    </ul>
</section>
