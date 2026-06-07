    {{-- Suggested fixes — one-click remediations the last "Test site" run
         detected from the deployed app's error (e.g. a missing table → Run
         migrations). Persisted on the site so they survive a page load. --}}
    @php
        $healthRemediations = data_get($site->meta, 'health.remediations', []);
        $healthRemediations = is_array($healthRemediations) ? $healthRemediations : [];
    @endphp
    @if ($healthRemediations !== [] && method_exists($this, 'runRemediation'))
        <div class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 bg-amber-50 px-5 py-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-100 text-amber-700 ring-amber-200">
                    <x-heroicon-o-wrench-screwdriver class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('Suggested fixes') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-amber-950">
                        {{ trans_choice('{1} :count one-click fix from the last site test|[2,*] :count one-click fixes from the last site test', count($healthRemediations), ['count' => count($healthRemediations)]) }}
                    </h3>
                    <ul class="mt-2 space-y-2">
                        @foreach ($healthRemediations as $rem)
                            <li class="flex flex-wrap items-center justify-between gap-2">
                                <span class="flex min-w-0 items-start gap-2 text-sm text-amber-900">
                                    <span class="mt-1.5 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                                    <span>{{ $rem['reason'] ?? '' }}</span>
                                </span>
                                <button
                                    type="button"
                                    wire:click="runRemediation(@js($rem['key']))"
                                    wire:loading.attr="disabled"
                                    wire:target="runRemediation"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-amber-700 disabled:opacity-60"
                                >
                                    <x-heroicon-o-play class="h-4 w-4" />
                                    {{ $rem['label'] ?? __('Run fix') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif
