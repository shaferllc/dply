@props([
    'engine',
    'engineLabel',
    'row',
    'replInput',
    'replHistory',
    'replUnlocked',
    'card' => 'dply-card overflow-hidden',
])

<div class="{{ $card }} p-6 sm:p-8" wire:key="cache-repl-{{ $engine }}">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h3 class="text-lg font-semibold text-brand-ink">{{ __(':engine — interactive console', ['engine' => $engineLabel]) }}</h3>
            <p class="mt-2 text-sm text-brand-moss">{{ __('Run :engine-cli commands directly against the server. Read-only commands work anytime; mutating commands need the unlock below.', ['engine' => $engine]) }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2 self-start whitespace-nowrap">
            <button
                type="button"
                wire:click="clearReplHistory"
                class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                @disabled(empty($replHistory))
            >
                <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Clear') }}
            </button>
        </div>
    </div>

    <x-explainer class="mt-4">
        <p>{{ __('A direct line into this engine. Each command runs as a single SSH round-trip via the engine\'s native cli (e.g. redis-cli, valkey-cli) on the server itself, then the response is rendered here.') }}</p>
        <p>
            {{ __('Read-only commands run anytime: ') }}
            <code>INFO</code>, <code>PING</code>, <code>GET</code>, <code>KEYS</code>, <code>SCAN</code>, <code>MEMORY USAGE</code>, <code>SLOWLOG GET</code>, <code>CLIENT LIST</code>, <code>CONFIG GET</code>.
        </p>
        <p>
            {{ __('Mutating commands require the unlock toggle: ') }}
            <code>SET</code>, <code>DEL</code>, <code>FLUSHALL</code>, <code>CONFIG SET</code>, <code>EXPIRE</code>.
            {{ __('Every command — read-only, mutating, denied, blocked — is recorded in the audit log with the verb only (never arguments).') }}
        </p>
        <p>
            {{ __('A handful of disruptive commands are blocked outright and do not run even when unlocked: ') }}
            <code>SHUTDOWN</code>, <code>MIGRATE</code>, <code>REPLICAOF</code>, <code>DEBUG SLEEP</code>, <code>BGREWRITEAOF</code>.
            {{ __('Use the engine controls (Restart, Stop, Start) for those.') }}
        </p>
    </x-explainer>

    <div class="mt-4 flex flex-wrap items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-4 py-3">
        <label class="inline-flex cursor-pointer items-center gap-2 text-sm">
            <input
                type="checkbox"
                wire:click="toggleReplUnlock"
                @checked($replUnlocked)
                class="h-4 w-4 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage/30"
            />
            <span class="font-medium text-brand-ink">{{ __('Allow mutating commands') }}</span>
        </label>
        <span class="text-xs text-brand-moss">
            @if ($replUnlocked)
                {{ __('Unlocked — every command is recorded in the audit log.') }}
            @else
                {{ __('Locked — read-only commands only.') }}
            @endif
        </span>
    </div>

    <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100">
        @if (empty($replHistory))
            <p class="px-1 py-2 text-brand-mist/80">{{ __('No commands run yet. Try INFO server or PING.') }}</p>
        @else
            <div class="max-h-96 space-y-2 overflow-auto" x-data x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">
                @foreach ($replHistory as $entry)
                    <div>
                        <p class="text-amber-300/90">
                            <span class="text-emerald-200/80 select-none">&gt;&nbsp;</span>{{ $entry['cmd'] }}
                        </p>
                        @if ($entry['output'] !== '')
                            <pre @class([
                                'mt-1 whitespace-pre-wrap break-words pl-4',
                                'text-rose-200' => $entry['kind'] === 'error',
                                'text-emerald-100' => $entry['kind'] !== 'error',
                            ])>{{ $entry['output'] }}</pre>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <form wire:submit.prevent="runReplCommand" class="mt-3 flex items-stretch gap-2">
        <span class="inline-flex items-center px-2 font-mono text-sm text-brand-mist select-none">&gt;</span>
        <x-text-input
            wire:model="replInput"
            type="text"
            autocomplete="off"
            spellcheck="false"
            class="block w-full font-mono text-sm"
            placeholder="{{ __('e.g. INFO server') }}"
            wire:loading.attr="disabled"
            wire:target="runReplCommand"
        />
        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="runReplCommand">
            <span wire:loading.remove wire:target="runReplCommand">{{ __('Run') }}</span>
            <span wire:loading wire:target="runReplCommand">{{ __('Running…') }}</span>
        </x-primary-button>
    </form>
</div>
