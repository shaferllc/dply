// ============================================================================
// Global feedback / bug-report capture.
//
// Two responsibilities:
//
//   1. A always-on console-error RING BUFFER so that when a user opens the
//      feedback sidebar to report a bug, we can attach the last N JS errors /
//      warnings / unhandled rejections that led up to it. Installed at import
//      time (before Alpine) so it catches errors from the very first paint.
//
//   2. An Alpine component (`dplyFeedbackSidebar`) that drives the slide-over:
//      page-screenshot capture (lazy-loaded modern-screenshot from CDN, with a
//      deny-by-default redaction filter so on-screen secrets / SSH output never
//      get rasterised), then hands the screenshot + console buffer + page
//      context to the Livewire component right before it submits.
//
// Screenshot capture is best-effort and NON-BLOCKING: a failed/slow capture
// must never stop the report from being filed.
// ============================================================================

const CONSOLE_MAX_ENTRIES = 50;
const CONSOLE_MAX_BYTES = 32 * 1024;
const SCREENSHOT_CDN = 'https://cdn.jsdelivr.net/npm/modern-screenshot@4/+esm';

// ---------------------------------------------------------------------------
// 1. Console ring buffer
// ---------------------------------------------------------------------------

const consoleBuffer = [];

function pushConsoleEntry(level, message) {
    try {
        consoleBuffer.push({
            level,
            message: String(message).slice(0, 2000),
            at: new Date().toISOString(),
        });
        while (consoleBuffer.length > CONSOLE_MAX_ENTRIES) {
            consoleBuffer.shift();
        }
    } catch (_) {
        /* never let instrumentation throw */
    }
}

function snapshotConsoleBuffer() {
    // Trim from the oldest end until the serialized payload fits the byte cap —
    // mirrors the server-side ceiling so we don't ship something it'll reject.
    let entries = consoleBuffer.slice();
    let serialized = JSON.stringify(entries);
    while (serialized.length > CONSOLE_MAX_BYTES && entries.length > 1) {
        entries = entries.slice(1);
        serialized = JSON.stringify(entries);
    }
    return entries;
}

export function installFeedbackConsoleBuffer() {
    if (window.__dplyFeedbackConsoleInstalled) {
        return;
    }
    window.__dplyFeedbackConsoleInstalled = true;

    const origError = console.error.bind(console);
    const origWarn = console.warn.bind(console);

    console.error = (...args) => {
        pushConsoleEntry('error', args.map(stringifyArg).join(' '));
        origError(...args);
    };
    console.warn = (...args) => {
        pushConsoleEntry('warn', args.map(stringifyArg).join(' '));
        origWarn(...args);
    };

    window.addEventListener('error', (e) => {
        const where = e.filename ? ` (${e.filename}:${e.lineno || 0})` : '';
        pushConsoleEntry('error', `${e.message || 'Uncaught error'}${where}`);
    });

    window.addEventListener('unhandledrejection', (e) => {
        const reason = e.reason && e.reason.message ? e.reason.message : e.reason;
        pushConsoleEntry('error', `Unhandled promise rejection: ${stringifyArg(reason)}`);
    });

    window.dplyFeedbackConsole = { snapshot: snapshotConsoleBuffer };
}

function stringifyArg(arg) {
    if (arg instanceof Error) {
        return `${arg.name}: ${arg.message}`;
    }
    if (typeof arg === 'object') {
        try {
            return JSON.stringify(arg);
        } catch (_) {
            return '[object]';
        }
    }
    return String(arg);
}

// ---------------------------------------------------------------------------
// 2. Screenshot capture (with redaction) + Alpine sidebar driver
// ---------------------------------------------------------------------------

let screenshotLib = null;

async function loadScreenshotLib() {
    if (screenshotLib) {
        return screenshotLib;
    }
    // Loaded from CDN on demand (same pattern as Plotly) so it stays out of the
    // main bundle. @vite-ignore keeps Vite from trying to resolve the URL.
    screenshotLib = await import(/* @vite-ignore */ SCREENSHOT_CDN);
    return screenshotLib;
}

// Deny-by-default: anything tagged for redaction, plus known-sensitive surfaces
// (secret/env fields, the SSH console drawer, password inputs) is dropped from
// the capture entirely rather than risking a leak of customer secrets.
const REDACT_SELECTORS = [
    '[data-feedback-redact]',
    '[data-sensitive]',
    'input[type="password"]',
    '.dply-feedback-sidebar', // never screenshot the feedback panel itself
];

function shouldRedact(node) {
    if (!node || node.nodeType !== 1 || typeof node.matches !== 'function') {
        return false;
    }
    try {
        return REDACT_SELECTORS.some((sel) => node.matches(sel));
    } catch (_) {
        return false;
    }
}

export async function captureFeedbackScreenshot() {
    try {
        const { domToWebp } = await loadScreenshotLib();
        const width = document.documentElement.clientWidth || window.innerWidth;
        // Downscale wide viewports toward ~1600px to bound payload size.
        const scale = width > 1600 ? 1600 / width : 1;

        return await domToWebp(document.body, {
            quality: 0.82,
            scale,
            backgroundColor: getComputedStyle(document.body).backgroundColor || '#ffffff',
            filter: (node) => !shouldRedact(node),
        });
    } catch (e) {
        console.warn('Feedback screenshot capture failed (continuing without it):', e);
        return null;
    }
}

function collectPageContext() {
    return {
        url: window.location.href,
        path: window.location.pathname,
        title: document.title,
        user_agent: navigator.userAgent,
        language: navigator.language,
        viewport: { width: window.innerWidth, height: window.innerHeight },
        screen: { width: window.screen?.width, height: window.screen?.height },
        device_pixel_ratio: window.devicePixelRatio,
        app_version: window.__DPLY_VERSION__ || null,
        captured_at: new Date().toISOString(),
    };
}

export function registerFeedbackSidebar(Alpine) {
    Alpine.data('dplyFeedbackSidebar', () => ({
        open: false,
        includeScreenshot: true,
        busy: false,

        toggle() {
            this.open = !this.open;
        },
        close() {
            this.open = false;
        },

        async submitWithCapture() {
            if (this.busy) {
                return;
            }
            this.busy = true;
            try {
                let screenshot = null;
                if (this.includeScreenshot) {
                    screenshot = await captureFeedbackScreenshot();
                }

                // Defer-set (false) so we don't trigger a render per call; the
                // submit() round-trip below carries them all to the server.
                await this.$wire.set('screenshotData', screenshot, false);
                await this.$wire.set(
                    'consoleBuffer',
                    JSON.stringify(window.dplyFeedbackConsole?.snapshot() ?? []),
                    false,
                );
                await this.$wire.set('pageContext', JSON.stringify(collectPageContext()), false);

                await this.$wire.submit();
            } finally {
                this.busy = false;
            }
        },

        init() {
            // Livewire tells us when a report was filed OK so we can close + reset.
            this.$wire.on('feedback-submitted', () => {
                this.open = false;
            });
        },
    }));
}
