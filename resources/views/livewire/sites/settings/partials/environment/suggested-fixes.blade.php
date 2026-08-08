    {{-- Suggested fixes — one-click remediations the last "Test site" run
         detected from the deployed app's error (e.g. a missing table → Run
         migrations). Persisted on the site so they survive a page load. --}}
    @php
        $healthRemediations = data_get($site->meta, 'health.remediations', []);
        $healthRemediations = is_array($healthRemediations) ? $healthRemediations : [];
    @endphp
    @if ($healthRemediations !== [] && method_exists($this, 'runRemediation'))
        {{-- Built to the same grammar as the "Needs attention" card above it:
             a thin labelled band, then one row per finding with a severity rail.
             The 40px icon tile, the uppercase eyebrow and the headline count were
             three ways of saying "suggested fixes" before the fixes themselves
             appeared, and the full amber wash made a one-line advisory read
             louder than the breaking warnings above. --}}
        <div class="{{ $card }} overflow-hidden">
            <div class="flex items-start gap-3 px-5 py-4 sm:px-6">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700 ring-1 ring-amber-200/70">
                    <x-heroicon-o-wrench-screwdriver class="h-4.5 w-4.5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-base font-semibold tracking-tight text-brand-ink">{{ __('Suggested fixes') }}</h2>
                    <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">
                        {{ trans_choice('{1} :count one-click fix dply detected from the last site test.|[2,*] :count one-click fixes dply detected from the last site test.', count($healthRemediations), ['count' => count($healthRemediations)]) }}
                    </p>
                </div>
            </div>
            <ul class="mx-5 mb-4 divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10 bg-white sm:mx-6">
                @foreach ($healthRemediations as $rem)
                    <li class="flex items-start gap-3 border-l-2 border-l-amber-500 px-4 py-3 transition-colors hover:bg-brand-sand/15">
                        <p class="min-w-0 flex-1 text-xs leading-5 text-brand-ink">{{ $rem['reason'] ?? '' }}</p>
                        <button
                            type="button"
                            wire:click="runRemediation(@js($rem['key']))"
                            wire:loading.attr="disabled"
                            wire:target="runRemediation"
                            class="dply-btn dply-btn-xs bg-amber-600 text-white hover:bg-amber-700"
                        >
                            <x-heroicon-o-play class="h-3 w-3" />
                            {{ $rem['label'] ?? __('Run fix') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
