    {{-- Missing required env warning. Driven by the scanner's detected
         requirements (refreshed each deploy; re-scan on demand). Lists the
         keys the deployed code expects but that aren't set here, with a
         one-click modal to add them. --}}
    @if ($supportsEnvPush && $envAdvanced && $missingEnv !== [] && ! $envGateOff)
        <div class="dply-card overflow-hidden">
            <div class="flex flex-col gap-3 bg-rose-50 px-5 py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-rose-100 text-rose-700 ring-rose-200">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Missing variables') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-rose-900">
                                {{ trans_choice('{1} :count required variable is missing|[2,*] :count required variables are missing', count($missingEnv), ['count' => count($missingEnv)]) }}
                            </h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-rose-900">
                                {{ __('These are referenced by the deployed code (.env.example, plus env() usage in app code and config/) but aren\'t set here. The app may error until they have values.') }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach (array_slice($missingEnv, 0, 24) as $entry)
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 font-mono text-[11px] font-semibold text-rose-800 ring-1 ring-inset ring-rose-200"
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
                                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-800">
                                        {{ __('+:count more', ['count' => count($missingEnv) - 24]) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-nowrap items-center gap-2 whitespace-nowrap">
                        <button
                            type="button"
                            wire:click="rescanEnvRequirements"
                            wire:loading.attr="disabled"
                            wire:target="rescanEnvRequirements"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                            title="{{ __('Re-scan the deployed code for required variables.') }}"
                        >
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="rescanEnvRequirements" />
                            <span wire:loading wire:target="rescanEnvRequirements" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                            {{ __('Re-scan') }}
                        </button>
                        <button
                            type="button"
                            wire:click="openMissingEnvModal"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-rose-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-rose-800"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                            {{ __('Add missing variables') }}
                        </button>
                        @if ($canIgnoreEnv)
                            <button
                                type="button"
                                wire:click="confirmIgnoreMissingEnv"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                                title="{{ __('Stop warning/blocking on missing required variables for this site.') }}"
                            >
                                <x-heroicon-o-no-symbol class="h-3.5 w-3.5" />
                                {{ __('Ignore all') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Required-env checks are off for this site (operator chose to ignore
         missing vars). Muted reminder with a one-click re-enable. --}}
    @if ($supportsEnvPush && $envGateOff)
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 text-sm text-brand-moss">
            <span class="inline-flex items-center gap-2">
                <x-heroicon-o-no-symbol class="h-4 w-4 text-brand-mist" />
                {{ __('Required-variable checks are off for this site — deploys won\'t be blocked by missing env.') }}
            </span>
            <button type="button" wire:click="enableEnvGate" class="font-semibold text-brand-forest hover:underline">
                {{ __('Re-enable') }}
            </button>
        </div>
    @endif
