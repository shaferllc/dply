{{--
  Livewire form fields read by this modal (must exist on the parent component):
    - $removeMode               (string: 'now' | 'in_30' | 'scheduled')
    - $deleteConfirmName        (string — must equal $serverName to submit)
    - $scheduledRemovalDate     (string Y-m-d, only used when removeMode='scheduled')
    - $deletionReason           (string, optional)
  Methods:
    - closeRemoveServerModal
    - submitRemoveServer
    - applyRemovalDatePreset(string $preset)   — provided by ManagesServerRemovalForm
  Props: $open (bool), $serverName (string), $serverId (string), $deletionSummary (?array)
--}}
@php
    $summary = $deletionSummary ?? null;
    $running = is_array($summary) && ($summary['running_deployments'] ?? 0) > 0;
    $nameMatches = trim($deleteConfirmName ?? '') === $serverName;
    $submitDisabled = ! $nameMatches || ($running && $removeMode === 'now');
    $submitLabel = match ($removeMode) {
        'in_30' => __('Remove in 30 minutes'),
        'scheduled' => __('Schedule removal'),
        default => __('Remove now'),
    };

    // Fold the impact summary into a compact, scannable shape: every
    // attached-resource count, dropping zeros so the list only shows what
    // would actually be lost. When everything is zero, we render a single
    // "Nothing attached" reassurance line instead of a wall of zeros.
    $impactItems = [];
    if (is_array($summary)) {
        $impactItems = array_filter([
            ['label' => __('Sites'), 'count' => (int) ($summary['sites'] ?? 0)],
            ['label' => __('Databases'), 'count' => (int) ($summary['databases'] ?? 0)],
            ['label' => __('Cron jobs'), 'count' => (int) ($summary['cron_jobs'] ?? 0)],
            ['label' => __('Daemons'), 'count' => (int) ($summary['supervisor_programs'] ?? 0)],
            ['label' => __('Firewall rules'), 'count' => (int) ($summary['firewall_rules'] ?? 0)],
            ['label' => __('Stored SSH keys'), 'count' => (int) ($summary['authorized_keys'] ?? 0)],
            ['label' => __('Recipes'), 'count' => (int) ($summary['recipes'] ?? 0)],
            ['label' => __('Running deployments'), 'count' => (int) ($summary['running_deployments'] ?? 0)],
        ], fn (array $r): bool => $r['count'] > 0);
    }
