@props([
    'align' => 'right',
    'label' => null,
    // Render the panel with position:fixed, positioned from the trigger's
    // bounding rect, instead of absolutely inside this element. Needed when an
    // ancestor clips — e.g. a `dply-card` with `overflow-hidden`, which crops an
    // absolutely-positioned panel to the card's bounds. Opt-in so existing
    // callers keep the cheaper absolute path.
    'fixed' => false,
])

{{-- Compact kebab "more actions" menu for crowded row action groups: keep the
     one or two primary actions inline next to this, and slot the rest in here so
     a row never shows more than a couple of buttons. Items are plain buttons /
     anchors styled with `dply-overflow-item`; the menu closes on any item click
     (the content wrapper's @click) and on outside click. --}}
<div
    class="relative"
    x-data="{
        open: false,
        anchored: @js((bool) $fixed),
        panelStyle: '',
        toggle() {
            this.open = ! this.open
            if (this.open && this.anchored) { this.$nextTick(() => this.place()) }
        },
        place() {
            if (! this.anchored || ! this.$refs.trigger) { return }
            const r = this.$refs.trigger.getBoundingClientRect()
            const w = this.$refs.panel?.offsetWidth || 192
            const h = this.$refs.panel?.offsetHeight || 0
            let left = @js($align) === 'left' ? r.left : r.right - w
            // Keep it on screen on narrow viewports.
            left = Math.max(8, Math.min(left, window.innerWidth - w - 8))
            // Flip above the trigger when there is no room below.
            let top = r.bottom + 4
            if (h && top + h > window.innerHeight - 8) { top = Math.max(8, r.top - h - 4) }
            this.panelStyle = `position:fixed; top:${top}px; left:${left}px;`
        },
    }"
    x-on:scroll.window="open && anchored && place()"
    x-on:resize.window="open && anchored && place()"
    {{ $attributes }}
>
    <button
        type="button"
        x-ref="trigger"
        x-on:click="toggle()"
        x-bind:aria-expanded="open"
        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/10 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 dply-focus dply-pressable"
        title="{{ $label ?? __('More actions') }}"
        aria-label="{{ $label ?? __('More actions') }}"
    >
        <x-heroicon-o-ellipsis-horizontal class="h-4 w-4" aria-hidden="true" />
    </button>

    <div
        x-ref="panel"
        x-show="open"
        x-cloak
        x-on:click.outside="open = false"
        x-on:click="open = false"
        x-transition
        x-bind:style="anchored ? panelStyle : null"
        @class([
            'w-48 overflow-hidden rounded-xl border border-brand-ink/10 bg-white py-1 text-left shadow-lg',
            // Absolute inside this element, unless the caller opted into fixed
            // because an ancestor clips.
            'absolute mt-1 z-30 '.($align === 'left' ? 'left-0' : 'right-0') => ! $fixed,
            // z-50 so a fixed panel sits above sticky headers and card chrome.
            'z-50' => $fixed,
        ])
    >
        {{ $slot }}
    </div>
</div>
