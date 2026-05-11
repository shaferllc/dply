@props([
    'label' => '',
    'placement' => 'top',
])

{{--
    Hover/focus tooltip for icon-only buttons and similar triggers.

    Positioning: the bubble renders with `position: fixed` and Alpine computes
    its `top`/`left` from the wrapper's getBoundingClientRect(). This lets the
    tooltip escape ancestor `overflow:hidden` clipping — relevant inside the
    workspace cards which clip rounded corners. Listens for window scroll/
    resize while open so the bubble tracks the trigger.

    Scoping: every wrapper is its own Alpine root, so hovering one button
    never triggers another button's tooltip — solves the "all tooltips fire
    together" bug we hit when the cron row's <li> already used `group`.
--}}

<span
    x-data="{
        open: false,
        place: '{{ in_array($placement, ['top', 'bottom', 'left', 'right'], true) ? $placement : 'top' }}',
        position: { top: 0, left: 0 },
        compute() {
            const tip = this.$refs.tip;
            if (! tip) return;
            const r = this.$el.getBoundingClientRect();
            const tw = tip.offsetWidth || 1;
            const th = tip.offsetHeight || 1;
            const gap = 8;
            let top = 0;
            let left = 0;
            switch (this.place) {
                case 'bottom':
                    top = r.bottom + gap;
                    left = r.left + r.width / 2 - tw / 2;
                    break;
                case 'left':
                    top = r.top + r.height / 2 - th / 2;
                    left = r.left - tw - gap;
                    break;
                case 'right':
                    top = r.top + r.height / 2 - th / 2;
                    left = r.right + gap;
                    break;
                default:
                    top = r.top - th - gap;
                    left = r.left + r.width / 2 - tw / 2;
            }
            const margin = 8;
            left = Math.max(margin, Math.min(left, window.innerWidth - tw - margin));
            top = Math.max(margin, Math.min(top, window.innerHeight - th - margin));
            this.position = { top, left };
        },
        show() {
            if (! this.$refs.tip) return;
            this.open = true;
            this.$nextTick(() => this.compute());
        },
        hide() { this.open = false; },
    }"
    x-on:mouseenter="show()"
    x-on:mouseleave="hide()"
    x-on:focusin="show()"
    x-on:focusout="hide()"
    {{ $attributes->merge(['class' => 'inline-flex']) }}
>
    {{ $slot }}
    @if ($label !== '')
        <span
            x-ref="tip"
            x-show="open"
            x-cloak
            x-on:scroll.window.passive="open && compute()"
            x-on:resize.window.passive="open && compute()"
            x-bind:style="`top: ${position.top}px; left: ${position.left}px;`"
            role="tooltip"
            class="pointer-events-none fixed z-[60] whitespace-nowrap rounded-md bg-brand-ink/95 px-2 py-1 text-[11px] font-medium text-brand-cream shadow-lg ring-1 ring-brand-ink/40"
        >
            {{ $label }}
        </span>
    @endif
</span>
