{{--
    Global command palette. Single root element (Livewire requirement). Alpine
    owns open/close, the Cmd/Ctrl+K shortcut and keyboard navigation; Livewire
    owns the drill-down stack, the query and the org-scoped lookups.

    The palette is nestable: leaf rows (<a>) navigate; nestable rows (<button>)
    push a context server-side via wire:click. Esc / Backspace at an empty query
    step back up the stack before closing. Active-row highlighting emits each
    row's flat index ({{ $i }}) straight into the markup so `active === N` stays
    correct across every Livewire re-render.
--}}
<div
    x-data="{
        open: false,
        active: 0,
        init() {
            window.addEventListener('keydown', (e) => {
                if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    this.toggle();
                }
            });
            window.addEventListener('dply-command-palette-open', () => this.openPalette());
        },
        toggle() { this.open ? this.close() : this.openPalette(); },
        openPalette() {
            this.open = true;
            this.active = 0;
            this.$nextTick(() => this.$refs.input && this.$refs.input.focus());
        },
        close() {
            this.open = false;
            // Reset back to root so the next open starts fresh (round-trip is
            // invisible since the panel is already hidden).
            if (this.$wire.stack && this.$wire.stack.length) this.$wire.resetStack();
        },
        rows() { return Array.from(this.$root.querySelectorAll('[data-cmdk-item]')); },
        move(dir) {
            const rows = this.rows();
            if (! rows.length) return;
            this.active = (this.active + dir + rows.length) % rows.length;
            rows[this.active] && rows[this.active].scrollIntoView({ block: 'nearest' });
        },
        choose() {
            const el = this.rows()[this.active];
            if (! el) return;
            // Anchors navigate (close first); nestable buttons drill in and the
            // palette stays open. el.click() routes to whichever handler applies.
            if (el.tagName === 'A') this.close();
            el.click();
        },
        drillIn() {
            // Right-arrow: drill INTO the highlighted row, but only when it's a
            // nestable context (a <button> that pushes). Leaf anchors aren't a
            // drill-in, so right-arrow is a no-op on them.
            const el = this.rows()[this.active];
            if (el && el.tagName === 'BUTTON') el.click();
        },
    }"
    @keydown.escape.window="open && ($wire.stack.length ? $wire.pop() : close())"
    @cmdk-changed.window="active = 0; $nextTick(() => $refs.input && $refs.input.focus())"
    class="contents"
