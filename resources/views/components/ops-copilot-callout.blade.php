@props([
    'site',
    'show' => null,
    'compact' => false,
])

@php
    $organization = $site->organization ?? null;
    $visible = $show ?? ops_copilot_site_has_failure($site);
    $href = route('fleet.copilot', ['site' => $site->id]);
    $llmEnabled = ai_llm_active($organization);
@endphp

@feature('surface.fleet')
    @if (ops_copilot_active($organization) && $visible)
        @if ($compact)
            <div {{ $attributes->class(['flex flex-col gap-3 rounded-xl border border-violet-200 bg-violet-50/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-between']) }}>
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-700 ring-1 ring-violet-200">
                        <x-heroicon-o-sparkles class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Ops Copilot can explain this failure') }}</p>
                        <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">
                            {{ __('Log excerpts, repo config, and fix suggestions across BYO and Edge deploys.') }}
                            @if ($llmEnabled)
                                <span class="text-violet-700">{{ __('AI analysis available.') }}</span>
                            @endif
                        </p>
                    </div>
                </div>
                <a
                    href="{{ $href }}"
                    wire:navigate
                    class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg bg-violet-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-violet-800 sm:self-center"
                >
                    {{ __('Open Ops Copilot') }}
                    <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                </a>
            </div>
        @else
            <section {{ $attributes->class(['scroll-mt-24 overflow-hidden rounded-2xl border border-violet-200 bg-gradient-to-b from-violet-50/90 to-white shadow-sm']) }}>
                <div class="flex flex-col gap-4 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-7">
                    <div class="flex min-w-0 items-start gap-3">
                        <x-icon-badge tone="violet">
                            <x-heroicon-o-sparkles class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-violet-800">{{ __('Ops Copilot') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Explain this deploy failure') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Fleet Copilot assembles the latest failure log, repo config snapshot, intelligence alerts, and heuristic fix suggestions for :site.', ['site' => $site->name]) }}
                                @if ($llmEnabled)
                                    {{ __('Queue AI analysis for a deeper narrative and next steps.') }}
                                @endif
                            </p>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:pt-1">
                        @if ($llmEnabled)
                            <span class="inline-flex items-center rounded-full bg-violet-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-violet-800 ring-1 ring-violet-200">
                                {{ __('AI ready') }}
                            </span>
                        @endif
                        <a
                            href="{{ $href }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg bg-violet-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-violet-800"
                        >
                            {{ __('Open Ops Copilot') }}
                            <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                    </div>
                </div>
            </section>
        @endif
    @endif
@endfeature
