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
        default => __('Remove server now'),
    };
@endphp
@if ($open)
    @teleport('body')
    <div
        class="fixed inset-0 isolate z-[100] overflow-y-auto overscroll-y-contain"
        role="dialog"
        aria-modal="true"
        aria-labelledby="remove-server-modal-title"
    >
        <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeRemoveServerModal" wire:key="remove-server-backdrop"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
            <div
                class="my-auto w-full max-w-xl dply-modal-panel"
                @click.stop
                wire:key="remove-server-dialog"
            >
                <form wire:submit="submitRemoveServer" class="flex flex-col">
                    <div class="border-b border-zinc-100 px-6 py-6 sm:px-8 sm:py-7">
                        <h2 id="remove-server-modal-title" class="text-lg font-semibold text-brand-ink">{{ __('Remove server') }}</h2>
                        <p class="mt-3 text-sm leading-relaxed text-brand-moss sm:mt-4">
                            {{ __('This removes :name from Dply.', ['name' => $serverName]) }}
                            @if (is_array($summary) && $summary['will_destroy_cloud'])
                                {{ __('Linked cloud resources are also targeted for teardown when available.') }}
                            @endif
                            {{ __('This cannot be undone.') }}
                        </p>
                    </div>
                    <div class="space-y-6 px-6 py-7 sm:px-8 sm:py-8">

                    {{-- When-to-remove selector. Three options that all flow
                         through the same submit handler — only the timestamp
                         differs. "In 30 min" stamps scheduled_deletion_at to
                         now+30 and the every-minute scheduler picks it up. --}}
                    <fieldset class="space-y-2">
                        <legend class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">{{ __('When to remove') }}</legend>
                        <div class="grid gap-2 sm:grid-cols-3">
                            @php
                                $modes = [
                                    'now' => ['label' => __('Now'), 'help' => __('Immediate')],
                                    'in_30' => ['label' => __('In 30 minutes'), 'help' => __('30-min grace · cancel anytime')],
                                    'scheduled' => ['label' => __('On a date'), 'help' => __('Pick a date below')],
                                ];
                            @endphp
                            @foreach ($modes as $modeValue => $modeMeta)
                                <label class="cursor-pointer">
                                    <input type="radio" wire:model.live="removeMode" value="{{ $modeValue }}" class="peer sr-only" />
                                    <div class="rounded-xl border-2 border-zinc-200 bg-white px-3 py-2.5 text-sm transition peer-checked:border-red-500 peer-checked:bg-red-50 peer-focus-visible:ring-2 peer-focus-visible:ring-red-500/40 hover:border-zinc-300">
                                        <p class="font-semibold text-brand-ink">{{ $modeMeta['label'] }}</p>
                                        <p class="mt-0.5 text-[11px] text-brand-moss">{{ $modeMeta['help'] }}</p>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    @if ($removeMode === 'scheduled')
                        <div class="space-y-2">
                            <label for="scheduled-removal-date" class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">{{ __('Removal date') }}</label>
                            <input
                                id="scheduled-removal-date"
                                type="date"
                                wire:model.live="scheduledRemovalDate"
                                min="{{ now()->addDay()->toDateString() }}"
                                class="block w-full rounded-xl border-zinc-200 bg-white shadow-sm focus:border-red-500 focus:ring-red-500"
                            />
                            <div class="flex flex-wrap gap-2 text-xs">
                                <button type="button" wire:click="applyRemovalDatePreset('tomorrow')" class="rounded-full border border-zinc-200 bg-white px-2.5 py-1 font-medium text-brand-ink hover:border-red-200 hover:bg-red-50/40">{{ __('Tomorrow') }}</button>
                                <button type="button" wire:click="applyRemovalDatePreset('week')" class="rounded-full border border-zinc-200 bg-white px-2.5 py-1 font-medium text-brand-ink hover:border-red-200 hover:bg-red-50/40">{{ __('In a week') }}</button>
                                <button type="button" wire:click="applyRemovalDatePreset('month')" class="rounded-full border border-zinc-200 bg-white px-2.5 py-1 font-medium text-brand-ink hover:border-red-200 hover:bg-red-50/40">{{ __('In a month') }}</button>
                            </div>
                            <p class="text-[11px] text-brand-mist">{{ __('Removal runs at the end of that day in your app timezone.') }}</p>
                            @error('scheduledRemovalDate')
                                <p class="text-xs text-red-700">{{ $message }}</p>
                            @enderror
                        </div>
                    @elseif ($removeMode === 'in_30')
                        <div class="rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-3 text-sm text-amber-900">
                            {{ __('The server will be removed in 30 minutes. You can cancel from the workspace page anytime before then.') }}
                        </div>
                    @endif

                    @if (is_array($summary))
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-5 text-sm text-brand-ink sm:p-6">
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Impact summary') }}</p>
                            <ul class="mt-3 list-inside list-disc space-y-1.5 text-brand-moss sm:mt-4">
                                <li>{{ __('Sites: :n', ['n' => $summary['sites']]) }}</li>
                                <li>{{ __('Databases: :n', ['n' => $summary['databases']]) }}</li>
                                <li>{{ __('Cron jobs: :n', ['n' => $summary['cron_jobs']]) }}</li>
                                <li>{{ __('Daemons: :n', ['n' => $summary['supervisor_programs']]) }}</li>
                                <li>{{ __('Firewall rules: :n', ['n' => $summary['firewall_rules']]) }}</li>
                                <li>{{ __('SSH keys (stored): :n', ['n' => $summary['authorized_keys']]) }}</li>
                                <li>{{ __('Recipes: :n', ['n' => $summary['recipes']]) }}</li>
                                <li>{{ __('Running deployments: :n', ['n' => $summary['running_deployments']]) }}</li>
                                <li>{{ __('Provider: :p', ['p' => $summary['provider_label']]) }}</li>
                                @if ($summary['will_destroy_cloud'])
                                    <li class="font-medium text-amber-800">{{ __('Matching cloud resources are targeted for teardown when applicable.') }}</li>
                                @endif
                            </ul>
                        </div>
                    @endif

                    @if ($running && $removeMode === 'now')
                        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm leading-relaxed text-red-900 sm:p-5">
                            {{ __('This server has running deployments. Finish or cancel them, or pick a scheduled removal mode above.') }}
                        </div>
                    @endif

                    {{-- Type-to-confirm. Required for every mode — operators
                         have wiped servers by muscle-memory before. --}}
                    <div class="space-y-2">
                        <label for="delete-confirm-name" class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">
                            {{ __('Type the server name to confirm') }}
                        </label>
                        <input
                            id="delete-confirm-name"
                            type="text"
                            wire:model.live="deleteConfirmName"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="{{ $serverName }}"
                            class="block w-full rounded-xl border-zinc-200 bg-white font-mono text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        />
                        <p class="font-mono text-[11px] text-brand-mist">{{ $serverName }}</p>
                        @error('deleteConfirmName')
                            <p class="text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                    </div>
                    <div class="flex flex-col-reverse gap-3 border-t border-zinc-100 bg-zinc-50/80 px-6 py-5 sm:flex-row sm:justify-end sm:gap-3 sm:px-8 sm:py-6">
                        <button type="button" wire:click="closeRemoveServerModal" class="inline-flex justify-center rounded-xl border border-zinc-200 bg-white px-5 py-3 text-sm font-semibold text-brand-ink hover:bg-zinc-50 sm:px-6">
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            class="inline-flex justify-center rounded-xl bg-red-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-red-300 sm:px-6"
                            @disabled($submitDisabled)
                        >
                            {{ $submitLabel }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endteleport
@endif
