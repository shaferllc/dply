import Webpass from '@laragear/webpass';

function passkeyErrorBox() {
    return document.getElementById('dply-passkey-error');
}

function showPasskeyError(message) {
    const el = passkeyErrorBox();
    if (!el) {
        return;
    }
    el.textContent = message;
    el.classList.remove('hidden');
}

function clearPasskeyError() {
    const el = passkeyErrorBox();
    if (!el) {
        return;
    }
    el.textContent = '';
    el.classList.add('hidden');
}

window.DplyWebAuthn = {
    isSupported() {
        return Webpass.isSupported();
    },

    /**
     * @param {string} optionsUrl
     * @param {string} registerUrl
     * @param {string} [authenticatorAttachment] When set (including ""), sent on the options request so the server can constrain WebAuthn. Omit for legacy behaviour (server/env default only).
     * @param {string} [alias] Optional friendly name stored with the credential (sent on the register request).
     */
    async register(optionsUrl, registerUrl, authenticatorAttachment, alias) {
        const useExplicitAttachment = authenticatorAttachment !== undefined;
        const trimmedAlias = typeof alias === 'string' ? alias.trim() : '';
        const registerBody = {};
        if (trimmedAlias !== '') {
            registerBody.alias = trimmedAlias;
        }

        const attestOptions = useExplicitAttachment
            ? {
                  path: optionsUrl,
                  body: { authenticator_attachment: authenticatorAttachment },
                  credentials: 'include',
              }
            : optionsUrl;

        const needsStructuredCeremony =
            useExplicitAttachment || Object.keys(registerBody).length > 0;

        const attestFinish = needsStructuredCeremony
            ? {
                  path: registerUrl,
                  credentials: 'include',
                  ...(Object.keys(registerBody).length ? { body: registerBody } : {}),
              }
            : registerUrl;

        const { success, error } = await Webpass.attest(attestOptions, attestFinish);
        if (!success) {
            throw error instanceof Error ? error : new Error(String(error ?? 'Passkey registration failed'));
        }
    },

    /**
     * @returns {Promise<{ redirect?: string, two_factor?: boolean, logged_in?: boolean }|undefined>}
     */
    async login(optionsUrl, loginUrl, email, remember) {
        clearPasskeyError();
        const headers = {};
        if (remember) {
            headers['WebAuthn-Remember'] = '1';
        }

        const { success, error, user } = await Webpass.assert(
            { path: optionsUrl, body: { email } },
            loginUrl,
            { headers },
        );

        if (!success) {
            throw error instanceof Error ? error : new Error(String(error ?? 'Passkey sign-in failed'));
        }

        return user;
    },

    showPasskeyError,
    clearPasskeyError,
};

function bindPasskeyLoginForm() {
    const btn = document.getElementById('dply-passkey-login-btn');
    if (!btn || btn.dataset.bound === '1') {
        return;
    }
    btn.dataset.bound = '1';

    btn.addEventListener('click', async () => {
        clearPasskeyError();
        const emailInput = document.getElementById('email');
        const email = emailInput?.value?.trim() ?? '';
        if (!email) {
            showPasskeyError(window.DplyWebAuthnStrings?.emailRequired ?? 'Enter your email address first.');
            emailInput?.focus();

            return;
        }

        const remember = document.getElementById('remember_me')?.checked ?? false;
        const optionsUrl = btn.dataset.optionsUrl;
        const loginUrl = btn.dataset.loginUrl;

        if (!optionsUrl || !loginUrl) {
            return;
        }

        btn.disabled = true;
        try {
            const payload = await window.DplyWebAuthn.login(optionsUrl, loginUrl, email, remember);
            if (payload?.redirect) {
                window.location.assign(payload.redirect);

                return;
            }
            showPasskeyError(window.DplyWebAuthnStrings?.unexpected ?? 'Something went wrong. Try again.');
        } catch (e) {
            const msg =
                e instanceof Error && e.message
                    ? e.message
                    : (window.DplyWebAuthnStrings?.failed ?? 'Passkey sign-in failed.');
            showPasskeyError(msg);
        } finally {
            btn.disabled = false;
        }
    });
}

function bindPasskeyRegister() {
    const btn = document.getElementById('dply-passkey-register-btn');
    const errEl = document.getElementById('dply-passkey-register-error');

    if (!btn || btn.dataset.bound === '1') {
        return;
    }

    btn.dataset.bound = '1';

    btn.addEventListener('click', async () => {
        errEl?.classList.add('hidden');
        if (!window.DplyWebAuthn?.isSupported?.()) {
            if (errEl) {
                errEl.textContent =
                    window.DplyWebAuthnStrings?.unsupported ??
                    'Passkeys are not supported in this browser.';
                errEl.classList.remove('hidden');
            }

            return;
        }

        const optionsUrl = btn.dataset.optionsUrl;
        const registerUrl = btn.dataset.registerUrl;
        if (!optionsUrl || !registerUrl) {
            return;
        }

        const attachmentChoice =
            document.querySelector('input[name="dply-passkey-attachment"]:checked')?.value ?? '';
        const aliasInput = document.getElementById('dply-passkey-alias');
        const alias = aliasInput?.value ?? '';

        btn.disabled = true;
        try {
            await window.DplyWebAuthn.register(optionsUrl, registerUrl, attachmentChoice, alias);
            window.location.reload();
        } catch (e) {
            if (errEl) {
                errEl.textContent =
                    e instanceof Error && e.message
                        ? e.message
                        : (window.DplyWebAuthnStrings?.registerFailed ?? 'Could not add passkey.');
                errEl.classList.remove('hidden');
            }
        } finally {
            btn.disabled = false;
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    bindPasskeyLoginForm();
    bindPasskeyRegister();
});
document.addEventListener('livewire:navigated', () => {
    bindPasskeyLoginForm();
    bindPasskeyRegister();
});
