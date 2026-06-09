@php $optPreview = $site->meta['pipeline_optimize_preview'] ?? null; @endphp

@if (is_array($optPreview) && ! empty($optPreview['steps']))
    {{-- Auto-open once per scan (keyed on its timestamp) so closing it without
         applying doesn't trigger a reopen loop on the next Livewire render. --}}
    <div
        x-data
        x-init="
            const key = 'dply-opt-preview-{{ $site->id }}';
            if (localStorage.getItem(key) !== @js($optPreview['at'])) {
                localStorage.setItem(key, @js($optPreview['at']));
                $dispatch('open-modal', 'pipeline-optimize-preview');
            }
        "
    ></div>

    <x-modal
        name="pipeline-optimize-preview"
        :show="false"
        maxWidth="lg"
        overlayClass="bg-brand-ink/30"
        panelClass="dply-modal-panel overflow-hidden shadow-xl"
        focusable
    >
        <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
            <x-icon-badge tone="indigo">
                <x-heroicon-o-sparkles class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-indigo-700">{{ __('Optimize pipeline') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Review proposed changes') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ __('These steps are missing for the detected stack. Nothing changes until you apply.') }}
                </p>
            </div>
        </div>

        <ul class="max-h-[min(60vh,420px)] divide-y divide-brand-ink/10 overflow-y-auto px-6 py-2">
            @foreach ($optPreview['steps'] as $step)
                <li class="flex items-start gap-3 py-3">
                    <span class="mt-0.5 inline-flex shrink-0 items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200">+ {{ __('Add') }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-brand-ink">{{ $step['label'] ?? $step['type'] ?? '' }}</p>
                        @if (! empty($step['command']))
                            <p class="mt-0.5 font-mono text-[11px] text-brand-moss">{{ $step['command'] }}</p>
                        @endif
                    </div>
                    <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $step['phase'] ?? '' }}</span>
                </li>
            @endforeach
        </ul>

        <div class="flex items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4">
            <button
                type="button"
                wire:click="discardPipelineOptimization"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
            >
                {{ __('Discard') }}
            </button>
            <button
                type="button"
                wire:click="applyPipelineOptimization"
                wire:loading.attr="disabled"
                wire:target="applyPipelineOptimization"
                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60"
            >
                <x-heroicon-o-check class="h-4 w-4" aria-hidden="true" />
                {{ trans_choice('{1} Apply :count change|[2,*] Apply :count changes', count($optPreview['steps']), ['count' => count($optPreview['steps'])]) }}
            </button>
        </div>
    </x-modal>
@endif
