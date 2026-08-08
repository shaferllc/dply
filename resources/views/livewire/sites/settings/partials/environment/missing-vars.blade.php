    {{-- Missing required env warning. Driven by the scanner's detected
         requirements (refreshed each deploy; re-scan on demand). Lists the
         keys the deployed code expects but that aren't set here, with a
         one-click modal to add them. --}}
    @if ($supportsEnvPush && $envAdvanced && $missingEnv !== [] && ! $envGateOff)
        {{-- Same compact grammar as the config-check row above it: heading line,
             then the payload. The 40px icon tile, the uppercase eyebrow and the
             two-line explainer were three separate ways of saying "missing
             variables" before the keys — which are the actual content — appeared. --}}
        <div class="px-5 pb-4 sm:px-6">
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 pb-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0 text-rose-600" />
                        <h3 class="text-xs font-semibold text-brand-ink">
                            {{ trans_choice('{1} :count required variable is missing|[2,*] :count required variables are missing', count($missingEnv), ['count' => count($missingEnv)]) }}
                        </h3>
                        <span class="text-[11px] text-brand-moss">{{ __('referenced by the deployed code but not set here') }}</span>
                    </div>

                    <div class="flex shrink-0 flex-nowrap items-center gap-1.5 whitespace-nowrap">
                        <button
                            type="button"
                            wire:click="rescanEnvRequirements"
                            wire:loading.attr="disabled"
                            wire:target="rescanEnvRequirements"
                            class="dply-btn dply-btn-xs dply-btn-outline"
                            title="{{ __('Re-scan the deployed code for required variables.') }}"
                        >
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="rescanEnvRequirements" />
                            <span wire:loading wire:target="rescanEnvRequirements" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                            {{ __('Re-scan') }}
                        </button>
                        <button
                            type="button"
                            wire:click="openMissingEnvModal"
                            class="dply-btn dply-btn-xs dply-btn-danger"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                            {{ __('Add all') }}
                        </button>
                        @if ($canIgnoreEnv)
                            <button
                                type="button"
                                wire:click="confirmIgnoreMissingEnv"
                                class="dply-btn dply-btn-xs dply-btn-ghost"
                                title="{{ __('Stop warning/blocking on missing required variables for this site.') }}"
                            >
                                {{ __('Ignore all') }}
                            </button>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap gap-1.5 rounded-xl border border-brand-ink/10 bg-white px-4 py-3">
                    @foreach (array_slice($missingEnv, 0, 24) as $entry)
                        <span
                            class="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 font-mono text-[11px] font-semibold text-rose-800"
                            title="{{ __('source: :s', ['s' => implode(', ', $entry['sources'])]) }}"
                        >
                            {{ $entry['key'] }}
                            @if ($canIgnoreEnv)
                                <button type="button" wire:click="confirmIgnoreEnvKey('{{ $entry['key'] }}')" class="-mr-0.5 text-rose-400 hover:text-rose-700" title="{{ __('Ignore :key', ['key' => $entry['key']]) }}" aria-label="{{ __('Ignore :key', ['key' => $entry['key']]) }}">
                                    <x-heroicon-o-x-mark class="h-3 w-3" />
                                </button>
                            @endif
                        </span>
                    @endforeach
                    @if (count($missingEnv) > 24)
                        <span class="inline-flex items-center rounded-md bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-800">
                            {{ __('+:count more', ['count' => count($missingEnv) - 24]) }}
                        </span>
                    @endif
                </div>
            </div>
    @endif

    {{-- Required-env checks are off for this site (operator chose to ignore
         missing vars). Muted reminder with a one-click re-enable. --}}
    @if ($supportsEnvPush && $envGateOff)
        <div class="mx-5 mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-xs text-brand-moss sm:mx-6">
            <span class="inline-flex items-center gap-1.5">
                <x-heroicon-o-no-symbol class="h-3.5 w-3.5 text-brand-mist" />
                {{ __('Required-variable checks are off for this site — deploys won\'t be blocked by missing env.') }}
            </span>
            <button type="button" wire:click="enableEnvGate" class="dply-btn dply-btn-xs dply-btn-outline">
                {{ __('Re-enable') }}
            </button>
        </div>
    @endif
