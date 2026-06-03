<div>
    @if ($this->canDeploy)
        @php
            $lock = $this->deployLockInfo;
            $latest = $this->latestDeployment;
            $inProgress = (bool) $lock || ($latest?->status === 'running');
            // A deploy was just queued (lock held) but the worker hasn't created
            // its running record yet — the "latest" is still the previous run.
            // Show a starting placeholder so the old (often failed) timeline
            // isn't mistaken for the new deploy.
            $startingFresh = $lock !== null && ($latest === null || $latest->status !== 'running');
            $phases = $latest ? \App\Support\Sites\SiteDeployTimeline::forDeployment($this->site, $latest) : [];
        @endphp

        <div
            x-data="{ open: false }"
            x-on:deploy-console-open.window="open = true"
            class="flex items-center gap-2"
            @if ($inProgress) wire:poll.4s @endif
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

                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4" @if ($inProgress) wire:poll.4s @endif>
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
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
