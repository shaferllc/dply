{{-- Onboarding checklist. --}}
@if (! $onboardingComplete && $onboardingTotal > 0)
    @php $onboardingPct = max(0, min(100, (int) round(100 * $onboardingDone / $onboardingTotal))); @endphp
    <section
        data-testid="server-onboarding-checklist"
        x-data="{ open: @js($onboardingDone === 0) }"
        class="dply-card overflow-hidden p-0"
    >
        {{-- Dense head, matching every other card on Overview: 4x4 icon inline
             with the title, progress + count + chevron on the right. The 10x10
             badge and its own padded row were spending ~40px on a control that
             is mostly collapsed. --}}
        <button
            type="button"
            x-on:click="open = ! open"
            class="flex w-full flex-wrap items-center gap-x-2 gap-y-1 bg-brand-sand/20 px-3 py-2 text-left sm:px-4"
        >
            <h3 class="flex min-w-0 shrink items-center gap-1.5 text-sm font-semibold text-brand-ink">
                <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                <span class="truncate">{{ trans_choice('{1} :n step left to make this server useful|[2,*] :n steps left to make this server useful', $onboardingTotal - $onboardingDone, ['n' => $onboardingTotal - $onboardingDone]) }}</span>
            </h3>
            <div class="ml-auto flex shrink-0 items-center gap-2">
                <div class="hidden w-24 sm:block">
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/5">
                        <div class="h-full rounded-full bg-sky-500 transition-[width] duration-500" style="width: {{ $onboardingPct }}%"></div>
                    </div>
                </div>
                <span class="rounded-full bg-sky-50 px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-sky-700 ring-1 ring-sky-200">{{ $onboardingDone }}/{{ $onboardingTotal }}</span>
                <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-moss transition-transform" x-bind:class="{ 'rotate-180': open }" />
            </div>
        </button>
        <ul x-show="open" x-collapse x-cloak class="divide-y divide-brand-ink/10 border-t border-brand-ink/10">
            @foreach ($onboardingSteps as $step)
                <li class="flex items-center justify-between gap-3 px-3 py-2 sm:px-4">
                    <div class="flex min-w-0 items-start gap-2.5">
                        @if ($step['done'])
                            <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white">
                                <x-heroicon-m-check class="h-3 w-3" />
                            </span>
                        @else
                            <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-sky-300 bg-white"></span>
                        @endif
                        <div class="min-w-0">
                            <p class="text-xs {{ $step['done'] ? 'text-brand-moss line-through' : 'font-semibold text-brand-ink' }}">{{ $step['label'] }}</p>
                            @if (! $step['done'])
                                <p class="mt-0.5 text-xs text-brand-moss">{{ $step['help'] }}</p>
                            @endif
                        </div>
                    </div>
                    @if (! $step['done'])
                        <a href="{{ $step['cta_route'] }}" wire:navigate class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-lg bg-sky-600 px-2.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-sky-700">
                            {{ $step['cta_label'] }}
                            <x-heroicon-m-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
