@props([
    'engine',
    'engineLabel',
    'row',
    'pattern',
    'keys',
    'loaded',
    'complete',
    'selected',
    'value',
    'valueError' => null,
    'error' => null,
    'replUnlocked' => false,
    'card' => 'dply-card overflow-hidden',
])

@php
    $patternCatalog = \App\Support\Servers\CachePatternCatalog::all();
    $patternsByGroup = \App\Support\Servers\CachePatternCatalog::byGroup();
    $patternModalName = 'cache-pattern-reference-'.$engine;
@endphp

<div class="{{ $card }} p-6 sm:p-8" wire:key="cache-key-browser-{{ $engine }}">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h3 class="text-lg font-semibold text-brand-ink">{{ __(':engine — key browser', ['engine' => $engineLabel]) }}</h3>
            <p class="mt-2 text-sm text-brand-moss">{{ __('SCAN-based key explorer. Walks the keyspace in pages without locking the engine the way KEYS * does.') }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2 self-start whitespace-nowrap">
            @if ($loaded)
                <button type="button" wire:click="hideKeyBrowser" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                    <x-heroicon-o-eye-slash class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Hide') }}
                </button>
            @endif
        </div>
    </div>

    <x-explainer class="mt-4">
        <p>{{ __('Each page runs SCAN with a small COUNT for up to 5 iterations server-side, returning the keys it found. The cursor in the response lets the next "Load more" continue exactly where it left off. Under heavy write traffic SCAN can repeat keys across pages — the explorer dedupes them client-side.') }}</p>
        <p>
            {{ __('Pattern is the redis SCAN MATCH glob — ') }}
            <code>*</code> {{ __('for everything, ') }}<code>session:*</code> {{ __('for one prefix, ') }}<code>?ache</code> {{ __('for single-character wildcard. ') }}
            {{ __('Inspecting a key fetches TYPE + TTL + value (truncated at 8 KB).') }}
        </p>
        <p>{{ __('Deleting a key requires the unlock toggle in the Console sub-tab. Every DEL is recorded in the audit log.') }}</p>
    </x-explainer>

    <div
        class="relative mt-4"
        x-data="{
            open: false,
            highlighted: 0,
            patterns: @js(array_map(fn ($p) => ['pattern' => $p['pattern'], 'description' => $p['description']], $patternCatalog)),
            term: '',
            matches() {
                const t = this.term.trim();
                if (t === '') return this.patterns.slice(0, 8);
                const lower = t.toLowerCase();
                return this.patterns
                    .filter(p => p.pattern.toLowerCase().includes(lower) || p.description.toLowerCase().includes(lower))
                    .slice(0, 8);
            },
            choose(item) {
                const input = this.$refs.patternInput;
                input.value = item.pattern;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();
                this.open = false;
                this.highlighted = 0;
            },
            onInput(e) {
                this.term = e.target.value;
                const m = this.matches();
                this.open = m.length > 0;
                if (this.highlighted >= m.length) this.highlighted = 0;
            },
            onKey(e) {
                const m = this.matches();
                if (! this.open || m.length === 0) return;
                if (e.key === 'ArrowDown') { e.preventDefault(); this.highlighted = (this.highlighted + 1) % m.length; }
                else if (e.key === 'ArrowUp') { e.preventDefault(); this.highlighted = (this.highlighted - 1 + m.length) % m.length; }
                else if (e.key === 'Escape') { this.open = false; }
                else if (e.key === 'Tab' && m.length > 0) {
                    e.preventDefault();
                    this.choose(m[this.highlighted]);
                }
            },
        }"
        x-on:click.outside="open = false"
    >
        <form wire:submit.prevent="searchKeyBrowser" class="flex flex-wrap items-end gap-2">
            <div class="grow">
                <div class="flex items-end justify-between gap-2">
                    <x-input-label for="keyBrowserPattern" :value="__('Pattern')" class="mb-0" />
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', @js($patternModalName))"
                        class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-brand-forest hover:underline"
                    >
                        <x-heroicon-o-book-open class="h-3 w-3" />
                        {{ __('Pattern guide') }}
                    </button>
                </div>
                <x-text-input
                    id="keyBrowserPattern"
                    x-ref="patternInput"
                    wire:model="keyBrowserPattern"
                    x-on:input="onInput($event)"
                    x-on:keydown="onKey($event)"
                    x-on:focus="open = matches().length > 0"
                    type="text"
                    spellcheck="false"
                    autocomplete="off"
                    class="mt-1 block w-full font-mono text-sm"
                    placeholder="*"
                    wire:loading.attr="disabled"
                    wire:target="searchKeyBrowser,loadKeyBrowserPage"
                />
            </div>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="searchKeyBrowser">
                <span wire:loading.remove wire:target="searchKeyBrowser">{{ __('Search') }}</span>
                <span wire:loading wire:target="searchKeyBrowser">{{ __('Scanning…') }}</span>
            </x-primary-button>
        </form>

        {{-- Autocomplete dropdown — common patterns + matches as the operator types. --}}
        <div
            x-show="open"
            x-cloak
            class="absolute left-0 right-24 top-full z-20 mt-1 max-h-72 overflow-auto rounded-lg border border-brand-ink/10 bg-white shadow-lg ring-1 ring-brand-ink/5"
        >
            <template x-for="(item, idx) in matches()" :key="item.pattern">
                <button
                    type="button"
                    x-on:click="choose(item)"
                    x-on:mouseenter="highlighted = idx"
                    :class="{ 'bg-brand-sand/60': highlighted === idx, 'hover:bg-brand-sand/40': highlighted !== idx }"
                    class="block w-full border-b border-brand-ink/5 px-3 py-2 text-left last:border-b-0"
                >
                    <span class="font-mono text-xs font-semibold text-brand-ink" x-text="item.pattern"></span>
                    <span class="ml-2 text-[11px] text-brand-moss" x-text="item.description"></span>
                </button>
            </template>
        </div>
    </div>

    {{-- Pattern reference modal — full grouped list + glob primer. --}}
    <x-modal :name="$patternModalName" maxWidth="3xl" overlayClass="bg-brand-ink/40">
        <div
            x-data="{
                insert(pattern) {
                    const input = document.getElementById('keyBrowserPattern');
                    if (input) {
                        input.value = pattern;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    this.$dispatch('close');
                    setTimeout(() => input && input.focus(), 80);
                },
            }"
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Reference') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('SCAN MATCH pattern guide') }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('SCAN MATCH uses Redis glob syntax. Click any pattern to drop it into the Pattern input — adjust prefixes/suffixes for your app, then Search.') }}
                </p>
                <ul class="mt-3 space-y-1 text-xs leading-relaxed text-brand-moss">
                    <li><code class="rounded bg-brand-sand/50 px-1 py-0.5 font-mono">*</code> — matches zero or more characters.</li>
                    <li><code class="rounded bg-brand-sand/50 px-1 py-0.5 font-mono">?</code> — matches exactly one character.</li>
                    <li><code class="rounded bg-brand-sand/50 px-1 py-0.5 font-mono">[abc]</code> — matches any single character in the set.</li>
                    <li><code class="rounded bg-brand-sand/50 px-1 py-0.5 font-mono">[a-z]</code> — matches a character range.</li>
                    <li><code class="rounded bg-brand-sand/50 px-1 py-0.5 font-mono">\\*</code> — escape a special character to match it literally.</li>
                </ul>
            </div>

            <div class="max-h-[60vh] overflow-auto px-6 py-5">
                <div class="space-y-6">
                    @foreach ($patternsByGroup as $groupName => $groupPatterns)
                        <section>
                            <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $groupName }}</h3>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($groupPatterns as $entry)
                                    <button
                                        type="button"
                                        x-on:click="insert(@js($entry['pattern']))"
                                        class="flex flex-col items-start gap-1 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 text-left transition-colors hover:border-brand-forest/30 hover:bg-brand-sand/40"
                                    >
                                        <span class="font-mono text-sm font-semibold text-brand-ink">{{ $entry['pattern'] }}</span>
                                        <span class="text-[11px] leading-snug text-brand-moss">{{ $entry['description'] }}</span>
                                    </button>
                                @endforeach
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

    @if ($error)
        <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $error }}</p>
    @endif

    @if ($loaded && empty($keys) && ! $error)
        <p class="mt-4 text-sm text-brand-moss">{{ __('No keys matched the pattern.') }}</p>
    @endif

    @if (! empty($keys))
        <div class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/40 text-left text-xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3">{{ __('Key') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($keys as $key)
                        <tr @class([
                            'bg-brand-sand/30' => $selected === $key,
                        ])>
                            <td class="px-4 py-2 font-mono text-xs text-brand-ink break-all">{{ $key }}</td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <button
                                    type="button"
                                    wire:click="inspectKey('{{ addslashes($key) }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="inspectKey"
                                    class="inline-flex items-center gap-1 text-xs font-medium text-brand-forest hover:underline disabled:opacity-50"
                                >
                                    <span wire:loading.remove wire:target="inspectKey('{{ addslashes($key) }}')">{{ __('Inspect') }}</span>
                                    <span wire:loading wire:target="inspectKey('{{ addslashes($key) }}')" class="inline-flex items-center gap-1">
                                        <x-spinner variant="forest" />
                                        {{ __('Reading…') }}
                                    </span>
                                </button>
                                @if ($replUnlocked)
                                    <span class="text-brand-mist mx-1">·</span>
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('deleteKey', ['{{ addslashes($key) }}'], @js(__('Delete key')), @js(__('Drop key :key from this engine. Cannot be undone.', ['key' => $key])), @js(__('Delete')), true)"
                                        class="text-xs font-medium text-rose-700 hover:underline"
                                    >{{ __('Delete') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-brand-moss">
            <span>{{ __('Showing :count keys', ['count' => count($keys)]) }}</span>
            @if (! $complete)
                <button
                    type="button"
                    wire:click="loadKeyBrowserPage"
                    wire:loading.attr="disabled"
                    wire:target="loadKeyBrowserPage"
                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    <x-heroicon-o-arrow-down class="h-3 w-3" />
                    <span wire:loading.remove wire:target="loadKeyBrowserPage">{{ __('Load more') }}</span>
                    <span wire:loading wire:target="loadKeyBrowserPage">{{ __('Loading…') }}</span>
                </button>
            @else
                <span class="text-brand-mist">{{ __('Scan complete.') }}</span>
            @endif
        </div>
    @endif

    @if ($selected !== null)
        <div class="relative mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
            {{-- While inspectKey is in flight, dim the panel and show a spinner overlay so
                 stale value data doesn't look like the response for the new selection. --}}
            <div
                wire:loading
                wire:target="inspectKey"
                class="absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-brand-sand/70 backdrop-blur-[1px]"
            >
                <span class="inline-flex items-center gap-2 text-xs font-medium text-brand-moss">
                    <x-spinner variant="forest" />
                    {{ __('Reading key…') }}
                </span>
            </div>

            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Inspecting') }}</p>
                    <p class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $selected }}</p>
                </div>
                <button
                    type="button"
                    wire:click="clearKeyInspection"
                    class="text-xs font-medium text-brand-moss hover:underline"
                >{{ __('Close') }}</button>
            </div>

            @if ($valueError)
                <p class="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $valueError }}</p>
            @elseif ($value !== null)
                <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Type') }}</dt>
                        <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $value['type'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('TTL (s)') }}</dt>
                        <dd class="mt-1 font-mono text-sm text-brand-ink">
                            @if ($value['ttl'] === -1)
                                <span class="text-brand-moss">{{ __('no expiry') }}</span>
                            @elseif ($value['ttl'] === -2)
                                <span class="text-rose-700">{{ __('expired') }}</span>
                            @else
                                {{ $value['ttl'] }}
                            @endif
                        </dd>
                    </div>
                    @if ($value['truncated'])
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Truncated') }}</dt>
                            <dd class="mt-1 text-xs text-amber-700">{{ __('Showing first 8 KB only') }}</dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-4">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Value') }}</dt>
                    <dd class="mt-1">
                        @if (is_array($value['value']))
                            <ul class="space-y-0.5 rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100">
                                @foreach ($value['value'] as $item)
                                    <li class="break-all">{{ $item }}</li>
                                @endforeach
                            </ul>
                        @else
                            <pre class="whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100">{{ $value['value'] }}</pre>
                        @endif
                    </dd>
                </div>
            @endif
        </div>
    @endif
</div>