>
    <div x-show="open" x-cloak style="z-index: 200;" class="fixed inset-0 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="{{ __('Search') }}">
        {{-- Backdrop --}}
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 bg-brand-ink/40 backdrop-blur-sm"
            @click="close()"
            aria-hidden="true"
        ></div>

        {{-- Panel --}}
        <div class="relative w-full max-w-xl">
            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-2 scale-[0.98]"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-2xl shadow-brand-ink/20"
            >
                {{-- Breadcrumb (only when drilled into a context) --}}
                @if (count($stack))
                    <div class="flex items-center gap-1 border-b border-brand-ink/10 bg-brand-cream/50 px-3 py-1.5 text-xs text-brand-moss">
                        <button
                            type="button"
                            wire:click="pop"
                            class="inline-flex shrink-0 items-center justify-center rounded-md p-1 hover:bg-brand-sand/50 hover:text-brand-ink"
                            title="{{ __('Back') }}"
                        >
                            <x-heroicon-o-chevron-left class="h-3.5 w-3.5" />
                        </button>
                        <button type="button" wire:click="resetStack" class="shrink-0 rounded px-1 py-0.5 hover:text-brand-ink">{{ __('Home') }}</button>
                        @foreach ($stack as $idx => $crumb)
                            <x-heroicon-o-chevron-right class="h-3 w-3 shrink-0 text-brand-mist" />
                            <button
                                type="button"
                                wire:click="popTo({{ $idx + 1 }})"
                                class="truncate rounded px-1 py-0.5 {{ $loop->last ? 'font-semibold text-brand-ink' : 'hover:text-brand-ink' }}"
                            >{{ $crumb['label'] }}</button>
                        @endforeach
                    </div>
                @endif

                {{-- Search input --}}
                <div class="flex items-center gap-3 border-b border-brand-ink/10 px-4">
                    <x-heroicon-o-magnifying-glass class="h-5 w-5 shrink-0 text-brand-moss" />
                    <input
                        x-ref="input"
                        type="text"
                        wire:model.live.debounce.200ms="query"
                        @input="active = 0"
                        @keydown.arrow-down.prevent="move(1)"
                        @keydown.arrow-up.prevent="move(-1)"
                        @keydown.enter.prevent="choose()"
                        @keydown.arrow-right="if ($event.target.selectionStart === $event.target.value.length && $event.target.selectionStart === $event.target.selectionEnd) { $event.preventDefault(); drillIn(); }"
                        @keydown.arrow-left="if ($event.target.selectionStart === 0 && $event.target.selectionEnd === 0 && $wire.stack.length) { $event.preventDefault(); $wire.pop(); }"
                        @keydown.backspace="if ($event.target.value === '' && $wire.stack.length) { $event.preventDefault(); $wire.pop(); }"
                        placeholder="{{ $placeholder }}"
                        class="w-full border-0 bg-transparent py-3.5 text-base text-brand-ink placeholder:text-brand-mist focus:outline-none focus:ring-0"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <kbd class="hidden shrink-0 rounded bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold text-brand-moss sm:inline-flex">ESC</kbd>
                </div>

                {{-- Results --}}
                <div class="max-h-[55vh] overflow-y-auto px-1.5 py-2" wire:loading.class="opacity-60">
                    @php $i = 0; @endphp
                    @forelse ($groups as $group)
                        <div class="px-1.5 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">
                            {{ $group['label'] }}
                        </div>
                        @foreach ($group['items'] as $item)
                            @php $isNest = empty($item['url']); @endphp
                            @if ($isNest)
                                <button
                                    type="button"
                                    wire:click="push('{{ $item['into']['type'] }}'@if(! empty($item['into']['id'])), '{{ $item['into']['id'] }}'@endif)"
                                    data-cmdk-item
                                    @mouseenter="active = {{ $i }}"
                                    :class="active === {{ $i }} ? 'bg-brand-sand/60 text-brand-ink' : 'text-brand-ink/90'"
                                    class="flex w-full appearance-none items-center gap-3 rounded-lg border-0 px-2.5 py-2 text-left text-sm hover:bg-brand-sand/60 focus:outline-none"
                                >
                                    @include('livewire.partials.command-palette-row', ['item' => $item, 'i' => $i, 'isNest' => true])
                                </button>
                            @else
                                <a
                                    href="{{ $item['url'] }}"
                                    wire:navigate
                                    data-cmdk-item
                                    @mouseenter="active = {{ $i }}"
                                    :class="active === {{ $i }} ? 'bg-brand-sand/60 text-brand-ink' : 'text-brand-ink/90'"
                                    class="flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-sm hover:bg-brand-sand/60"
                                >
                                    @include('livewire.partials.command-palette-row', ['item' => $item, 'i' => $i, 'isNest' => false])
                                </a>
                            @endif
                            @php $i++; @endphp
                        @endforeach
                    @empty
                        <div class="px-4 py-10 text-center text-sm text-brand-moss">
                            @if (trim($query) === '')
                                {{ __('Nothing here yet.') }}
                            @else
                                {{ __('No matches for ":query".', ['query' => $query]) }}
                            @endif
                        </div>
                    @endforelse
                </div>

                {{-- Footer hints --}}
                <div class="flex items-center gap-4 border-t border-brand-ink/10 bg-brand-cream/40 px-4 py-2 text-[11px] text-brand-moss">
                    <span class="inline-flex items-center gap-1">
                        <kbd class="rounded bg-white px-1 py-0.5 font-semibold ring-1 ring-brand-ink/10">↑</kbd>
                        <kbd class="rounded bg-white px-1 py-0.5 font-semibold ring-1 ring-brand-ink/10">↓</kbd>
                        {{ __('navigate') }}
                    </span>
                    <span class="inline-flex items-center gap-1">
                        <kbd class="rounded bg-white px-1 py-0.5 font-semibold ring-1 ring-brand-ink/10">↵</kbd>
                        {{ __('select') }}
                    </span>
                    @if (count($stack) || ! empty($groups))
                        <span class="hidden items-center gap-1 sm:inline-flex">
                            <kbd class="rounded bg-white px-1 py-0.5 font-semibold ring-1 ring-brand-ink/10">→</kbd>
                            <kbd class="rounded bg-white px-1 py-0.5 font-semibold ring-1 ring-brand-ink/10">←</kbd>
                            {{ __('open / back') }}
                        </span>
                    @endif
                    <span class="inline-flex items-center gap-1">
                        <kbd class="rounded bg-white px-1.5 py-0.5 font-semibold ring-1 ring-brand-ink/10">esc</kbd>
                        {{ __('back') }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>
