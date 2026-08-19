{{-- One card per unique deploy-hub fix. Pipeline-check runtime actions,
     remediations catalog hits, and SiteFixers are collapsed here so Upgrade
     PHP and Install php-redis never stack as three overlapping lists. --}}
@php
    $fixBusy = method_exists($this, 'deploymentRemediationRun')
        && ($this->deploymentRemediationRun?->isInFlight() ?? false)
        && ! ($this->deploymentRemediationRun?->isStale() ?? true);
    $fixerRun = method_exists($this, 'fixerRun') ? $this->fixerRun : null;
    $fixerInFlight = $fixerRun && $fixerRun->isInFlight();
    $activeFixerKey = method_exists($this, 'fixerRunKey') ? $this->fixerRunKey : null;
@endphp

@if ($deployFixCards !== [])
    <div class="space-y-2 border-b border-brand-ink/10 bg-brand-sand/10 px-3 py-2.5 sm:px-4">
        @foreach ($deployFixCards as $card)
            @php
                $thisBusy = match ($card['source'] ?? '') {
                    'pipeline', 'remediation' => $fixBusy,
                    'fixer' => $fixerInFlight && $activeFixerKey === ($card['id'] ?? null),
                    default => false,
                };
            @endphp
            <article wire:key="deploy-fix-{{ $card['id'] }}" class="rounded-xl border border-amber-200 bg-amber-50/80 p-3">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('Fix') }}</p>
                <h3 class="mt-0.5 text-sm font-semibold text-brand-ink">{{ $card['title'] }}</h3>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $card['reason'] }}</p>
                <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                    @if (! empty($card['href']))
                        <a href="{{ $card['href'] }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest">
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" />
                            {{ $card['button'] }}
                        </a>
                    @elseif (($card['method'] ?? '') === 'addSuggestedPipelineStep')
                        @if ($thisBusy)
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-900">
                                <x-spinner size="sm" />
                                {{ __('Installing…') }}
                            </span>
                        @else
                            <button
                                type="button"
                                wire:click="addSuggestedPipelineStep(@js($card['args'][0]))"
                                wire:loading.attr="disabled"
                                wire:target="addSuggestedPipelineStep"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-60"
                            >
                                <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5" />
                                {{ $card['button'] }}
                            </button>
                        @endif
                    @elseif (($card['method'] ?? '') === 'applyDeploymentRemediation')
                        <button
                            type="button"
                            wire:click="applyDeploymentRemediation(@js($card['args'][0]), @js($card['args'][1]))"
                            wire:loading.attr="disabled"
                            wire:target="applyDeploymentRemediation"
                            @disabled($thisBusy)
                            @class([
                                'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold shadow-sm disabled:opacity-60',
                                'bg-sky-100 text-sky-900' => $thisBusy,
                                'bg-brand-ink text-brand-cream hover:bg-brand-forest' => ! $thisBusy,
                            ])
                        >
                            @if ($thisBusy)
                                <x-spinner size="sm" />
                                {{ __('Installing…') }}
                            @else
                                <x-heroicon-o-wrench class="h-3.5 w-3.5" />
                                {{ $card['button'] }}
                            @endif
                        </button>
                    @elseif (($card['method'] ?? '') === 'runFixer')
                        <button
                            type="button"
                            wire:click="runFixer(@js($card['args'][0]))"
                            wire:loading.attr="disabled"
                            wire:target="runFixer"
                            @disabled($fixerInFlight)
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-60"
                        >
                            @if ($thisBusy)
                                <x-spinner variant="white" size="sm" />
                                {{ __('Processing…') }}
                            @else
                                <x-heroicon-o-play class="h-3.5 w-3.5" />
                                {{ $card['button'] }}
                            @endif
                        </button>
                    @endif

                    @if (! empty($card['dismiss_key']) && method_exists($this, 'dismissPipelineSuggestion'))
                        <button
                            type="button"
                            wire:click="dismissPipelineSuggestion(@js($card['dismiss_key']))"
                            wire:loading.attr="disabled"
                            wire:target="addSuggestedPipelineStep, dismissPipelineSuggestion"
                            class="inline-flex items-center justify-center rounded-lg border border-transparent p-1.5 text-brand-mist hover:border-brand-ink/10 hover:bg-white/70 hover:text-brand-moss disabled:opacity-60"
                            title="{{ __('Dismiss this suggestion') }}"
                            aria-label="{{ __('Dismiss :label', ['label' => $card['title']]) }}"
                        >
                            <x-heroicon-o-x-mark class="h-4 w-4" />
                        </button>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
@endif
