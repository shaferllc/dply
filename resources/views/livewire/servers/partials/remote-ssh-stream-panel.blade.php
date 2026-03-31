@php
    $logViewportLines = max(2, min(50, (int) ($logViewportLines ?? 18)));
    $logLineHeightRem = 1.35;
    /** Drawer stays compact: cap lines and height so it never covers the workspace. */
    $drawerMaxLines = 5;
    $drawerLines = min($logViewportLines, $drawerMaxLines);
    $logViewportHeightRem = min($drawerLines * $logLineHeightRem, 8);
@endphp
{{-- wire:ignore: Livewire morphs were destroying Alpine on every update (e.g. log filter), so the drawer never stayed open. Streams still target nodes inside ignore. --}}
<div wire:ignore>
    <div
        x-data="{
            open: false,
            storageKey: 'dply-live-session-drawer-open',
            init() {
                try {
                    if (localStorage.getItem(this.storageKey) === '1') {
                        this.open = true;
                    }
                } catch (e) {}
            },
            toggle() {
                this.open = !this.open;
                try {
                    localStorage.setItem(this.storageKey, this.open ? '1' : '0');
                } catch (e) {}
            },
            close() {
                this.open = false;
                try {
                    localStorage.setItem(this.storageKey, '0');
                } catch (e) {}
            },
        }"
        @keydown.escape.window="if (open) close()"
        class="fixed bottom-0 right-0 z-30 flex w-[min(100vw-0.75rem,13rem)] flex-col pr-1.5 pb-[max(0.35rem,env(safe-area-inset-bottom,0px))] pl-0.5 sm:w-[14rem] sm:pr-2 sm:pl-1"
        aria-label="{{ __('Live session') }}"
    >
        <div
            class="flex max-h-[min(22vh,10rem)] min-h-0 flex-col overflow-hidden rounded-tl-lg border border-b-0 border-zinc-300 bg-white shadow-md shadow-zinc-900/10"
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-2 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-y-0 opacity-100"
            x-transition:leave-end="translate-y-2 opacity-0"
        >
            <div class="flex min-h-0 flex-1 flex-col gap-1.5 overflow-hidden p-2">
                <p class="shrink-0 text-[10px] leading-snug text-brand-moss">{{ __('Streamed output while the request runs.') }}</p>
                <div
                    wire:ignore
                    wire:stream.replace="remoteSshMeta"
                    class="max-h-14 shrink-0 overflow-x-auto overflow-y-auto overscroll-contain rounded border border-brand-ink/15 bg-brand-sand/40 p-1.5 text-[10px] text-brand-ink [scrollbar-color:rgba(15,118,110,0.45)_transparent] [&_pre]:!text-brand-ink [&_pre]:text-[10px] [&_pre]:leading-snug"
                ></div>
                <div
                    class="min-h-0 flex-1 overflow-hidden"
                    style="--remote-log-viewport: {{ $logViewportHeightRem }}rem; --remote-log-line-height: {{ $logLineHeightRem }}rem"
                >
                    <pre
                        wire:ignore
                        wire:stream="remoteSshOut"
                        style="height: var(--remote-log-viewport); line-height: var(--remote-log-line-height); color: #166534; background-color: #fafafa;"
                        class="box-border max-h-[8rem] min-h-0 overflow-y-auto rounded border border-zinc-300 px-2 py-1.5 font-mono text-[10px] leading-snug whitespace-pre-wrap break-all [scrollbar-color:rgb(82 82 91 / 0.45)_transparent]"
                    ></pre>
                </div>
            </div>
        </div>

        <button
            type="button"
            @click.prevent="toggle()"
            class="flex w-full items-center justify-between gap-1.5 border border-zinc-300 bg-zinc-100 px-2 py-1.5 text-left text-[10px] font-semibold uppercase tracking-wide shadow-sm hover:bg-zinc-200/90"
            x-bind:class="open ? 'rounded-none border-t-0' : 'rounded-tl-lg border-b-zinc-200'"
            x-bind:aria-expanded="open"
        >
            <span class="truncate text-zinc-700">{{ __('Live') }}</span>
            <span class="inline-flex transition-transform duration-200" x-bind:class="open ? 'rotate-180' : ''">
                <x-heroicon-o-chevron-up class="h-3 w-3 shrink-0 text-zinc-600" />
            </span>
        </button>
    </div>
</div>
