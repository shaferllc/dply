{{-- Onboarding checklist. --}}
@if (! $onboardingComplete && $onboardingTotal > 0)
    @php $onboardingPct = max(0, min(100, (int) round(100 * $onboardingDone / $onboardingTotal))); @endphp
    <section
        data-testid="server-onboarding-checklist"
        x-data="{ open: @js($onboardingDone === 0) }"
        class="dply-card overflow-hidden"
    >
        <div class="px-6 pt-5 pb-4 sm:px-7">
            <button
                type="button"
                x-on:click="open = ! open"
                class="flex w-full items-start gap-3 text-left"
            >
                <x-icon-badge>
                    <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                        {{ trans_choice('{1} :n step left to make this server useful|[2,*] :n steps left to make this server useful', $onboardingTotal - $onboardingDone, ['n' => $onboardingTotal - $onboardingDone]) }}
                    </h3>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <div class="hidden w-32 sm:block">
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/5">
                            <div class="h-full rounded-full bg-sky-500 transition-[width] duration-500" style="width: {{ $onboardingPct }}%"></div>
                        </div>
                    </div>
                    <span class="rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-sky-700 ring-1 ring-sky-200">{{ $onboardingDone }}/{{ $onboardingTotal }}</span>
                    <x-heroicon-m-chevron-down class="h-5 w-5 text-brand-moss transition-transform" x-bind:class="{ 'rotate-180': open }" />
                </div>
            </button>
        </div>
        <ul x-show="open" x-collapse x-cloak class="divide-y divide-brand-ink/10">
            @foreach ($onboardingSteps as $step)
                <li class="flex items-center justify-between gap-3 px-6 py-3 sm:px-7">
                    <div class="flex min-w-0 items-start gap-3">
                        @if ($step['done'])
                            <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white">
                                <x-heroicon-m-check class="h-4 w-4" />
                            </span>
                        @else
                            <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-sky-300 bg-white"></span>
                        @endif
                        <div class="min-w-0">
                            <p class="text-sm {{ $step['done'] ? 'text-brand-moss line-through' : 'font-medium text-brand-ink' }}">{{ $step['label'] }}</p>
                            @if (! $step['done'])
                                <p class="mt-0.5 text-xs text-brand-moss">{{ $step['help'] }}</p>
                            @endif
                        </div>
                    </div>
                    @if (! $step['done'])
                        <a href="{{ $step['cta_route'] }}" wire:navigate class="shrink-0 inline-flex items-center gap-1 whitespace-nowrap rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-sky-700">
                            {{ $step['cta_label'] }}
                            <x-heroicon-m-arrow-right class="h-4 w-4 shrink-0" aria-hidden="true" />
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
