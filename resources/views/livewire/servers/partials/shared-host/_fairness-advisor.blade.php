@php
    $advisor = $advisor ?? [];
    $recommendations = $advisor['recommendations'] ?? [];
    $severity = (string) ($advisor['severity'] ?? 'info');
    $tone = match ($severity) {
        'critical' => 'border-rose-200 bg-rose-50/60 ring-rose-200',
        'warning' => 'border-amber-200 bg-amber-50/50 ring-amber-200',
        default => 'border-brand-sage/30 bg-brand-sage/5 ring-brand-sage/20',
    };
@endphp

<section id="fairness-advisor" class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 px-6 py-5 sm:px-7">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Fairness Advisor') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('What to do next') }}</h2>
                <p class="mt-1 text-sm text-brand-moss">{{ $advisor['summary'] ?? '' }}</p>
            </div>
            @if (ai_llm_active() && ($llmCanRun ?? false))
                <button
                    type="button"
                    wire:click="generateSharedHostLlmAnalysis"
                    wire:loading.attr="disabled"
                    wire:target="generateSharedHostLlmAnalysis"
                    @disabled($llmRun?->isPending())
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-forest/30 bg-white px-3 py-1.5 text-xs font-semibold text-brand-forest shadow-sm hover:bg-brand-sage/10 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="generateSharedHostLlmAnalysis">{{ __('AI summary') }}</span>
                    <span wire:loading wire:target="generateSharedHostLlmAnalysis">{{ __('Summarizing…') }}</span>
                </button>
            @endif
        </div>
    </div>

    @if ($llmNarrative ?? null)
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('AI summary') }}</p>
            <p class="mt-2 text-sm leading-relaxed text-brand-ink">{{ $llmNarrative }}</p>
        </div>
    @elseif ($llmRun?->isPending())
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-7" wire:poll.3s="refreshSharedHostLlmRun">
            <p class="text-sm text-brand-moss">{{ __('AI summary is generating…') }}</p>
        </div>
    @endif

    <div class="space-y-3 px-6 py-5 sm:px-7">
        @forelse ($recommendations as $recommendation)
            <article class="rounded-2xl border p-4 ring-1 {{ $tone }}">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ $recommendation['title'] }}</h3>
                    <span class="rounded-full bg-white/80 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                        {{ $recommendation['confidence'] ?? 'medium' }}
                    </span>
                </div>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ $recommendation['summary'] }}</p>
                @if (! empty($recommendation['actions']))
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($recommendation['actions'] as $action)
                            <a href="{{ $action['url'] }}" wire:navigate class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50">
                                {{ $action['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <p class="text-sm text-brand-moss">{{ __('No fairness actions needed right now. Re-scan after deploys or when adding sites.') }}</p>
        @endforelse
    </div>
</section>
