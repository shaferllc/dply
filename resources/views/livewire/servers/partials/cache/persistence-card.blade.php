@php
    /** @var \App\Models\ServerCacheService $row */
    /** @var string $card */
    /** @var array<string, string> $engineLabels */
    $engineLabel = $engineLabels[$row->engine] ?? ucfirst($row->engine);
    $state = $persistenceState ?? null;
    $error = $persistenceError ?? null;
    $aofEnabled = $state['aof_enabled'] ?? null;

    // These arrive as ISO-8601 strings: the component stores them as strings
    // because $persistenceState is a public Livewire property, and a Carbon
    // nested in it hydrates back as __PHP_Incomplete_Class on the next request.
    // Accept a real date object too so a direct (non-Livewire) caller still works.
    $toDate = static function ($value): ?\Carbon\CarbonImmutable {
        if ($value instanceof \DateTimeInterface) {
            return \Carbon\CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Carbon\CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    };

    $rdbLastSave = $toDate($state['rdb_last_save_at'] ?? null);
    $bgsaveInProgress = (bool) ($state['rdb_bgsave_in_progress'] ?? false);
    $aofRewriteAt = $toDate($state['aof_last_rewrite_at'] ?? null);
    $aofBytes = $state['aof_size_bytes'] ?? null;
    $schedule = $state['save_schedule'] ?? [];
@endphp

<x-workspace-panel-head
    dense
    icon="heroicon-o-archive-box"
    :title="__(':engine — persistence', ['engine' => $engineLabel])"
    :note="__('RDB save schedule (point-in-time snapshots) and AOF append-only log. RDB is fast restore, low durability; AOF is slow restore, high durability. Most production setups run RDB only.')"
    class="border-b border-brand-ink/10"
/>

<div class="{{ $card ?? 'border-b border-brand-ink/10' }} px-4 py-3.5 sm:px-5" wire:init="loadPersistenceState">

    @if ($error)
        <p class="rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">{{ $error }}</p>
    @elseif ($state === null)
        {{-- First-load state: blue SSH-in-flight banner + 4-tile skeleton that
             mirrors the real status grid so the swap to live values lands
             without a layout jump. wire:init="loadPersistenceState" fires on
             mount; this block stays visible until the response writes $state. --}}
        <div class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50/70 px-4 py-3 text-xs text-sky-900">
            <svg class="mt-0.5 h-4 w-4 shrink-0 animate-spin text-sky-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10" opacity="0.25" />
                <path d="M22 12a10 10 0 0 1-10 10" stroke-linecap="round" />
            </svg>
            <div class="min-w-0 flex-1">
                <p class="font-semibold">{{ __('Reading persistence state over SSH…') }}</p>
                <p class="mt-0.5 text-sky-800/90">{{ __('Pulls RDB save rules + AOF status from the engine. Typically 1–2 seconds.') }}</p>
            </div>
        </div>
        <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach (['Last RDB save', 'RDB rules', 'AOF', 'AOF rewrite'] as $label)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-2">
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __($label) }}</p>
                    <div class="mt-2 h-5 w-20 animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            @endforeach
        </dl>
    @else
        {{-- Status grid: 4 tiles summarising current state at-a-glance. --}}
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Last RDB save') }}</dt>
                <dd class="mt-1 text-sm font-semibold text-brand-ink">
                    @if ($rdbLastSave)
                        <span title="{{ $rdbLastSave->toDateTimeString() }}">{{ $rdbLastSave->diffForHumans() }}</span>
                    @else
                        —
                    @endif
                </dd>
                @if ($bgsaveInProgress)
                    <p class="mt-1 text-xs font-semibold text-sky-700">{{ __('BGSAVE in progress…') }}</p>
                @endif
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('RDB rules') }}</dt>
                <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ count($schedule) }} {{ trans_choice('{1}rule|[2,*]rules', count($schedule)) }}</dd>
                @if ($schedule === [])
                    <p class="mt-1 text-xs text-amber-700">{{ __('RDB snapshots disabled') }}</p>
                @endif
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('AOF') }}</dt>
                <dd class="mt-1 text-sm font-semibold {{ $aofEnabled ? 'text-emerald-700' : 'text-brand-ink' }}">
                    {{ $aofEnabled === null ? '—' : ($aofEnabled ? __('Enabled') : __('Disabled')) }}
                </dd>
                @if ($aofEnabled && $aofBytes !== null && $aofBytes > 0)
                    <p class="mt-1 text-xs text-brand-moss">{{ \Illuminate\Support\Number::fileSize($aofBytes) }}</p>
                @endif
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Last AOF rewrite') }}</dt>
                <dd class="mt-1 text-sm font-semibold text-brand-ink">
                    @if ($aofRewriteAt)
                        <span title="{{ $aofRewriteAt->toDateTimeString() }}">{{ $aofRewriteAt->diffForHumans() }}</span>
                    @else
                        —
                    @endif
                </dd>
            </div>
        </dl>

        {{-- One-shot triggers + AOF toggle. --}}
        <div class="mt-3 flex flex-wrap gap-2">
            <button
                type="button"
                wire:click="triggerBgsave"
                wire:loading.attr="disabled"
                wire:target="triggerBgsave"
                class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
                title="{{ __('Trigger an async RDB snapshot. Output goes to the engine\'s rdb file path.') }}"
            >
                <span wire:loading.remove wire:target="triggerBgsave">{{ __('BGSAVE') }}</span>
                <span wire:loading wire:target="triggerBgsave">{{ __('Queueing…') }}</span>
            </button>
            <button
                type="button"
                wire:click="triggerBgrewriteaof"
                wire:loading.attr="disabled"
                wire:target="triggerBgrewriteaof"
                @disabled(! $aofEnabled)
                class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
                title="{{ __('Rewrite the AOF log to compact it. Only available when AOF is enabled.') }}"
            >
                <span wire:loading.remove wire:target="triggerBgrewriteaof">{{ __('BGREWRITEAOF') }}</span>
                <span wire:loading wire:target="triggerBgrewriteaof">{{ __('Queueing…') }}</span>
            </button>
            <button
                type="button"
                wire:click="openConfirmActionModal('toggleAofPersistence', [], @js($aofEnabled ? __('Disable AOF?') : __('Enable AOF?')), @js($aofEnabled ? __('Stops appending writes to the AOF log. The existing .aof file stays on disk; engine continues to serve from memory + RDB.') : __('Starts appending every write to the AOF log. Higher durability; larger on-disk footprint. Engine will load AOF first on restart.')), @js(__('Apply')), false)"
                wire:loading.attr="disabled"
                wire:target="toggleAofPersistence"
                class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
            >
                {{ $aofEnabled ? __('Disable AOF') : __('Enable AOF') }}
            </button>
        </div>

        {{-- Save-schedule editor. --}}
        <form wire:submit="saveRdbSchedule" class="mt-6 max-w-xl">
            <div>
                <x-input-label for="rdb_save_schedule" :value="__('RDB save schedule')" />
                <textarea
                    id="rdb_save_schedule"
                    wire:model="rdb_save_schedule"
                    rows="2"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="3600 1 300 100 60 10000"
                    wire:loading.attr="disabled"
                    wire:target="saveRdbSchedule"
                ></textarea>
                <p class="mt-1 text-xs text-brand-mist">{{ __('Space-separated <seconds> <changes> pairs. "3600 1" = snapshot after 1 change in 3600s. Leave blank to disable RDB snapshots.') }}</p>
                <x-input-error :messages="$errors->get('rdb_save_schedule')" class="mt-1" />
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveRdbSchedule">
                    <span wire:loading.remove wire:target="saveRdbSchedule">{{ __('Save schedule') }}</span>
                    <span wire:loading wire:target="saveRdbSchedule">{{ __('Updating…') }}</span>
                </x-primary-button>
            </div>
        </form>
    @endif
</div>
