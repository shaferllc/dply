@props([
    'engine',
    'engineLabel',
    'row',
    'replInput',
    'replHistory',
    'replUnlocked',
    'card' => 'dply-card overflow-hidden',
])

@php
    $commandCatalog = \App\Support\Servers\CacheCommandCatalog::respFamily();
    $commandsByGroup = \App\Support\Servers\CacheCommandCatalog::respFamilyByGroup();
    $commandModalName = 'cache-command-reference-'.$engine;
@endphp

<div class="{{ $card }}" wire:key="cache-repl-{{ $engine }}">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Console') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine — interactive console', ['engine' => $engineLabel]) }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Run :engine-cli commands directly against the server. Read-only commands work anytime; mutating commands need the unlock below.', ['engine' => $engine]) }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2 self-start whitespace-nowrap">
            <button
                type="button"
                x-on:click="$dispatch('open-modal', @js($commandModalName))"
                class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
            >
                <x-heroicon-o-book-open class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Command reference') }}
            </button>
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

    <div class="px-6 py-6 sm:px-7">
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

    {{-- Autocomplete state lives in Alpine; the actual input is bound to Livewire. When a
         suggestion is chosen we set `.value` directly and fire an `input` event so wire:model
         syncs without a Livewire round-trip on every keystroke. --}}
    <div
        class="relative mt-3"
        x-data="{
            open: false,
            highlighted: 0,
            commands: @js(array_map(fn ($c) => ['name' => $c['name'], 'syntax' => $c['syntax'], 'mutating' => $c['mutating']], $commandCatalog)),
            filterTerm: '',
            matches() {
                const term = this.filterTerm.trim().toUpperCase();
                if (term === '') return [];
                return this.commands
                    .filter(c => c.name.startsWith(term))
                    .slice(0, 8);
            },
            choose(item) {
                const input = this.$refs.replInput;
                input.value = item.syntax;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();
                this.open = false;
                this.highlighted = 0;
            },
            onInput(e) {
                this.filterTerm = e.target.value;
                const m = this.matches();
                this.open = m.length > 0;
                if (this.highlighted >= m.length) this.highlighted = 0;
            },
            onKey(e) {
                const m = this.matches();
                if (! this.open || m.length === 0) return;
                if (e.key === 'ArrowDown') { e.preventDefault(); this.highlighted = (this.highlighted + 1) % m.length; }
                else if (e.key === 'ArrowUp') { e.preventDefault(); this.highlighted = (this.highlighted - 1 + m.length) % m.length; }
                else if (e.key === 'Enter' && this.highlighted >= 0) {
                    // Only intercept Enter when a suggestion is highlighted AND the user clearly
                    // wants completion (no space yet — i.e. they're still typing the verb).
                    if (! this.filterTerm.includes(' ')) {
                        e.preventDefault();
                        this.choose(m[this.highlighted]);
                    }
                }
                else if (e.key === 'Escape') { this.open = false; }
                else if (e.key === 'Tab' && m.length > 0) {
                    e.preventDefault();
                    this.choose(m[this.highlighted]);
                }
            },
        }"
        x-on:click.outside="open = false"
    >
        <form wire:submit.prevent="runReplCommand" class="flex items-stretch gap-2">
            <span class="inline-flex items-center px-2 font-mono text-sm text-brand-mist select-none">&gt;</span>
            <x-text-input
                x-ref="replInput"
                wire:model="replInput"
                x-on:input="onInput($event)"
                x-on:keydown="onKey($event)"
                x-on:focus="onInput({ target: $refs.replInput })"
                type="text"
                autocomplete="off"
                spellcheck="false"
                class="block w-full font-mono text-sm"
                placeholder="{{ __('e.g. INFO server — start typing for command suggestions') }}"
                wire:loading.attr="disabled"
                wire:target="runReplCommand"
            />
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="runReplCommand">
                <span wire:loading.remove wire:target="runReplCommand">{{ __('Run') }}</span>
                <span wire:loading wire:target="runReplCommand">{{ __('Running…') }}</span>
            </x-primary-button>
        </form>

        {{-- Autocomplete dropdown. Opens upward (bottom-full) because the REPL card has
             `overflow-hidden` and the input sits at its bottom edge — a downward-opening
             dropdown gets clipped. Hidden when no matches or user has moved past the verb. --}}
        <div
            x-show="open"
            x-cloak
            class="absolute left-6 right-24 bottom-full z-20 mb-1 max-h-72 overflow-auto rounded-lg border border-brand-ink/10 bg-white shadow-lg ring-1 ring-brand-ink/5"
        >
            <template x-for="(item, idx) in matches()" :key="item.name">
                <button
                    type="button"
                    x-on:click="choose(item)"
                    x-on:mouseenter="highlighted = idx"
                    :class="{ 'bg-brand-sand/60': highlighted === idx, 'hover:bg-brand-sand/40': highlighted !== idx }"
                    class="block w-full border-b border-brand-ink/5 px-3 py-2 text-left text-xs last:border-b-0"
                >
                    <span class="font-mono font-semibold text-brand-ink" x-text="item.name"></span>
                    <span class="ml-2 font-mono text-brand-mist" x-text="item.syntax.slice(item.name.length).trim()"></span>
                    <template x-if="item.mutating">
                        <span class="ml-2 inline-flex items-center rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">{{ __('mutating') }}</span>
                    </template>
                </button>
            </template>
        </div>
    </div>

    {{-- Command reference modal. Same data as the autocomplete, grouped + searchable. --}}
    <x-modal :name="$commandModalName" maxWidth="4xl" overlayClass="bg-brand-ink/40">
        <div
            x-data="{
                search: '',
                matches(group) {
                    const term = this.search.trim().toUpperCase();
                    if (term === '') return group;
                    return group.filter(c =>
                        c.name.includes(term) ||
                        c.summary.toUpperCase().includes(term)
                    );
                },
                insert(item) {
                    const input = document.querySelector('[wire\\:key=\'cache-repl-{{ $engine }}\'] [x-ref=\'replInput\']');
                    if (input) {
                        input.value = item.syntax;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    this.$dispatch('close');
                    setTimeout(() => input && input.focus(), 80);
                },
            }"
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Reference') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __(':engine — command reference', ['engine' => $engineLabel]) }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('Curated set of the commands operators reach for most. Click a command to drop its syntax into the input — fill in the placeholders, then hit Run. Commands marked "mutating" need the unlock toggle.') }}
                </p>
                <div class="relative mt-4">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-brand-mist">
                        <x-heroicon-o-magnifying-glass class="h-4 w-4" />
                    </span>
                    <x-text-input
                        x-model="search"
                        type="search"
                        autocomplete="off"
                        spellcheck="false"
                        class="block w-full pl-10 text-sm"
                        placeholder="{{ __('Filter commands — name or summary') }}"
                    />
                </div>
            </div>

            <div class="max-h-[60vh] overflow-auto px-6 py-5">
                <div class="space-y-6">
                    @foreach ($commandsByGroup as $groupName => $groupCmds)
                        @php
                            $groupId = 'group-'.\Illuminate\Support\Str::slug($groupName);
                        @endphp
                        <section
                            x-data="{ groupCmds: @js(array_values(array_map(fn ($c) => ['name' => $c['name'], 'syntax' => $c['syntax'], 'summary' => $c['summary'], 'mutating' => $c['mutating']], $groupCmds))) }"
                            x-show="matches(groupCmds).length > 0"
                        >
                            <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $groupName }}</h3>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <template x-for="item in matches(groupCmds)" :key="item.name">
                                    <button
                                        type="button"
                                        x-on:click="insert(item)"
                                        class="flex flex-col items-start gap-1 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 text-left transition-colors hover:border-brand-forest/30 hover:bg-brand-sand/40"
                                    >
                                        <span class="flex w-full items-center justify-between gap-2">
                                            <span class="font-mono text-sm font-semibold text-brand-ink" x-text="item.name"></span>
                                            <template x-if="item.mutating">
                                                <span class="inline-flex items-center rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">{{ __('mutating') }}</span>
                                            </template>
                                        </span>
                                        <span class="font-mono text-[11px] text-brand-mist" x-text="item.syntax"></span>
                                        <span class="text-[11px] leading-snug text-brand-moss" x-text="item.summary"></span>
                                    </button>
                                </template>
                            </div>
                        </section>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
            </div>
        </div>
    </x-modal>
    </div>
</div>
