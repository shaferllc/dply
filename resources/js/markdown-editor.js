/**
 * Markdown editor chrome for Livewire textareas — toolbar, selection-aware
 * formatting, keyboard shortcuts, list continuation, autosize and a
 * distraction-free mode.
 *
 * Rendering stays on the server: the preview pane is a plain <x-markdown>
 * blade, so what the operator previews is byte-identical to what the note will
 * look like once saved (same CommonMark options, same HTML escaping). That is
 * why this file carries no Markdown parser and adds no bundle weight.
 *
 * The textarea is NOT bound with wire:model. Every keystroke does a deferred
 * $wire.set (no request); switching to Preview does a live one so the server
 * has the current text before it renders. Binding both ways would fight the
 * morph on each preview refresh and drop the caret.
 *
 * Kept out of Blade x-data because HTML attribute quoting truncates scripts
 * this size.
 */
export function registerMarkdownEditor(Alpine) {
    Alpine.data('dplyMarkdownEditor', (config = {}) => ({
        /** Livewire property this editor writes to, e.g. 'noteDraft'. */
        property: config.property || '',

        value: config.value ?? '',

        /** write | preview | split */
        mode: 'write',

        fullscreen: false,

        maxLength: config.maxLength ?? 10000,

        /** Pending live-sync timer used while a preview pane is visible. */
        previewTimer: null,

        init() {
            if (this.$refs.input) {
                this.value = this.$refs.input.value;
                this.autosize();
            }

            // Livewire can rewrite the textarea (validation errors, a
            // server-side reset after save). Re-read rather than clobber.
            this.$watch('mode', () => {
                if (this.mode !== 'write') {
                    this.syncNow();
                }
                this.$nextTick(() => this.autosize());
            });
        },

        destroy() {
            // Never leave the page unscrollable if the editor is torn down
            // (Livewire morph, wire:navigate) while still in fullscreen.
            document.body.classList.remove('overflow-y-hidden');
            clearTimeout(this.previewTimer);
        },

        /**
         * The server cleared (or replaced) the bound property — e.g. the note
         * was saved and the compose box reset. Livewire's morph fixes the
         * textarea, but Alpine's copy would stay stale and keep showing the old
         * character count, so the component announces the reset explicitly.
         */
        onExternalReset(event) {
            if (event.detail?.property !== this.property) {
                return;
            }

            this.value = event.detail.value ?? '';
            if (this.$refs.input) {
                this.$refs.input.value = this.value;
            }
            this.mode = 'write';
            this.$nextTick(() => this.autosize());
        },

        get characterCount() {
            return this.value.length;
        },

        get overLimit() {
            return this.characterCount > this.maxLength;
        },

        get isEmpty() {
            return this.value.trim() === '';
        },

        onInput(event) {
            this.value = event.target.value;
            this.autosize();
            this.push(false);

            // Only pay for a round trip when something is actually previewing.
            if (this.mode !== 'write') {
                clearTimeout(this.previewTimer);
                this.previewTimer = setTimeout(() => this.syncNow(), 500);
            }
        },

        /** @param {boolean} live Whether to trigger a Livewire request. */
        push(live) {
            if (! this.property || ! this.$wire) {
                return;
            }
            this.$wire.set(this.property, this.value, live);
        },

        syncNow() {
            clearTimeout(this.previewTimer);
            this.push(true);
        },

        setMode(mode) {
            this.mode = mode;
            if (mode !== 'preview') {
                this.$nextTick(() => this.$refs.input?.focus());
            }
        },

        toggleFullscreen() {
            this.fullscreen = ! this.fullscreen;
            document.body.classList.toggle('overflow-y-hidden', this.fullscreen);
            this.$nextTick(() => {
                this.autosize();
                this.$refs.input?.focus();
            });
        },

        autosize() {
            const el = this.$refs.input;
            if (! el || this.fullscreen) {
                return;
            }
            el.style.height = 'auto';
            // Cap the growth so a long runbook doesn't push the actions off
            // screen — past that the textarea scrolls itself.
            el.style.height = `${Math.min(el.scrollHeight, 560)}px`;
        },

        // --- Selection helpers -------------------------------------------------

        selection() {
            const el = this.$refs.input;
            return {
                el,
                start: el.selectionStart,
                end: el.selectionEnd,
                text: this.value.slice(el.selectionStart, el.selectionEnd),
            };
        },

        replace(start, end, text, selectStart, selectEnd) {
            const el = this.$refs.input;
            this.value = this.value.slice(0, start) + text + this.value.slice(end);
            el.value = this.value;
            el.focus();
            el.setSelectionRange(
                selectStart ?? start + text.length,
                selectEnd ?? start + text.length,
            );
            this.autosize();
            this.push(false);
            if (this.mode !== 'write') {
                clearTimeout(this.previewTimer);
                this.previewTimer = setTimeout(() => this.syncNow(), 500);
            }
        },

        /**
         * Wrap the selection in `token`, or unwrap it when it is already
         * wrapped — so the bold button toggles instead of stacking asterisks.
         * With no selection it drops the markers in and puts the caret between.
         */
        wrap(token, placeholder = '') {
            const { start, end, text } = this.selection();
            const before = this.value.slice(Math.max(0, start - token.length), start);
            const after = this.value.slice(end, end + token.length);

            if (before === token && after === token) {
                this.replace(
                    start - token.length,
                    end + token.length,
                    text,
                    start - token.length,
                    end - token.length,
                );
                return;
            }

            const body = text || placeholder;
            const wrapped = `${token}${body}${token}`;
            const caret = start + token.length;
            this.replace(start, end, wrapped, caret, caret + body.length);
        },

        /**
         * Toggle a line prefix (`## `, `- `, `> `, `- [ ] `) across every line
         * the selection touches.
         */
        prefixLines(prefix, pattern) {
            const { start, end } = this.selection();
            const lineStart = this.value.lastIndexOf('\n', start - 1) + 1;
            const lineEndIndex = this.value.indexOf('\n', end);
            const lineEnd = lineEndIndex === -1 ? this.value.length : lineEndIndex;

            const block = this.value.slice(lineStart, lineEnd);
            const lines = block.split('\n');
            const matcher = pattern || new RegExp(`^${prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`);
            const allPrefixed = lines.every((line) => line.trim() === '' || matcher.test(line));

            const next = lines
                .map((line) => {
                    if (line.trim() === '') {
                        return line;
                    }
                    return allPrefixed ? line.replace(matcher, '') : `${prefix}${line}`;
                })
                .join('\n');

            this.replace(lineStart, lineEnd, next, lineStart, lineStart + next.length);
        },

        /** Ordered lists renumber, so they can't reuse prefixLines. */
        orderedList() {
            const { start, end } = this.selection();
            const lineStart = this.value.lastIndexOf('\n', start - 1) + 1;
            const lineEndIndex = this.value.indexOf('\n', end);
            const lineEnd = lineEndIndex === -1 ? this.value.length : lineEndIndex;

            const lines = this.value.slice(lineStart, lineEnd).split('\n');
            const numbered = /^\d+\.\s/;
            const allNumbered = lines.every((line) => line.trim() === '' || numbered.test(line));

            let n = 0;
            const next = lines
                .map((line) => {
                    if (line.trim() === '') {
                        return line;
                    }
                    if (allNumbered) {
                        return line.replace(numbered, '');
                    }
                    n += 1;
                    return `${n}. ${line}`;
                })
                .join('\n');

            this.replace(lineStart, lineEnd, next, lineStart, lineStart + next.length);
        },

        link() {
            const { start, end, text } = this.selection();
            const looksLikeUrl = /^https?:\/\/\S+$/i.test(text.trim());
            const label = looksLikeUrl ? '' : text;
            const url = looksLikeUrl ? text.trim() : 'https://';
            const markup = `[${label}](${url})`;

            // Land the caret wherever the operator still has to type.
            const caret = looksLikeUrl ? start + 1 : start + markup.length - 1;
            this.replace(start, end, markup, caret, looksLikeUrl ? caret : caret);
        },

        codeBlock() {
            const { start, end, text } = this.selection();
            const body = text || '';
            const markup = `\n\`\`\`\n${body}\n\`\`\`\n`;
            const caret = start + 5;
            this.replace(start, end, markup, caret, caret + body.length);
        },

        table() {
            const { start, end } = this.selection();
            const markup = '\n| Step | Detail |\n| --- | --- |\n|  |  |\n';
            this.replace(start, end, markup, start + markup.length);
        },

        // --- Key handling ------------------------------------------------------

        onKeydown(event) {
            const mod = event.metaKey || event.ctrlKey;

            if (mod && ! event.altKey) {
                const key = event.key.toLowerCase();
                if (key === 'b') {
                    event.preventDefault();
                    return this.wrap('**', 'bold text');
                }
                if (key === 'i') {
                    event.preventDefault();
                    return this.wrap('_', 'italic text');
                }
                if (key === 'k') {
                    event.preventDefault();
                    return this.link();
                }
                if (key === 'e') {
                    event.preventDefault();
                    return this.wrap('`', 'code');
                }
            }

            if (event.key === 'Escape' && this.fullscreen) {
                event.preventDefault();
                return this.toggleFullscreen();
            }

            if (event.key === 'Tab' && ! event.shiftKey && ! mod) {
                event.preventDefault();
                const { start, end } = this.selection();
                return this.replace(start, end, '  ');
            }

            if (event.key === 'Enter' && ! event.shiftKey && ! mod) {
                return this.continueList(event);
            }

            return undefined;
        },

        /**
         * Pressing Enter inside a list carries the marker to the next line, and
         * pressing it again on the resulting empty item ends the list — the
         * behaviour every Markdown editor has trained people to expect.
         */
        continueList(event) {
            const { start } = this.selection();
            const lineStart = this.value.lastIndexOf('\n', start - 1) + 1;
            const line = this.value.slice(lineStart, start);

            const match = line.match(/^(\s*)(-\s\[[ xX]\]\s|[-*+]\s|(\d+)\.\s)/);
            if (! match) {
                return undefined;
            }

            const [marker, indent, token, number] = match;

            // Enter on an empty item closes the list instead of adding another.
            if (line.trim() === marker.trim()) {
                event.preventDefault();
                return this.replace(lineStart, start, indent);
            }

            event.preventDefault();
            const nextToken = number
                ? `${parseInt(number, 10) + 1}. `
                : token.replace(/\[[xX]\]/, '[ ]');

            return this.replace(start, start, `\n${indent}${nextToken}`);
        },

        /** Pasting a URL over selected text turns it into a Markdown link. */
        onPaste(event) {
            const pasted = event.clipboardData?.getData('text/plain') ?? '';
            const { start, end, text } = this.selection();

            if (text && /^https?:\/\/\S+$/i.test(pasted.trim())) {
                event.preventDefault();
                this.replace(start, end, `[${text}](${pasted.trim()})`);
            }
        },
    }));
}