@endphp
@if ($open)
    @teleport('body')
    <div
        class="fixed inset-0 isolate z-[100] overflow-y-auto overscroll-y-contain"
        role="dialog"
        aria-modal="true"
        aria-labelledby="remove-server-modal-title"
        x-data="{ copied: false, copy() { navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($serverName) }}).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1500); }); } }"
    >
        <div class="fixed inset-0 z-0 bg-brand-ink/60 backdrop-blur-sm" wire:click="closeRemoveServerModal" wire:key="remove-server-backdrop"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
            <div
                class="my-auto w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-brand-ink/5"
                @click.stop
                wire:key="remove-server-dialog"
            >
                <form wire:submit="submitRemoveServer" class="flex flex-col">
                    {{-- Header. Destructive icon + concise framing — the body
                         carries the detail so this stays calm and scannable. --}}
                    <div class="flex items-start gap-4 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 ring-1 ring-red-100">
                            <x-heroicon-o-trash class="h-5 w-5 text-red-600" aria-hidden="true" />
                        </span>
                        <div class="flex-1 pt-0.5">
                            <h2 id="remove-server-modal-title" class="text-base font-semibold text-brand-ink">{{ __('Remove server') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                {{ __(':name will be torn down. This action cannot be undone.', ['name' => $serverName]) }}
                                @if (is_array($summary) && $summary['will_destroy_cloud'])
                                    <span class="text-amber-800">{{ __('Linked cloud resources will be destroyed when accessible.') }}</span>
                                @endif
                            </p>
                        </div>
                        <button
                            type="button"
                            wire:click="closeRemoveServerModal"
                            class="ms-2 -me-2 -mt-1 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-brand-mist transition hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-ink/10"
                            aria-label="{{ __('Close') }}"
                        >
                            <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                        </button>
                    </div>

                    <div class="space-y-5 border-t border-brand-sand/60 bg-brand-cream/30 px-6 py-5 sm:px-7">

                    {{-- Timing. Segmented control — much tighter than three
                         tile-cards and reads as one decision rather than
                         three separate ones. --}}
                    <fieldset>
                        <legend class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Timing') }}</legend>
                        @php
                            $modes = [
                                'now' => ['label' => __('Now'), 'icon' => 'heroicon-o-bolt'],
                                'in_30' => ['label' => __('In 30 min'), 'icon' => 'heroicon-o-clock'],
                                'scheduled' => ['label' => __('On a date'), 'icon' => 'heroicon-o-calendar-days'],
                            ];
                        @endphp
                        <div class="mt-2 inline-flex w-full rounded-xl border border-brand-ink/10 bg-white p-1 text-xs shadow-sm">
                            @foreach ($modes as $modeValue => $modeMeta)
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio" wire:model.live="removeMode" value="{{ $modeValue }}" class="peer sr-only" />
                                    <span class="flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 font-semibold text-brand-moss transition peer-checked:bg-red-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-red-500/40 hover:text-brand-ink peer-checked:hover:text-white">
                                        <x-dynamic-component :component="$modeMeta['icon']" class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ $modeMeta['label'] }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-2 text-[11px] text-brand-mist">
                            @switch($removeMode)
                                @case('in_30')
                                    {{ __('Removal runs in 30 minutes. Cancel anytime from the workspace before then.') }}
                                    @break
                                @case('scheduled')
                                    {{ __('Removal runs at the end of the selected day in your app timezone.') }}
                                    @break
                                @default
                                    {{ __('Removal starts immediately.') }}
                            @endswitch
                        </p>
                    </fieldset>

                    @if ($removeMode === 'scheduled')
                        <div class="space-y-2">
                            <label for="scheduled-removal-date" class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Removal date') }}</label>
                            <input
                                id="scheduled-removal-date"
                                type="date"
                                wire:model.live="scheduledRemovalDate"
                                min="{{ now()->addDay()->toDateString() }}"
                                class="block w-full rounded-lg border-brand-ink/15 bg-white text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                            />
                            <div class="flex flex-wrap gap-1.5">
                                <button type="button" wire:click="applyRemovalDatePreset('tomorrow')" class="rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:border-red-200 hover:bg-red-50/50">{{ __('Tomorrow') }}</button>
                                <button type="button" wire:click="applyRemovalDatePreset('week')" class="rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:border-red-200 hover:bg-red-50/50">{{ __('In a week') }}</button>
                                <button type="button" wire:click="applyRemovalDatePreset('month')" class="rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:border-red-200 hover:bg-red-50/50">{{ __('In a month') }}</button>
                            </div>
                            @error('scheduledRemovalDate')
                                <p class="text-xs text-red-700">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    {{-- Impact. Compact: list only non-zero counts, or a
                         single reassurance line when everything is clean. --}}
                    @if (is_array($summary))
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Impact') }}</p>
                            @if ($impactItems === [])
                                <div class="mt-2 inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-1.5 text-xs font-medium text-emerald-800">
                                    <x-heroicon-m-check-circle class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Nothing attached — clean removal.') }}
                                    <span class="text-emerald-700/70">·</span>
                                    <span class="text-emerald-700">{{ $summary['provider_label'] }}</span>
                                </div>
                            @else
                                <ul class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm text-brand-ink sm:grid-cols-3">
                                    @foreach ($impactItems as $item)
                                        <li class="flex items-baseline justify-between gap-2">
                                            <span class="text-brand-moss">{{ $item['label'] }}</span>
                                            <span class="font-mono text-sm font-semibold tabular-nums">{{ $item['count'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="mt-2 text-[11px] text-brand-mist">{{ __('Provider:') }} <span class="font-medium text-brand-moss">{{ $summary['provider_label'] }}</span></p>
                            @endif
                        </div>
                    @endif

                    @if ($running && $removeMode === 'now')
                        <div class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-xs leading-relaxed text-red-900">
                            <x-heroicon-m-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <span>{{ __('Running deployments are in flight. Finish or cancel them first, or pick a scheduled timing above.') }}</span>
                        </div>
                    @endif

                    {{-- Confirm. The name chip is the copy button — tap it,
                         it lands on your clipboard, paste it into the field
                         below. Friction in this step is intentional but the
                         right value being a click away keeps the safeguard
                         from devolving into a wrong-server typo. --}}
                    <div class="space-y-2">
                        <label for="delete-confirm-name" class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">
                            {{ __('Type the server name to confirm') }}
                        </label>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="copy()"
                                :aria-label="copied ? '{{ __('Copied') }}' : '{{ __('Copy server name') }}'"
                                class="group inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1.5 font-mono text-xs text-brand-ink shadow-sm transition hover:border-brand-ink/20 hover:bg-brand-sand/30 focus:outline-none focus:ring-2 focus:ring-red-500/40"
                            >
                                <span>{{ $serverName }}</span>
                                <span class="flex h-4 w-4 items-center justify-center">
                                    <svg x-show="!copied" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 text-brand-mist transition group-hover:text-brand-moss">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375A1.875 1.875 0 0 1 13.875 22.5h-9A1.875 1.875 0 0 1 3 20.625V9.75A1.875 1.875 0 0 1 4.875 7.875H8.25M9.75 6.375h9A1.875 1.875 0 0 1 20.625 8.25v9A1.875 1.875 0 0 1 18.75 19.125h-9A1.875 1.875 0 0 1 7.875 17.25v-9A1.875 1.875 0 0 1 9.75 6.375Z" />
                                    </svg>
                                    <svg x-show="copied" x-cloak xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke="currentColor" class="h-4 w-4 text-emerald-600">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                </span>
                            </button>
                            <span x-show="copied" x-cloak class="text-[11px] font-semibold text-emerald-700" aria-live="polite">{{ __('Copied — paste below') }}</span>
                        </div>
                        <input
                            id="delete-confirm-name"
                            type="text"
                            wire:model.live="deleteConfirmName"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="{{ __('Paste the server name') }}"
                            @class([
                                'block w-full rounded-lg bg-white font-mono text-sm shadow-sm transition focus:border-red-500 focus:ring-red-500',
                                'border-brand-ink/15' => ! $nameMatches,
                                'border-emerald-300 ring-1 ring-emerald-200' => $nameMatches,
                            ])
                        />
                        @error('deleteConfirmName')
                            <p class="text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    </div>

                    {{-- Footer. Loading state on the destructive action: once
                         submitted, both buttons disable and the primary swaps
                         to a spinner + "Confirming…" so the user sees the
                         request is in flight (these requests can take a few
                         seconds when cloud teardown is involved). --}}
                    <div class="flex flex-col-reverse gap-2 border-t border-brand-sand/60 bg-white px-6 py-4 sm:flex-row sm:justify-end sm:gap-2 sm:px-7">
                        <button
                            type="button"
                            wire:click="closeRemoveServerModal"
                            wire:loading.attr="disabled"
                            wire:target="submitRemoveServer"
                            class="inline-flex justify-center rounded-lg border border-brand-ink/10 bg-white px-4 py-2 text-sm font-semibold text-brand-ink transition hover:bg-brand-sand/30 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            class="inline-flex min-w-[10rem] items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500/40 disabled:cursor-not-allowed disabled:bg-red-300"
                            @disabled($submitDisabled)
                            wire:loading.attr="disabled"
                            wire:target="submitRemoveServer"
                        >
                            <span wire:loading.remove wire:target="submitRemoveServer" class="inline-flex items-center gap-2">
                                <x-heroicon-m-trash class="h-4 w-4" aria-hidden="true" />
                                {{ $submitLabel }}
                            </span>
                            <span wire:loading wire:target="submitRemoveServer" class="inline-flex items-center gap-2">
                                <x-spinner size="sm" variant="white" />
                                {{ __('Confirming…') }}
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endteleport
@endif
