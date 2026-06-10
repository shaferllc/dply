@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1.5'])

@php
// A width like "24rem"/"384px" is applied as an inline style so it never
// depends on whether that arbitrary Tailwind class made it into the build.
// Anything else is treated as a utility class (e.g. "w-64", "48" => "w-48").
$isCssWidth = (bool) preg_match('/^\d+(\.\d+)?(rem|px|em|%|vw)$/', (string) $width);
$widthClass = $isCssWidth ? '' : match ($width) {
    '48' => 'w-48',
    default => $width,
};
$widthStyle = $isCssWidth ? "width: {$width};" : '';
@endphp

{{--
    Menus teleport to <body> with fixed positioning so they are not clipped by
    workspace cards, table rows, or tab panels that use overflow-hidden.
    See components/tooltip.blade.php for the same pattern.
--}}
<div
    class="relative inline-flex"
    x-data="{
        open: false,
        uid: 'dd-' + Math.random().toString(36).slice(2),
        align: @js($align),
        position: { top: 0, left: 0 },
        compute() {
            const menu = this.$refs.menu;
            const trigger = this.$refs.triggerWrap;
            if (! menu || ! trigger) {
                return;
            }
            const r = trigger.getBoundingClientRect();
            const mw = menu.offsetWidth || 1;
            const mh = menu.offsetHeight || 1;
            const gap = 8;
            let top = r.bottom + gap;
            let left = r.left;
            if (this.align === 'right') {
                left = r.right - mw;
            } else if (this.align === 'left') {
                left = r.left;
            } else if (this.align === 'top') {
                top = r.top - mh - gap;
                left = r.left + (r.width / 2) - (mw / 2);
            }
            const margin = 8;
            left = Math.max(margin, Math.min(left, window.innerWidth - mw - margin));
            top = Math.max(margin, Math.min(top, window.innerHeight - mh - margin));
            this.position = { top, left };
        },
        toggle() {
            this.open = ! this.open;
            if (this.open) {
                // Let every other dropdown know to close; they compare against our root element.
                window.dispatchEvent(new CustomEvent('dropdown-open', { detail: this.uid }));
                this.$nextTick(() => this.compute());
            }
        },
        close() {
            this.open = false;
        },
    }"
    @@click.outside="close()"
    @@close.stop="close()"
    @@dropdown-open.window="$event.detail !== uid && close()"
>
    <div x-ref="triggerWrap" @@click.stop="toggle()">
        {{ $trigger }}
    </div>

    <template x-teleport="body">
        <div
            x-ref="menu"
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-[0.98] translate-y-0.5"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-[0.98]"
            @@click="close()"
            @@scroll.window.passive="open && compute()"
            @@resize.window.passive="open && compute()"
            x-bind:style="`top: ${position.top}px; left: ${position.left}px;`"
            class="fixed z-[80] {{ $widthClass }}"
            style="display: none;"
        >
            {{-- Width lives on the panel, not the positioned wrapper above: Alpine's
                 x-bind:style rewrites the wrapper's style for top/left, which would
                 strip an inline width. The fixed wrapper shrink-wraps to this panel. --}}
            <div class="dply-dropdown-panel {{ $contentClasses }}" @style([$widthStyle])>
                {{ $content }}
            </div>
        </div>
    </template>
</div>
