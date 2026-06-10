{{--
    Ctrl/Cmd + "/" docs search palette (⌘K is reserved for the app's global
    command palette). Self-contained Alpine (no external deps):
    fetches the prebuilt index once, runs a small weighted fuzzy scorer
    (title > heading > description > body), groups by category, full keyboard
    nav. The `docsSearch()` x-data lives on the docs-shell root.
--}}
<template x-teleport="body">
    <div
        x-show="isOpen"
        x-cloak
        @keydown.escape.window="close()"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="go()"
        class="fixed inset-0 z-[100] flex items-start justify-center p-4 pt-[10vh]"
        role="dialog"
        aria-modal="true"
    >
        <div class="absolute inset-0 bg-brand-ink/40 backdrop-blur-sm" @click="close()"></div>

        <div x-show="isOpen" x-transition class="relative w-full max-w-xl overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-2xl">
            <div class="flex items-center gap-2 border-b border-brand-ink/10 px-4">
                <x-heroicon-o-magnifying-glass class="h-5 w-5 shrink-0 text-brand-moss" aria-hidden="true" />
                <input
                    x-ref="input"
                    x-model="query"
                    @input="search()"
                    type="text"
                    placeholder="{{ __('Search the documentation…') }}"
                    class="w-full border-0 bg-transparent py-3.5 text-sm text-brand-ink placeholder:text-brand-mist focus:outline-none focus:ring-0"
                    autocomplete="off" spellcheck="false"
                />
                <kbd class="hidden sm:inline-flex items-center rounded border border-brand-ink/15 bg-brand-sand/40 px-1.5 py-0.5 text-[0.625rem] font-semibold text-brand-moss">Esc</kbd>
            </div>

            <div class="max-h-[60vh] overflow-y-auto py-2" x-ref="results">
                <template x-if="query.length > 0 && results.length === 0">
                    <p class="px-4 py-6 text-center text-sm text-brand-moss">{{ __('No matches.') }}</p>
                </template>
                <template x-if="query.length === 0">
                    <p class="px-4 py-6 text-center text-sm text-brand-mist">{{ __('Type to search across every guide.') }}</p>
                </template>

                <template x-for="(item, i) in results" :key="item.url">
                    <a
                        :href="item.url"
                        @mouseenter="active = i"
                        @click="close()"
                        :class="active === i ? 'bg-brand-sand/60' : ''"
                        class="block px-4 py-2.5 cursor-pointer"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span class="truncate text-sm font-medium text-brand-ink" x-text="item.title"></span>
                            <span class="shrink-0 rounded-full bg-brand-sage/15 px-2 py-0.5 text-[0.625rem] font-semibold text-brand-forest" x-text="item.category"></span>
                        </div>
                        <p class="mt-0.5 truncate text-xs text-brand-moss" x-text="item.snippet || item.description"></p>
                    </a>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
    window.docsSearch = function () {
        return {
            isOpen: false,
            query: '',
            index: null,
            results: [],
            active: 0,

            init() {
                window.addEventListener('keydown', (e) => {
                    // Ctrl/Cmd + "/" opens docs search (⌘K is owned by the app's
                    // global command palette).
                    if (this.isOpen) return;
                    if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                        e.preventDefault();
                        this.open();
                    }
                });
            },

            async ensureIndex() {
                if (this.index) return;
                try {
                    const res = await fetch(@json(route('docs.search-index')));
                    this.index = await res.json();
                } catch (e) {
                    this.index = [];
                }
            },

            async open() {
                this.isOpen = true;
                await this.ensureIndex();
                this.$nextTick(() => this.$refs.input && this.$refs.input.focus());
            },

            close() {
                this.isOpen = false;
            },

            move(dir) {
                if (!this.results.length) return;
                this.active = (this.active + dir + this.results.length) % this.results.length;
                this.$nextTick(() => {
                    const el = this.$refs.results?.querySelectorAll('a')[this.active];
                    el && el.scrollIntoView({ block: 'nearest' });
                });
            },

            go() {
                const item = this.results[this.active];
                if (item) window.location.href = item.url;
            },

            score(doc, terms) {
                const t = (doc.title || '').toLowerCase();
                const h = (doc.headings || []).join(' ').toLowerCase();
                const d = (doc.description || '').toLowerCase();
                const b = (doc.body || '').toLowerCase();
                let total = 0;
                for (const term of terms) {
                    let s = 0;
                    if (t === term) s += 120;
                    if (t.startsWith(term)) s += 40;
                    if (t.includes(term)) s += 30;
                    if (h.includes(term)) s += 12;
                    if (d.includes(term)) s += 8;
                    if (b.includes(term)) s += 3;
                    if (s === 0) return 0; // every term must appear somewhere
                    total += s;
                }
                return total;
            },

            search() {
                const q = this.query.trim().toLowerCase();
                this.active = 0;
                if (!q || !this.index) { this.results = []; return; }
                const terms = q.split(/\s+/).filter(Boolean);
                this.results = this.index
                    .map((doc) => ({ doc, s: this.score(doc, terms) }))
                    .filter((r) => r.s > 0)
                    .sort((a, b) => b.s - a.s)
                    .slice(0, 12)
                    .map((r) => ({
                        title: r.doc.title,
                        category: r.doc.category,
                        description: r.doc.description,
                        url: r.doc.url,
                        snippet: this.snippet(r.doc.body, terms[0]),
                    }));
            },

            snippet(body, term) {
                if (!body) return '';
                const i = body.toLowerCase().indexOf(term);
                if (i < 0) return '';
                const start = Math.max(0, i - 30);
                return (start > 0 ? '…' : '') + body.slice(start, start + 90).trim() + '…';
            },
        };
    };
</script>
