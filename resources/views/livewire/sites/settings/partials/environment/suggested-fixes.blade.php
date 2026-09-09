    {{-- Suggested fixes — one-click remediations the last "Test site" run
         detected from the deployed app's error (e.g. a missing table → Run
         migrations). Persisted on the site so they survive a page load. --}}
    @php
        $healthRemediations = data_get($site->meta, 'health.remediations', []);
        $healthRemediations = is_array($healthRemediations) ? $healthRemediations : [];
    @endphp
    @if ($healthRemediations !== [] && method_exists($this, 'runRemediation'))
        <div class="{{ $card }} overflow-hidden">
            <x-workspace-panel-head
                class="border-b border-brand-ink/10"
                icon="heroicon-o-wrench-screwdriver"
                :title="__('Suggested fixes')"
                :note="trans_choice('{1} :count one-click fix dply detected from the last site test.|[2,*] :count one-click fixes dply detected from the last site test.', count($healthRemediations), ['count' => count($healthRemediations)])"
                tone="amber"
            />
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
