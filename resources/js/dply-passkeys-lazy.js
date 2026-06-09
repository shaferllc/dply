let passkeysLoader = null;

function dplyPasskeyButtonsPresent() {
    return Boolean(
        document.getElementById('dply-passkey-login-btn')
            || document.getElementById('dply-passkey-register-btn'),
    );
}

/**
 * WebAuthn client — login + security settings only.
 * Loaded via @vite on those pages, not from the global app bundle.
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

window.dplyEnsurePasskeys = dplyEnsurePasskeys;

dplyEnsurePasskeys().catch(() => {});

document.addEventListener('livewire:navigated', () => {
    dplyEnsurePasskeys().catch(() => {});
});
