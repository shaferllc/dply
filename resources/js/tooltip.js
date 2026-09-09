/**
 * Global hover/focus tooltips.
 *
 * One bubble, appended to <body> once and reused, driven by delegated listeners
 * on `document` — so it survives every Livewire morph and needs no per-element
 * Alpine root. Two ways an element opts in:
 *
 *   • `data-tooltip="…"` — explicit, always shown.
 *   • `title="…"` on an element whose text is actually clipped (truncate /
 *     line-clamp). The native tooltip is slow, unstyled, and cut off at the
 *     viewport edge, which is exactly the "I can't read the description" case.
 *     We hoist the title into the styled bubble while hovered and restore it on
 *     leave, so copy/paste and a11y tooling keep working.
 *
 * Positioning is `position: fixed` against the trigger's viewport rect, flipped
 * to the other side when the preferred side has no room and clamped to the
 * viewport. Living directly under <body> means no ancestor `transform`/`filter`
 * (workspace cards, transitions) can clip it.
 *
 * Text only — content is written with textContent, never innerHTML, so a note
 * carrying user data can't inject markup.
 *
 * @see resources/views/components/tooltip.blade.php for the Alpine component
 *      used by icon-only buttons; this engine covers everything else.
 */

const SHOW_DELAY = 140;
const HIDE_DELAY = 80;
const GAP = 8;
const MARGIN = 8;

let bubble = null;
let activeTrigger = null;
let showTimer = null;
let hideTimer = null;

/** Elements whose native `title` we removed while showing the bubble. */
const hoistedTitles = new WeakMap();

function ensureBubble() {
    if (bubble && bubble.isConnected) {
        return bubble;
    }

    bubble = document.createElement('div');
    bubble.className = 'dply-tooltip';
    bubble.setAttribute('role', 'tooltip');
    bubble.dataset.show = 'false';
    document.body.appendChild(bubble);

    return bubble;
}

/** Is this element's text visually clipped by truncate / line-clamp? */
function isClipped(el) {
    return el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1;
}

/**
 * Resolve the nearest opted-in ancestor and the text to show.
 *
 * @returns {{el: HTMLElement, text: string, placement: string, fromTitle: boolean}|null}
 */
function resolveTrigger(target) {
    if (! (target instanceof Element)) {
        return null;
    }

    const explicit = target.closest('[data-tooltip]');
    if (explicit) {
        const text = (explicit.getAttribute('data-tooltip') || '').trim();
        if (text !== '') {
            return {
                el: explicit,
                text,
                placement: explicit.getAttribute('data-tooltip-placement') || 'top',
                fromTitle: false,
            };
        }
    }

    // `[title]` only wins when the text is genuinely cut off — a title on a
    // fully visible element is a deliberate native affordance, leave it alone.
    const titled = target.closest('[title]');
    if (titled && titled.getAttribute('title').trim() !== '' && isClipped(titled)) {
        return {
            el: titled,
            text: titled.getAttribute('title').trim(),
            placement: titled.getAttribute('data-tooltip-placement') || 'bottom',
            fromTitle: true,
        };
    }

    return null;
}

function position(trigger, placement) {
    const tip = ensureBubble();
    const r = trigger.getBoundingClientRect();
    const tw = tip.offsetWidth;
    const th = tip.offsetHeight;

    // Flip when the preferred side can't fit the bubble.
    let side = placement;
    if (side === 'top' && r.top - th - GAP < MARGIN) side = 'bottom';
    else if (side === 'bottom' && r.bottom + th + GAP > window.innerHeight - MARGIN) side = 'top';
    else if (side === 'left' && r.left - tw - GAP < MARGIN) side = 'right';
    else if (side === 'right' && r.right + tw + GAP > window.innerWidth - MARGIN) side = 'left';

    let top;
    let left;
    switch (side) {
        case 'bottom':
            top = r.bottom + GAP;
            left = r.left + r.width / 2 - tw / 2;
            break;
        case 'left':
            top = r.top + r.height / 2 - th / 2;
            left = r.left - tw - GAP;
            break;
        case 'right':
            top = r.top + r.height / 2 - th / 2;
            left = r.right + GAP;
            break;
        default:
            top = r.top - th - GAP;
            left = r.left + r.width / 2 - tw / 2;
    }

    left = Math.max(MARGIN, Math.min(left, window.innerWidth - tw - MARGIN));
    top = Math.max(MARGIN, Math.min(top, window.innerHeight - th - MARGIN));

    tip.style.top = `${Math.round(top)}px`;
    tip.style.left = `${Math.round(left)}px`;
}

