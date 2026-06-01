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
    /** Current page of the in-memory SCAN buffer. Drives slice + prev/next. */
    'keysTablePage' => 1,
    /** True when the keys list was hydrated from session on mount, not freshly SCANned. */
    'fromCache' => false,
])

@php
    $patternCatalog = \App\Support\Servers\CachePatternCatalog::all();
    $patternsByGroup = \App\Support\Servers\CachePatternCatalog::byGroup();
    $patternModalName = 'cache-pattern-reference-'.$engine;
@endphp

{{-- Strip `overflow-hidden` from the card root so the autocomplete dropdown
     (positioned `absolute top-full z-20` under the Pattern input) can paint
     past the card's bottom edge. With overflow-hidden in place, z-index does
     nothing — the dropdown gets clipped flush at the card border and the
     visible portion looks like it's "hiding behind" the next workspace card.
     The header still uses bg-brand-sand/20 + border-b which naturally
     respects rounded corners, so visually nothing else changes. --}}
<div class="{{ str_replace('overflow-hidden', 'overflow-visible', $card) }}" wire:key="cache-key-browser-{{ $engine }}">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Keys') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine — key browser', ['engine' => $engineLabel]) }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('SCAN-based key explorer. Walks the keyspace in pages without locking the engine the way KEYS * does.') }}</p>
        </div>
        @if ($loaded)
            <button type="button" wire:click="hideKeyBrowser" class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                <x-heroicon-o-eye-slash class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Hide') }}
            </button>
        @endif
    </div>

    <div class="px-6 py-6 sm:px-7">
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

    {{-- Active-scan banner. SCAN walks the keyspace in bounded iterations
         server-side (see CacheServiceKeyExplorer::MAX_SCAN_ITERATIONS). For a
         large keyspace the per-Search round-trip can take a couple of seconds
         which previously showed nothing in-body except the "Scanning…" label
         on the button. This block surfaces the pattern, an explainer line so
         the operator knows SCAN is non-blocking, and a skeleton table that
         lines up with the real result table that lands next. --}}
    <div wire:loading wire:target="searchKeyBrowser,loadKeyBrowserPage" class="mt-4">
        <div class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50/70 px-4 py-3 text-xs text-sky-900">
            <svg class="mt-0.5 h-4 w-4 shrink-0 animate-spin text-sky-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10" opacity="0.25" />
                <path d="M22 12a10 10 0 0 1-10 10" stroke-linecap="round" />
            </svg>
            <div class="min-w-0">
                <p class="font-semibold">
                    {{ __('Scanning') }} <code class="rounded bg-white/70 px-1.5 py-0.5 font-mono text-[11px] text-sky-900 ring-1 ring-sky-200">{{ $pattern ?: '*' }}</code>{{ __(' on the engine…') }}
                </p>
                <p class="mt-0.5 text-sky-800/90">{{ __('Walks the keyspace in bounded SCAN iterations (non-blocking — KEYS * would lock the engine). Empty result so far means no matches in the slice scanned this pass; hit "Load more" if you need to keep walking.') }}</p>
            </div>
        </div>
        <div class="mt-3 overflow-hidden rounded-xl border border-brand-ink/10">
            <div class="bg-brand-sand/40 px-4 py-3 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Key') }}</div>
            <div class="space-y-2 bg-white p-4">
                @foreach (range(1, 5) as $i)
                    <div class="h-3 w-{{ $i % 2 === 0 ? 'full' : '3/4' }} animate-pulse rounded bg-brand-ink/10"></div>
                @endforeach
            </div>
        </div>
    </div>

    @if ($error)
        <div wire:loading.remove wire:target="searchKeyBrowser,loadKeyBrowserPage">
            <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $error }}</p>
        </div>
    @endif

    @if ($loaded && empty($keys) && ! $error)
        {{-- Matches-empty empty state. Same dashed-border pattern as the
             other cards' idle/empty surfaces so the operator immediately
             recognises this as a "no data" state rather than "load failed". --}}
        <div class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center" wire:loading.remove wire:target="searchKeyBrowser,loadKeyBrowserPage">
            <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
            </span>
            <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No keys matched') }}</p>
            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                {{ __('SCAN walked the keyspace with pattern') }}
                <code class="rounded bg-white/70 px-1 py-0.5 font-mono text-[11px] text-brand-ink ring-1 ring-brand-ink/10">{{ $pattern ?: '*' }}</code>
                {{ __('and came back empty. Try a broader pattern like') }} <code class="rounded bg-white/70 px-1 py-0.5 font-mono text-[11px] text-brand-ink ring-1 ring-brand-ink/10">*</code>, {{ __('or open the') }} <strong>{{ __('Pattern guide') }}</strong> {{ __('for common shapes.') }}
            </p>
        </div>
    @endif

    @if (! $loaded && empty($keys) && ! $error)
        {{-- Pre-search idle state. Hides during wire:loading so the
             scanning skeleton above takes over the moment Search fires. --}}
        <div class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center" wire:loading.remove wire:target="searchKeyBrowser,loadKeyBrowserPage">
            <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
            </span>
            <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No search yet') }}</p>
            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                {{ __('Pick a pattern above (default') }}
                <code class="rounded bg-white/70 px-1 py-0.5 font-mono text-[11px] text-brand-ink ring-1 ring-brand-ink/10">*</code>
                {{ __('matches everything) and hit') }}
                <span class="inline-flex items-center gap-1 rounded-md bg-brand-ink px-1.5 py-0.5 align-middle text-[11px] font-medium text-white">{{ __('Search') }}</span>
                {{ __('to walk the keyspace via SCAN — non-blocking, paginated, safe to run on a busy engine.') }}
            </p>
        </div>
    @endif

    @if (! empty($keys))
        <div wire:loading.remove wire:target="searchKeyBrowser,loadKeyBrowserPage">
        @if ($fromCache ?? false)
            <p class="mt-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-amber-900">
                <x-heroicon-o-clock class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>{{ __('Showing your previous SCAN result for') }} <code class="rounded bg-white/60 px-1 py-0.5 font-mono">{{ $pattern ?: '*' }}</code>. {{ __('Click') }} <strong>{{ __('Search') }}</strong> {{ __('to refresh.') }}</span>
            </p>
        @endif
        @php
            // Client-side pagination of the SCAN buffer. Slicing is free
            // (everything's already in memory) and prev/next never re-hits
            // the engine. "Load more" still continues the SCAN cursor and
            // appends to $keys — that's separate from the page index.
            $pageSize = \App\Livewire\Servers\WorkspaceCaches::KEYS_TABLE_PAGE_SIZE;
            $keysCount = count($keys);
            $keysPageCount = max(1, (int) ceil($keysCount / $pageSize));
            $keysCurrentPage = max(1, min((int) ($keysTablePage ?? 1), $keysPageCount));
            $keysStartIndex = ($keysCurrentPage - 1) * $pageSize;
            $keysSlice = array_slice($keys, $keysStartIndex, $pageSize);
            $keysRangeStart = $keysStartIndex + 1;
            $keysRangeEnd = min($keysStartIndex + $pageSize, $keysCount);
        @endphp
        <div class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/40 text-left text-xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3">{{ __('Key') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($keysSlice as $key)
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

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-brand-moss">
            <span>{{ __('Showing :start–:end of :count keys', ['start' => $keysRangeStart, 'end' => $keysRangeEnd, 'count' => $keysCount]) }}</span>
            <div class="flex flex-wrap items-center gap-3">
                @if ($keysPageCount > 1)
                    <div class="inline-flex items-center gap-1">
                        <button
                            type="button"
                            wire:click="setKeysTablePage({{ $keysCurrentPage - 1 }})"
                            @disabled($keysCurrentPage <= 1)
                            class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <x-heroicon-m-chevron-left class="h-3 w-3" />
                            {{ __('Prev') }}
                        </button>
                        <span class="px-2 font-mono text-brand-moss">{{ $keysCurrentPage }} / {{ $keysPageCount }}</span>
                        <button
                            type="button"
                            wire:click="setKeysTablePage({{ $keysCurrentPage + 1 }})"
                            @disabled($keysCurrentPage >= $keysPageCount)
                            class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {{ __('Next') }}
                            <x-heroicon-m-chevron-right class="h-3 w-3" />
                        </button>
                    </div>
                @endif
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
        </div>
        </div>{{-- /wire:loading.remove wrapper for keys block --}}
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
</div>
