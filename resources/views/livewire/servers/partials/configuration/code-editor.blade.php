@props([
    'path',
    'readOnly' => false,
    'autocomplete' => [],
])

@vite(['resources/js/file-browser-editor-lazy.js'])

<div
    wire:key="config-editor-{{ md5($path) }}"
    wire:ignore
    x-data="{
        editor: null,
        syncToServer() {
            if (! this.editor || @js($readOnly)) return;
            this.$wire.set('config_contents', this.editor.view.state.doc.toString(), true);
        },
        async init() {
            const mountEditor = window.dplyEnsureFileBrowserEditor
                ? await window.dplyEnsureFileBrowserEditor()
                : null;
            if (! mountEditor) return;
            this.editor = mountEditor(this.$refs.editorMount, {
                content: this.$wire.config_contents ?? '',
                path: @js($path),
                readOnly: @js($readOnly),
                completions: @js($autocomplete),
                onChange: (val) => {
                    if (! @js($readOnly)) {
                        this.$wire.set('config_contents', val, true);
                    }
                },
            });
        },
        applyServerBuffer() {
            if (! this.editor) return;
            const next = this.$wire.config_contents ?? '';
            const current = this.editor.view.state.doc.toString();
            if (next === '' && current.trim() !== '') return;
            if (next !== current) {
                this.editor.setContent(next);
            }
        },
    }"
    x-init="init()"
    x-on:livewire:navigated.window="if (editor) { editor.destroy(); init(); }"
    x-on:livewire:update.window="applyServerBuffer()"
    x-on:config-editor-sync.window="syncToServer()"
    class="mt-2 flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-brand-ink/15 bg-white shadow-sm ring-1 ring-brand-ink/5"
>
    <div x-ref="editorMount" class="min-h-0 flex-1 overflow-hidden text-xs"></div>
</div>