function show({ el, text, placement, fromTitle }) {
    const tip = ensureBubble();

    if (fromTitle) {
        // Suppress the native bubble for as long as ours is up.
        hoistedTitles.set(el, text);
        el.removeAttribute('title');
    }

    activeTrigger = { el, placement };
    tip.textContent = text;
    tip.dataset.show = 'true';
    position(el, placement);
}

function hide() {
    clearTimeout(showTimer);

    if (activeTrigger) {
        const stored = hoistedTitles.get(activeTrigger.el);
        if (stored !== undefined) {
            activeTrigger.el.setAttribute('title', stored);
            hoistedTitles.delete(activeTrigger.el);
        }
    }

    activeTrigger = null;

    if (bubble) {
        bubble.dataset.show = 'false';
        bubble.textContent = '';
    }
}

function scheduleShow(match) {
    clearTimeout(hideTimer);
    clearTimeout(showTimer);

    if (activeTrigger && activeTrigger.el === match.el) {
        return;
    }

    showTimer = setTimeout(() => {
        // Re-check: the row may have been morphed away during the delay.
        if (match.el.isConnected) {
            hide();
            show(match);
        }
    }, SHOW_DELAY);
}

function scheduleHide() {
    clearTimeout(showTimer);
    clearTimeout(hideTimer);
    hideTimer = setTimeout(hide, HIDE_DELAY);
}

export function registerDplyTooltips() {
    if (window.dplyTooltipsRegistered) {
        return;
    }
    window.dplyTooltipsRegistered = true;

    document.addEventListener('mouseover', (e) => {
        // Still inside the open trigger. This guard is load-bearing for the
        // hoisted-title path: we removed the `title` to kill the native bubble,
        // so resolveTrigger() can no longer match that element and every
        // mouseover on a child would otherwise read as "left the trigger".
        if (activeTrigger && e.target instanceof Node && activeTrigger.el.contains(e.target)) {
            clearTimeout(hideTimer);

            return;
        }

        const match = resolveTrigger(e.target);
        if (match) {
            scheduleShow(match);
        } else if (activeTrigger) {
            scheduleHide();
        }
    });

    document.addEventListener('mouseout', (e) => {
        if (activeTrigger && ! activeTrigger.el.contains(e.relatedTarget)) {
            scheduleHide();
        }
    });

    // Keyboard parity: the same note reachable by tabbing to the element.
    document.addEventListener('focusin', (e) => {
        const match = resolveTrigger(e.target);
        if (match) {
            scheduleShow(match);
        }
    });
    document.addEventListener('focusout', () => scheduleHide());

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') hide();
    });

    // A click means the user is doing something else now.
    document.addEventListener('click', () => hide(), true);

    // Follow the trigger while the page moves; drop it if it scrolled away.
    const track = () => {
        if (! activeTrigger) return;
        if (! activeTrigger.el.isConnected) {
            hide();

            return;
        }
        position(activeTrigger.el, activeTrigger.placement);
    };
    window.addEventListener('scroll', track, { passive: true, capture: true });
    window.addEventListener('resize', track, { passive: true });

    // Livewire morphs can replace the hovered element mid-hover.
    document.addEventListener('livewire:navigated', hide);
}
