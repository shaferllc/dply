@props([
    'site',
    'show' => null,
    'compact' => false,
    'strip' => false,
])

@php
    $organization = $site->organization ?? null;
    $visible = $show ?? ops_copilot_site_has_failure($site);
    $href = route('infrastructure.copilot', ['site' => $site->id]);
    $llmEnabled = ai_llm_active($organization);
@endphp

@if (ops_copilot_active($organization) && $visible)
    @if ($strip)
        <div {{ $attributes->class(['flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 border-b border-brand-ink/10 bg-brand-sand/15 px-5 py-2 sm:px-6']) }}>
            <p class="flex min-w-0 items-center gap-2 text-xs text-brand-moss">
                <x-heroicon-o-sparkles class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                <span class="min-w-0">
                    <span class="font-semibold text-brand-ink">{{ __('Ops Copilot') }}</span>
                    <span class="text-brand-mist"> · </span>
                    {{ __('Explain this deploy failure') }}
                    @if ($llmEnabled)
                        <span class="text-brand-mist">{{ __('· AI available') }}</span>
                    @endif
                </span>
            </p>
            <a
                href="{{ $href }}"
                wire:navigate
                class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-brand-forest transition hover:text-brand-sage hover:underline"
            >
                {{ __('Open') }}
                <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
            </a>
        </div>
    @elseif ($compact)
        <div {{ $attributes->class(['flex flex-col gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 sm:flex-row sm:items-center sm:justify-between']) }}>
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand text-brand-sage ring-1 ring-brand-ink/10">
                    <x-heroicon-o-sparkles class="h-4 w-4" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Ops Copilot can explain this failure') }}</p>
                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">
                        {{ __('Log excerpts, repo config, and fix suggestions across BYO and Edge deploys.') }}
                        @if ($llmEnabled)
                            <span class="text-brand-forest">{{ __('AI analysis available.') }}</span>
                        @endif
                    </p>
                </div>
            </div>
            <a
                href="{{ $href }}"
                wire:navigate
                class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 sm:self-center"
            >
                {{ __('Open Ops Copilot') }}
                <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
            </a>
        </div>
    @else
        <section {{ $attributes->class(['scroll-mt-24 overflow-hidden rounded-2xl border border-brand-ink/10 bg-brand-sand/15']) }}>
            <div class="flex flex-col gap-4 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-7">
                <div class="flex min-w-0 items-start gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-sparkles class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Ops Copilot') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Explain this deploy failure') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Ops Copilot assembles the latest failure log, repo config snapshot, intelligence alerts, and heuristic fix suggestions for :site.', ['site' => $site->name]) }}
                            @if ($llmEnabled)
                                {{ __('Queue AI analysis for a deeper narrative and next steps.') }}
                            @endif
                        </p>
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2 sm:pt-1">
                    @if ($llmEnabled)
                        <span class="inline-flex items-center rounded-full bg-brand-sand px-2.5 py-1 text-2xs font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-ink/10">
                            {{ __('AI ready') }}
                        </span>
                    @endif
                    <a
                        href="{{ $href }}"
                        wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                    >
                        {{ __('Open Ops Copilot') }}
                        <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </div>
            </div>
        </section>
    @endif
@endif
