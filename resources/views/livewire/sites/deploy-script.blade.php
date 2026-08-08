@php
    $isEmbedded = $embedded ?? false;
    $phases = [
        'build' => ['label' => __('Build'), 'desc' => __('After clone, before activate — deps and assets.')],
        'release' => ['label' => __('Release'), 'desc' => __('Before cutover — migrations, cache warm. Failure aborts without going live.')],
        'restart' => ['label' => __('Restart'), 'desc' => __('After dply restarts services — your workers/daemons.')],
    ];
    $shellKind = \App\Models\SiteDeployHook::KIND_SHELL;
    $btnOutline = 'dply-btn dply-btn-xs dply-btn-outline';
    $strip = $isEmbedded ? 'border-b border-brand-ink/10' : 'mt-5 rounded-2xl border border-brand-ink/10 bg-white/80 shadow-sm';
    $stripPad = $isEmbedded ? 'px-5 py-2.5 sm:px-6' : 'p-4 sm:p-5';
    $phaseRows = $isEmbedded ? 2 : 5;
@endphp
<div>
@if (! $isEmbedded)
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
@else
    <div class="min-w-0">
@endif

        @unless ($isEmbedded)
            <section class="dply-card min-w-0 overflow-hidden p-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-command-line"
                    :title="__('Deploy script')"
                    :note="__('Plain shell commands per phase. Start from a preset, then tweak — or Insert command.')"
                />
            </section>
        @endunless

        {{-- Presets + zero-downtime in one compact strip when embedded --}}
        <div @class([
            'border-b border-brand-ink/10 px-5 py-2 sm:px-6' => $isEmbedded,
            'mt-5 flex flex-wrap items-start justify-between gap-3' => ! $isEmbedded,
        ])>
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Preset') }}</span>
                @foreach ($this->presets() as $key => $preset)
                    <button type="button"
                        x-on:click="$dispatch('confirm-preset', { key: '{{ $key }}', label: @js($preset['label']) }); $dispatch('open-modal', 'deploy-preset-confirm')"
                        class="rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[11px] font-semibold text-brand-ink hover:border-brand-forest/40 hover:bg-brand-sand/40">
                        {{ $preset['label'] }}
                    </button>
                @endforeach
            </div>
        </div>

        @foreach ($phases as $phase => $meta)
            @php $locked = $lockedSteps[$phase] ?? []; @endphp
            <div class="{{ $strip }}">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 {{ $isEmbedded ? 'bg-brand-sand/15 px-5 py-2 sm:px-6' : 'px-4 py-3 sm:px-5' }}">
                    <div class="min-w-0">
                        <h3 class="text-xs font-semibold text-brand-ink">{{ $meta['label'] }}</h3>
                        <p class="mt-0.5 text-[11px] text-brand-moss">{{ $meta['desc'] }}</p>
                    </div>
                    <button type="button"
                        x-on:click="$dispatch('open-command-catalog', { phase: '{{ $phase }}' }); $dispatch('open-modal', 'deploy-script-catalog')"
                        class="{{ $btnOutline }} shrink-0">
                        <x-heroicon-o-plus class="h-3.5 w-3.5" /> {{ __('Insert') }}
                    </button>
                </div>

                <div class="{{ $stripPad }}">
                    @if (! empty($locked))
                        @php $customType = \App\Models\SiteDeployStep::TYPE_CUSTOM; @endphp
                        <div class="mb-2 space-y-1">
                            @foreach ($locked as $step)
                                @php $pinnedCustom = $step->step_type === $customType; @endphp
                                <div class="rounded-md border border-brand-ink/10 bg-brand-sand/25 px-2 py-1" wire:key="locked-{{ $step->id }}">
                                    @if ($editing_step_id === (string) $step->id)
                                        <div class="flex items-center gap-1.5">
                                            <span class="shrink-0 text-[11px] font-semibold text-brand-ink">{{ $step->pillLabel() }}</span>
                                            <input type="text" wire:model="editing_step_command" spellcheck="false"
                                                wire:keydown.enter.prevent="saveStep" wire:keydown.escape="cancelStepEdit"
                                                class="min-w-0 flex-1 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 font-mono text-[11px] text-brand-ink focus:border-brand-forest focus:ring-brand-forest" autofocus>
                                            <button type="button" wire:click="saveStep"
                                                class="shrink-0 rounded-md bg-brand-forest px-2 py-0.5 text-[10px] font-semibold text-white hover:bg-brand-forest/90">{{ __('Save') }}</button>
                                            <button type="button" wire:click="cancelStepEdit"
                                                class="shrink-0 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[10px] font-semibold text-brand-moss hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                                        </div>
                                        @error('editing_step_command')
                                            <p class="mt-1 text-[10px] font-medium text-rose-600">{{ $message }}</p>
                                        @enderror
                                    @else
                                        <div class="flex items-center gap-1.5">
                                            <x-heroicon-m-lock-closed class="h-3 w-3 shrink-0 text-brand-mist" />
                                            <span class="shrink-0 text-[11px] font-semibold text-brand-ink">{{ $step->pillLabel() }}</span>
                                            <span class="min-w-0 flex-1 truncate font-mono text-[10px] text-brand-mist">{{ $step->commandFor() }}</span>
                                            <span @class([
                                                'shrink-0 rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide',
                                                'bg-brand-gold/20 text-brand-ink' => $pinnedCustom,
                                                'bg-brand-ink/5 text-brand-moss' => ! $pinnedCustom,
                                            ])>{{ $pinnedCustom ? __('Pinned') : __('Builder') }}</span>
                                            <button type="button" wire:click="editStep('{{ $step->id }}')" title="{{ __('Edit command') }}"
                                                class="shrink-0 rounded-md p-0.5 text-brand-moss hover:bg-brand-sand/60 hover:text-brand-ink">
                                                <x-heroicon-m-pencil-square class="h-3.5 w-3.5" />
                                            </button>
                                            <button type="button" title="{{ __('Remove step') }}"
                                                x-on:click="$dispatch('confirm-remove-step', { id: '{{ $step->id }}', label: @js($step->pillLabel()) }); $dispatch('open-modal', 'deploy-step-remove-confirm')"
                                                class="shrink-0 rounded-md p-0.5 text-brand-moss hover:bg-rose-50 hover:text-rose-600">
                                                <x-heroicon-m-trash class="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                            <p class="text-[10px] text-brand-mist">{{ __('Your commands below run after these.') }}</p>
                        </div>
                    @endif

                    @if ($phase === 'restart')
                        @if ($managedRestart['has'])
                            <div class="mb-2 rounded-md border border-brand-ink/10 bg-brand-sand/15 px-3 py-2" x-data="{ managed: @js($managed_restart_enabled), fpmStrategy: @js($php_fpm_strategy) }">
                                <label class="flex cursor-pointer items-start gap-2">
                                    <input type="checkbox" id="deploy-managed-restart-toggle" wire:model="managed_restart_enabled" x-on:change="managed = $event.target.checked"
                                        class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                    <span class="min-w-0">
                                        <span class="text-[11px] font-semibold text-brand-ink">{{ __('Let dply restart automatically') }}</span>
                                        <span class="mt-0.5 block text-[11px] text-brand-moss">{{ $managedRestart['label'] }}</span>
                                        <ul class="mt-1 space-y-0.5" :class="{ 'opacity-40': ! managed }">
                                            @foreach ($managedRestart['items'] as $item)
                                                <li class="flex items-start gap-1 text-[11px] text-brand-ink">
                                                    <x-heroicon-m-arrow-path class="mt-0.5 h-3 w-3 shrink-0 text-brand-forest" />
                                                    <span>{{ $item }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                        <span x-show="! managed" x-cloak class="mt-1.5 flex items-start gap-1 text-[11px] font-medium text-amber-700">
                                            <x-heroicon-m-exclamation-triangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                            <span>{{ __('Off — add your own commands below or the site may keep serving old code.') }}</span>
                                        </span>
                                    </span>
                                </label>

                                @if ($managedRestart['fpm_strategy_selectable'] ?? false)
                                    <div class="mt-2 border-t border-brand-ink/10 pt-2 pl-[26px]" x-show="managed" x-cloak>
                                        <label for="deploy-php-fpm-strategy" class="text-[11px] font-semibold text-brand-ink">{{ __('PHP-FPM & OPcache') }}</label>
                                        <select id="deploy-php-fpm-strategy" wire:model="php_fpm_strategy" x-on:change="fpmStrategy = $event.target.value"
                                            class="mt-1 block w-full max-w-md rounded-md border-brand-ink/15 bg-white py-1 text-[11px] text-brand-ink focus:border-brand-forest focus:ring-brand-forest">
                                            @foreach ($phpFpmStrategyOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <p x-show="fpmStrategy === 'flush'" x-cloak class="mt-1 text-[11px] text-brand-moss">
                                            {{ __('Zero-downtime: FPM reload + OPcache flush; falls back to FPM restart if flush fails.') }}
                                        </p>
                                        <p x-show="fpmStrategy === 'flush_only'" x-cloak class="mt-1 flex items-start gap-1 text-[11px] font-medium text-amber-700">
                                            <x-heroicon-m-exclamation-triangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                            <span>{{ __('Never restarts FPM. If flush fails, previous release may stay live until a manual restart.') }}</span>
                                        </p>
                                        <p x-show="fpmStrategy === 'restart'" x-cloak class="mt-1 flex items-start gap-1 text-[11px] font-medium text-amber-700">
                                            <x-heroicon-m-exclamation-triangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                            <span>{{ __('Restarting PHP-FPM briefly interrupts every site on this PHP version.') }}</span>
                                        </p>
                                    </div>
                                @endif
                            </div>
                        @else
                            <p class="mb-2 rounded-md border border-brand-ink/10 bg-brand-sand/15 px-3 py-1.5 text-[11px] text-brand-moss">{{ $managedRestart['label'] }}</p>
                        @endif
                    @endif

                    <textarea wire:model="{{ $phase }}" id="deploy-phase-{{ $phase }}" rows="{{ $phaseRows }}" spellcheck="false"
                        placeholder="{{ __('# one command per line') }}"
                        class="w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs leading-5 text-brand-ink focus:border-brand-forest focus:ring-brand-forest"></textarea>
                </div>
            </div>
        @endforeach

        <div class="{{ $strip }}" x-data="{ zd: @js($atomic_release) }">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 {{ $isEmbedded ? 'bg-brand-sand/15 px-5 py-2 sm:px-6' : 'px-4 py-3 sm:px-5' }}">
                <div class="min-w-0">
                    <h3 class="text-xs font-semibold text-brand-ink">{{ __('Deploy hooks') }}</h3>
                    <p class="mt-0.5 text-[11px] text-brand-moss">{{ __('Shell at before/after clone and activate.') }}</p>
                </div>
                <button type="button" wire:click="openAddHook" class="{{ $btnOutline }} shrink-0">
                    <x-heroicon-o-plus class="h-3.5 w-3.5" /> {{ __('Add hook') }}
                </button>
            </div>

            <div class="{{ $stripPad }}">
                @if ($hooks->isEmpty())
                    <p class="rounded-md border border-dashed border-brand-ink/15 bg-brand-sand/15 px-3 py-3 text-center text-[11px] text-brand-moss">
                        {{ __('No deploy hooks yet.') }}
                    </p>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($hooks as $hook)
                            @php
                                $editable = $hook->hook_kind === $shellKind && in_array($hook->anchor, $hookAnchorOptions, true);
                                $hookScriptLc = strtolower((string) $hook->script);
                                $isMaintenanceHook = str_contains($hookScriptLc, 'artisan down') || str_contains($hookScriptLc, 'artisan up');
                            @endphp
                            <li class="flex items-center gap-2 py-2" wire:key="hook-{{ $hook->id }}"
                                @if ($isMaintenanceHook) :class="zd ? 'opacity-50' : ''" @endif>
                                <span class="shrink-0 rounded-full bg-brand-sage/15 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-brand-forest">
                                    {{ $hookAnchorLabels[$hook->anchor] ?? $hook->anchor }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[11px] font-semibold text-brand-ink">
                                        {{ $hook->pillLabel() }}
                                        @if ($isMaintenanceHook)
                                            <span x-cloak x-show="zd"
                                                class="ml-1 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 align-middle text-[9px] font-bold uppercase tracking-wide text-amber-800"
                                                title="{{ __('Skipped at deploy time while zero-downtime is on.') }}">{{ __('Redundant') }}</span>
                                        @endif
                                    </p>
                                    @if ($hook->hook_kind === $shellKind && trim((string) $hook->script) !== '')
                                        <p class="truncate font-mono text-[10px] text-brand-mist">{{ \Illuminate\Support\Str::limit(trim((string) $hook->script), 80) }}</p>
                                    @endif
                                </div>
                                @if ($editable)
                                    <button type="button" wire:click="openEditHook('{{ $hook->id }}')" class="shrink-0 text-[11px] font-semibold text-brand-forest hover:underline">{{ __('Edit') }}</button>
                                @else
                                    <span class="shrink-0 rounded-full bg-brand-ink/5 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-brand-moss" title="{{ __('Edit in the visual builder') }}">{{ __('Builder') }}</span>
                                @endif
                                <button type="button"
                                    x-on:click="$dispatch('confirm-remove-hook', { id: '{{ $hook->id }}', label: @js($hook->pillLabel()) }); $dispatch('open-modal', 'deploy-hook-remove-confirm')"
                                    class="shrink-0 text-[11px] font-semibold text-red-700 hover:underline">{{ __('Remove') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    {{-- Self-contained unsaved bar. Hidden until a genuine edit: document-level
         input/change delegation (survives Livewire morphs) flips it dirty only
         for the watched fields, and the preset/clear event flips it too. Nothing
         fires on load, so the bar stays hidden until the user actually changes
         something. Hooks save immediately via their own modal. --}}
    <div
        x-data="{
            dirty: false,
            ids: ['deploy-phase-build', 'deploy-phase-release', 'deploy-phase-restart', 'deploy-atomic-toggle', 'deploy-managed-restart-toggle'],
            init() {
                let mark = (e) => { if (e.target && this.ids.includes(e.target.id)) this.dirty = true; };
                document.addEventListener('input', mark, true);
                document.addEventListener('change', mark, true);
            },
        }"
        x-on:deploy-script-blocks-changed.window="dirty = true"
        x-show="dirty"
        x-cloak
        style="display: none;"
        role="region"
        aria-label="{{ __('Unsaved changes') }}"
        class="pointer-events-auto fixed bottom-24 left-1/2 z-[110] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 rounded-2xl border border-brand-mist/80 bg-white shadow-lg shadow-brand-forest/10 sm:bottom-28"
    >
        <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-5 sm:py-3.5">
            <p class="text-sm text-brand-moss">{{ __('You have unsaved deploy-script changes.') }}</p>
            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 sm:gap-2.5">
                <button type="button" wire:click="discard" x-on:click="dirty = false"
                    class="inline-flex items-center justify-center rounded-xl border border-brand-ink/20 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 focus:outline-none focus:ring-2 focus:ring-brand-sage/40">
                    <span wire:loading.remove wire:target="discard">{{ __('Discard') }}</span>
                    <span wire:loading wire:target="discard" class="opacity-80">{{ __('Resetting…') }}</span>
                </button>
                <button type="button" wire:click="save" x-on:click="dirty = false"
                    class="inline-flex items-center justify-center rounded-xl bg-brand-sage px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-sage/90 focus:outline-none focus:ring-2 focus:ring-brand-sage/50">
                    <span wire:loading.remove wire:target="save">{{ __('Save deploy script') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Remove-step confirmation — deletes a locked builder/pinned step from the pipeline. --}}
    <x-modal name="deploy-step-remove-confirm" maxWidth="md" overlayClass="bg-brand-ink/40" focusable>
        <div x-data="{ stepId: null, stepLabel: '' }"
            x-on:confirm-remove-step.window="stepId = $event.detail.id; stepLabel = $event.detail.label">
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <div class="flex items-start gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Remove step') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                            {{ __('Remove') }} <span x-text="stepLabel"></span>{{ __('?') }}
                        </h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('This deletes the step from the deploy pipeline immediately. You can re-add it later from “Insert command”.') }}</p>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <button type="button" x-on:click="$dispatch('close-modal', 'deploy-step-remove-confirm')"
                    class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                <button type="button" x-on:click="$wire.removeStep(stepId); $dispatch('close-modal', 'deploy-step-remove-confirm')"
                    class="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                    <x-heroicon-o-trash class="h-4 w-4" /> {{ __('Remove step') }}
                </button>
            </div>
        </div>
    </x-modal>

    {{-- Remove-hook confirmation — deletes a deploy hook immediately. --}}
    <x-modal name="deploy-hook-remove-confirm" maxWidth="md" overlayClass="bg-brand-ink/40" focusable>
        <div x-data="{ hookId: null, hookLabel: '' }"
            x-on:confirm-remove-hook.window="hookId = $event.detail.id; hookLabel = $event.detail.label">
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <div class="flex items-start gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Remove hook') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                            {{ __('Remove') }} <span x-text="hookLabel"></span>{{ __('?') }}
                        </h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('This deletes the deploy hook immediately. You can add it again later with “Add hook”.') }}</p>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <button type="button" x-on:click="$dispatch('close-modal', 'deploy-hook-remove-confirm')"
                    class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                <button type="button" x-on:click="$wire.deleteHook(hookId); $dispatch('close-modal', 'deploy-hook-remove-confirm')"
                    class="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                    <x-heroicon-o-trash class="h-4 w-4" /> {{ __('Remove hook') }}
                </button>
            </div>
        </div>
    </x-modal>

    {{-- Preset confirmation — replaces the loaded scripts in the editor (not persisted until Save). --}}
    <x-modal name="deploy-preset-confirm" maxWidth="md" overlayClass="bg-brand-ink/40" focusable>
        <div x-data="{ presetKey: null, presetLabel: '' }"
            x-on:confirm-preset.window="presetKey = $event.detail.key; presetLabel = $event.detail.label">
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <div class="flex items-start gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Preset') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                            {{ __('Load the') }} <span x-text="presetLabel"></span> {{ __('preset?') }}
                        </h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('This replaces the current scripts in the editor. Nothing is saved until you click “Save deploy script”.') }}</p>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <button type="button" x-on:click="$dispatch('close-modal', 'deploy-preset-confirm')"
                    class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                <button type="button" x-on:click="$wire.applyPreset(presetKey); $dispatch('close-modal', 'deploy-preset-confirm')"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                    <x-heroicon-o-arrow-down-tray class="h-4 w-4" /> {{ __('Load preset') }}
                </button>
            </div>
        </div>
    </x-modal>

    {{-- Searchable, runtime-aware command catalog. Inserts the command into the
         phase's textarea client-side (dispatching `input` so wire:model + the
         unsaved bar both pick it up). --}}
    <x-modal name="deploy-script-catalog" maxWidth="lg" overlayClass="bg-brand-ink/40" focusable>
        <div
            x-data="{
                catalog: @js($commandCatalog),
                phase: 'build',
                search: '',
                phaseLabel: '',
                items() {
                    let list = this.catalog[this.phase] || [];
                    let q = this.search.trim().toLowerCase();
                    if (! q) return list;
                    return list.filter(i => (i.label + ' ' + i.command).toLowerCase().includes(q));
                },
                pick(cmd) {
                    let ta = document.getElementById('deploy-phase-' + this.phase);
                    if (ta) {
                        let cur = ta.value.trim();
                        ta.value = cur === '' ? cmd : cur + '\n' + cmd;
                        ta.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    $dispatch('close-modal', 'deploy-script-catalog');
                },
            }"
            x-on:open-command-catalog.window="
                phase = $event.detail.phase;
                search = '';
                phaseLabel = { build: @js(__('Build')), release: @js(__('Release')), restart: @js(__('Restart')) }[phase] || phase;
            "
        >
            <div class="flex items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-4">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Insert command') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                        <span x-text="phaseLabel"></span> {{ __('commands') }}
                    </h2>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'deploy-script-catalog')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>
            <div class="border-b border-brand-ink/10 px-6 py-3">
                <div class="relative">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist" aria-hidden="true" />
                    <input type="search" x-model="search" placeholder="{{ __('Filter commands…') }}"
                        class="w-full rounded-lg border border-brand-ink/15 bg-white py-2 pl-9 pr-3 text-sm shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink" />
                </div>
            </div>
            <div class="max-h-[55vh] min-h-0 overflow-y-auto px-6 py-3">
                <template x-if="items().length === 0">
                    <p class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-4 py-8 text-center text-sm text-brand-moss">{{ __('No commands match.') }}</p>
                </template>
                <ul class="divide-y divide-brand-ink/10">
                    <template x-for="(item, i) in items()" :key="phase + '-' + i">
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-brand-ink" x-text="item.label"></p>
                                <p class="truncate font-mono text-[10px] text-brand-mist" x-text="item.command"></p>
                            </div>
                            <button type="button" x-on:click="pick(item.command)"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                <x-heroicon-o-plus class="h-4 w-4" /> {{ __('Insert') }}
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </x-modal>

    {{-- Add / edit shell deploy hook. --}}
    <x-modal name="deploy-script-hook" maxWidth="lg" overlayClass="bg-brand-ink/40" focusable>
        <form wire:submit="saveHook">
            <div class="border-b border-brand-ink/10 px-6 py-4">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy hook') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $editing_hook_id ? __('Edit hook') : __('Add hook') }}</h2>
            </div>
            <div class="space-y-4 px-6 py-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="hook_anchor" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('When') }}</label>
                        <select id="hook_anchor" wire:model="hook_anchor" class="w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm focus:border-brand-forest focus:ring-brand-forest">
                            @foreach ($hookAnchorOptions as $anchor)
                                <option value="{{ $anchor }}">{{ $hookAnchorLabels[$anchor] ?? $anchor }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('hook_anchor')" class="mt-1" />
                    </div>
                    <div>
                        <label for="hook_timeout" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Timeout (seconds)') }}</label>
                        <input type="number" id="hook_timeout" wire:model="hook_timeout" min="30" max="3600" class="w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm focus:border-brand-forest focus:ring-brand-forest" />
                        <x-input-error :messages="$errors->get('hook_timeout')" class="mt-1" />
                    </div>
                </div>
                <div>
                    <label for="hook_label" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Label (optional)') }}</label>
                    <input type="text" id="hook_label" wire:model="hook_label" maxlength="120" placeholder="{{ __('Notify Slack') }}" class="w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm focus:border-brand-forest focus:ring-brand-forest" />
                    <x-input-error :messages="$errors->get('hook_label')" class="mt-1" />
                </div>
                <div>
                    <label for="hook_script" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Shell script') }}</label>
                    <textarea id="hook_script" wire:model="hook_script" rows="6" spellcheck="false" placeholder="{{ __('# runs on the server') }}"
                        class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs leading-5 text-brand-ink focus:border-brand-forest focus:ring-brand-forest"></textarea>
                    <x-input-error :messages="$errors->get('hook_script')" class="mt-1" />
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <button type="button" wire:click="closeHookForm" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                    <x-heroicon-o-check class="h-4 w-4" /> {{ $editing_hook_id ? __('Save hook') : __('Add hook') }}
                </button>
            </div>
        </form>
    </x-modal>
</div>
