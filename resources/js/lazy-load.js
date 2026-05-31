let passkeysLoader = null;

function dplyPasskeyButtonsPresent() {
    return Boolean(
        document.getElementById('dply-passkey-login-btn')
            || document.getElementById('dply-passkey-register-btn'),
    );
}

/**
 * WebAuthn client — login + security settings only.
 */
export function dplyEnsurePasskeys() {
    if (!dplyPasskeyButtonsPresent()) {
        return Promise.resolve();
    }

    if (!passkeysLoader) {
        passkeysLoader = import('./dply-passkeys.js');
    }

    return passkeysLoader;
}

let docsProseLoader = null;

/**
 * Docs spec/compare card styles — slide-over panel + full /docs pages.
 */
export function dplyEnsureDocsProseStyles() {
    if (!docsProseLoader) {
        docsProseLoader = import('./lazy/docs-prose.js');
    }

    return docsProseLoader;
}

function dplyMaybeLoadPasskeys() {
    dplyEnsurePasskeys().catch(() => {});
}

function dplyMaybeLoadDocsProseStyles() {
    if (
        document.querySelector('.docs-markdown-prose, .docs-sidebar-prose')
    ) {
        dplyEnsureDocsProseStyles().catch(() => {});
    }
}

export function registerDplyLazyAssetListeners() {
    document.addEventListener('DOMContentLoaded', () => {
        dplyMaybeLoadPasskeys();
        dplyMaybeLoadDocsProseStyles();
    });

    document.addEventListener('livewire:navigated', () => {
        dplyMaybeLoadPasskeys();
        dplyMaybeLoadDocsProseStyles();
    });

    window.addEventListener('dply-docs-open', () => {
        dplyEnsureDocsProseStyles().catch(() => {});
    });
}
