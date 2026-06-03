<div>
    @if ($this->canDeploy)
        @php
            $lock = $this->deployLockInfo;
            $latest = $this->latestDeployment;
            $inProgress = (bool) $lock || ($latest?->status === 'running');
            // A deploy was just queued (lock held) but the worker hasn't created
            // its running record yet — the "latest" is still the previous run.
            // Show a starting placeholder so the old (often failed) timeline
            // isn't mistaken for the new deploy. Guard on the lock being NEWER
            // than the last finished run so a stale lock doesn't fake "starting".
            $lockStarted = ($lock && ! empty($lock['started_at'])) ? \Illuminate\Support\Carbon::parse($lock['started_at']) : null;
            $startingFresh = $lock !== null && (
                $latest === null
                || ($latest->status !== 'running'
                    && ($latest->finished_at === null || $lockStarted === null || $lockStarted->greaterThanOrEqualTo($latest->finished_at)))
            );
            $phases = $latest ? \App\Support\Sites\SiteDeployTimeline::forDeployment($this->site, $latest) : [];

            // Smart-fix detection on the FAILED deploy output (e.g. npm not
            // found → Install Node.js & npm), so the fix is offered inline.
            $deployFixers = [];
            if ($latest && $latest->status === 'failed') {
                $failOutput = '';
                foreach ($phases as $ph) {
                    foreach ($ph['steps'] as $st) {
                        if (! ($st['ok'] ?? true) && ! ($st['skipped'] ?? false)) {
                            $failOutput .= ' '.($st['output'] ?? '');
                        }
                    }
                }
                $deployFixers = \App\Support\Sites\SiteFixers::detect($failOutput);
            }

            $fixerRun = $this->fixerRun;
            $fixerInFlight = $fixerRun && $fixerRun->isInFlight();
        @endphp

        <div
            x-data="{ open: false }"
            x-on:deploy-console-open.window="open = true"
            class="flex items-center gap-2"
            @if ($inProgress || $fixerInFlight) wire:poll.3s @endif
        >
            {{-- Deploy now — available from any site page. --}}
            <button
                type="button"
                wire:click="deploy"
                wire:loading.attr="disabled"
                wire:target="deploy"
                @disabled($inProgress)
                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60"
            >
                @if ($inProgress)
                    <x-spinner variant="white" size="sm" />
                    <span>{{ __('Deploying…') }}</span>
                @else
                    <x-heroicon-o-rocket-launch class="h-3.5 w-3.5" wire:loading.remove wire:target="deploy" />
                    <span wire:loading wire:target="deploy" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="white" size="sm" /></span>
                    <span>{{ __('Deploy') }}</span>
                @endif
            </button>

            {{-- Console toggle. --}}
            <button
                type="button"
                x-on:click="open = ! open"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
            >
                <x-heroicon-o-command-line class="h-3.5 w-3.5" />
                {{ __('Console') }}
                @if ($inProgress)
                    <span class="relative flex h-2 w-2">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-amber-500"></span>
                    </span>
                @endif
            </button>

            {{-- Slide-over console: live phase timeline for the latest deploy. --}}
            <div x-show="open" x-cloak class="fixed inset-0 z-50" style="display: none;">
                <div class="absolute inset-0 bg-brand-ink/40" x-on:click="open = false" x-transition.opacity></div>
                <div
                    class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="translate-x-full"
                    x-transition:enter-end="translate-x-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="translate-x-0"
                    x-transition:leave-end="translate-x-full"
                >
                    <div class="flex items-center justify-between border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4">
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy console') }}</p>
                            <p class="truncate text-sm font-semibold text-brand-ink">{{ $site->name ?? $site->domain }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                wire:click="deploy"
                                @disabled($inProgress)
                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1.5 text-[11px] font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-60"
                            >
                                @if ($inProgress)
                                    <x-spinner variant="white" size="sm" /> {{ __('Deploying…') }}
                                @else
                                    <x-heroicon-o-rocket-launch class="h-3.5 w-3.5" /> {{ __('Deploy now') }}
                                @endif
                            </button>
                            <button type="button" x-on:click="open = false" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                                <x-heroicon-o-x-mark class="h-5 w-5" />
                            </button>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4" @if ($inProgress || $fixerInFlight) wire:poll.3s @endif>
                        @if ($startingFresh)
                            <div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-amber-200 bg-amber-50/50 px-4 py-10 text-center">
                                <x-spinner size="sm" />
                                <p class="text-sm font-medium text-brand-ink">{{ __('Deploy starting — clearing the previous run…') }}</p>
                                <p class="text-xs text-brand-moss">{{ __('The new deploy is queued on a worker. This refreshes the moment it records its first phase.') }}</p>
                            </div>
                        @elseif ($latest === null)
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-8 text-center text-sm text-brand-moss">
                                {{ __('No deploys yet. Hit Deploy to ship the current branch.') }}
                            </div>
                        @else
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset',
                                    'bg-emerald-50 text-emerald-800 ring-emerald-200' => $latest->status === 'success',
                                    'bg-rose-50 text-rose-800 ring-rose-200' => $latest->status === 'failed',
                                    'bg-amber-50 text-amber-900 ring-amber-200' => $latest->status === 'running',
                                    'bg-brand-sand/60 text-brand-ink ring-brand-ink/10' => ! in_array($latest->status, ['success', 'failed', 'running'], true),
                                ])>{{ $latest->status }}</span>
                                <a href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $latest]) }}" wire:navigate class="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-forest hover:underline">
                                    {{ __('Full log') }}
                                    <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" />
                                </a>
                            </div>

                            <ol class="space-y-2">
                                @foreach ($phases as $phase)
                                    @php $st = $phase['status']; $durTxt = ($phase['duration_ms'] ?? 0) > 0 ? number_format($phase['duration_ms'] / 1000, 1).'s' : null; @endphp
                                    <li @class([
                                        'rounded-xl border px-3 py-2',
                                        'border-emerald-200 bg-emerald-50/50' => $st === 'success',
                                        'border-rose-200 bg-rose-50/50' => $st === 'failed',
                                        'border-amber-200 bg-amber-50/50' => $st === 'running',
                                        'border-brand-ink/10 bg-brand-sand/10' => in_array($st, ['skipped', 'pending'], true),
                                    ])>
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                                @switch ($st)
                                                    @case('success') <x-heroicon-m-check-circle class="h-4 w-4 text-emerald-600" /> @break
                                                    @case('failed') <x-heroicon-m-x-circle class="h-4 w-4 text-rose-600" /> @break
                                                    @case('running') <x-heroicon-m-arrow-path class="h-4 w-4 animate-spin text-amber-600" /> @break
                                                    @default <x-heroicon-m-minus-circle class="h-4 w-4 text-brand-mist" />
                                                @endswitch
                                                {{ $phase['label'] }}
                                            </span>
                                            @if ($durTxt)<span class="font-mono text-[11px] text-brand-mist">{{ $durTxt }}</span>@endif
                                        </div>
                                        @foreach ($phase['steps'] as $step)
                                            @if (! $step['ok'] && ! $step['skipped'] && ($step['output'] ?? '') !== '')
                                                <pre class="mt-1.5 max-h-40 overflow-auto rounded-lg bg-brand-ink p-2.5 font-mono text-[11px] leading-relaxed text-rose-100/95">{{ $step['output'] }}</pre>
                                            @endif
                                        @endforeach
                                    </li>
                                @endforeach
                            </ol>

                            {{-- Inline smart fixes for a failed deploy. --}}
                            @if ($deployFixers !== [])
                                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3">
                                    <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">
                                        <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5" />
                                        {{ trans_choice('{1} Suggested fix|[2,*] Suggested fixes', count($deployFixers)) }}
                                    </p>
                                    <ul class="mt-2 space-y-2">
                                        @foreach ($deployFixers as $fx)
                                            @php $thisRunning = $fixerInFlight && $fixerRunKey === $fx['key']; @endphp
                                            <li class="flex flex-wrap items-center justify-between gap-2">
                                                <span class="min-w-0 flex-1 text-xs text-amber-900">{{ $fx['reason'] }}</span>
                                                <button
                                                    type="button"
                                                    wire:click="runFixer(@js($fx['key']))"
                                                    wire:loading.attr="disabled"
                                                    wire:target="runFixer"
                                                    @disabled($fixerInFlight)
                                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-amber-600 px-2.5 py-1.5 text-[11px] font-semibold text-white shadow-sm transition-colors hover:bg-amber-700 disabled:opacity-60"
                                                >
                                                    @if ($thisRunning)
                                                        <x-spinner variant="white" size="sm" /> {{ __('Processing…') }}
                                                    @else
                                                        <x-heroicon-o-play class="h-3.5 w-3.5" /> {{ $fx['label'] }}
                                                    @endif
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            {{-- Live output of the fix running from this drawer. --}}
                            @if ($fixerRun)
                                <div class="mt-3 overflow-hidden rounded-xl border border-brand-ink/10">
                                    <div class="flex items-center justify-between gap-2 bg-brand-sand/20 px-3 py-2">
                                        <span class="flex items-center gap-1.5 text-[11px] font-semibold text-brand-ink">
                                            @if ($fixerInFlight)
                                                <x-spinner size="sm" /> {{ $fixerRun->label ?? __('Fix') }} · {{ __('processing…') }}
                                            @elseif ($fixerRun->status === 'completed')
                                                <x-heroicon-m-check-circle class="h-4 w-4 text-emerald-600" /> {{ $fixerRun->label ?? __('Fix') }} · {{ __('done') }}
                                            @else
                                                <x-heroicon-m-x-circle class="h-4 w-4 text-rose-600" /> {{ $fixerRun->label ?? __('Fix') }} · {{ __('failed') }}
                                            @endif
                                        </span>
                                        @if (! $fixerInFlight && $fixerRun->status === 'completed')
                                            <button type="button" wire:click="deploy" class="inline-flex items-center gap-1 rounded-lg bg-brand-ink px-2 py-1 text-[10px] font-semibold text-brand-cream hover:bg-brand-forest">
                                                <x-heroicon-o-rocket-launch class="h-3 w-3" /> {{ __('Deploy now') }}
                                            </button>
                                        @endif
                                    </div>
                                    @php $fixLines = $fixerRun->lines(); @endphp
                                    <pre class="max-h-56 overflow-auto bg-brand-ink p-3 font-mono text-[11px] leading-relaxed text-brand-cream/95" x-init="$el.scrollTop = $el.scrollHeight">@forelse ($fixLines as $ln)@if (! empty($ln['source']))<span class="text-brand-sage">[{{ $ln['source'] }}]</span> @endif{{ $ln['line'] ?? '' }}
@empty{{ __('Queued — starting…') }}@endforelse</pre>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
