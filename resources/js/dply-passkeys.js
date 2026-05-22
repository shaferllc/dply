/**
 * Bridge from dply's existing button IDs to the @laravel/passkeys browser client.
 * Replaces the old @laragear/webpass driver — same DOM hooks, new package under the hood.
 *
 * Login binds to:
 *   #dply-passkey-login-btn — clicking calls Passkeys.verify() and follows the redirect
 *   #dply-passkey-error      — error sink shown on failure
 *
 * Register (settings/security) binds to:
 *   #dply-passkey-register-btn       — clicking starts the WebAuthn registration ceremony
 *   #dply-passkey-alias              — friendly label saved with the passkey
 *   #dply-passkey-register-error     — error sink shown on failure
 *
 * The old "authenticator attachment" radio + email/remember bridge are gone — the new
 * package handles those through its own login/register options endpoint and conditional
 * UI mediation. The blades have been simplified accordingly.
 */
import { Passkeys } from '@laravel/passkeys';

function showError(elId, message) {
    const el = document.getElementById(elId);
    if (!el) {
        return;
    }
    el.textContent = message;
    el.classList.remove('hidden');
}

function clearError(elId) {
    const el = document.getElementById(elId);
    if (!el) {
        return;
    }
    el.textContent = '';
    el.classList.add('hidden');
}

function bindPasskeyLogin() {
    const btn = document.getElementById('dply-passkey-login-btn');
    if (!btn || btn.dataset.bound === '1') {
        return;
    }
    btn.dataset.bound = '1';

    btn.addEventListener('click', async () => {
        clearError('dply-passkey-error');

        if (!Passkeys.isSupported()) {
            showError('dply-passkey-error', 'Passkeys are not supported in this browser.');
            return;
        }

        btn.disabled = true;
        try {
            const response = await Passkeys.verify();
            if (response?.redirect) {
                window.location.assign(response.redirect);
                return;
            }
            // No redirect target → reload so middleware can decide where to send the user.
            window.location.reload();
        } catch (e) {
            const msg = e instanceof Error && e.message ? e.message : 'Passkey sign-in failed.';
            showError('dply-passkey-error', msg);
        } finally {
            btn.disabled = false;
        }
    });
}

function bindPasskeyRegister() {
    const btn = document.getElementById('dply-passkey-register-btn');
    if (!btn || btn.dataset.bound === '1') {
        return;
    }
    btn.dataset.bound = '1';

    btn.addEventListener('click', async () => {
        clearError('dply-passkey-register-error');

        if (!Passkeys.isSupported()) {
            showError('dply-passkey-register-error', 'Passkeys are not supported in this browser.');
            return;
        }

        const aliasInput = document.getElementById('dply-passkey-alias');
        const name = (aliasInput?.value ?? '').trim() || 'Passkey';

        btn.disabled = true;
        try {
            await Passkeys.register({ name });
            window.location.reload();
        } catch (e) {
            const msg = e instanceof Error && e.message ? e.message : 'Could not add passkey.';
            showError('dply-passkey-register-error', msg);
        } finally {
            btn.disabled = false;
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    bindPasskeyLogin();
    bindPasskeyRegister();
});

document.addEventListener('livewire:navigated', () => {
    bindPasskeyLogin();
    bindPasskeyRegister();
});
