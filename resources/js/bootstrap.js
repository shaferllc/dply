import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.Pusher = Pusher;

const rawReverbKey = import.meta.env.VITE_REVERB_APP_KEY;
const reverbKey = typeof rawReverbKey === 'string' ? rawReverbKey.trim() : '';

/**
 * Whether Echo should connect. Skips known-broken combos so the console is not spammed.
 *
 * - https page + REVERB_SCHEME=http → browsers block ws:// (mixed content).
 * - https page + wss + Reverb on localhost → Reverb has no TLS by default, so wss fails.
 *
 * Fix: use http:// for local app URL with REVERB_SCHEME=http, or terminate TLS / proxy wss.
 * Opt out: VITE_REVERB_ENABLED=false, or VITE_REVERB_BYPASS_LOCAL_GUARD=true (dev only).
 */
function shouldInitializeReverbEcho() {
    if (!reverbKey) {
        return false;
    }

    if (import.meta.env.VITE_REVERB_ENABLED === 'false') {
        return false;
    }

    const scheme = (import.meta.env.VITE_REVERB_SCHEME ?? 'http').toLowerCase();
    const forceTLS = scheme === 'https';
    const pageIsHttps = window.location.protocol === 'https:';
    const wsHost = (import.meta.env.VITE_REVERB_HOST ?? window.location.hostname).toLowerCase();
    const isLocalReverbHost = ['localhost', '127.0.0.1', '[::1]'].includes(wsHost);
    const bypass = import.meta.env.VITE_REVERB_BYPASS_LOCAL_GUARD === 'true';

    if (bypass || !import.meta.env.DEV) {
        return true;
    }

    if (pageIsHttps && !forceTLS) {
        console.info(
            '[dply] Echo disabled: page is https but REVERB_SCHEME is http (ws:// blocked as mixed content). Use http:// locally or set REVERB_SCHEME=https with a TLS-capable Reverb endpoint.',
        );
        return false;
    }

    if (pageIsHttps && forceTLS && isLocalReverbHost) {
        console.info(
            '[dply] Echo disabled: wss to localhost Reverb usually fails (no TLS on `php artisan reverb:start`). Use http:// + REVERB_SCHEME=http, proxy wss through your vhost, or set VITE_REVERB_BYPASS_LOCAL_GUARD=true to try anyway.',
        );
        return false;
    }

    return true;
}

if (shouldInitializeReverbEcho()) {
    // Match php artisan reverb:start: plain WebSocket (no TLS) on 8080 by default.
    const scheme = (import.meta.env.VITE_REVERB_SCHEME ?? 'http').toLowerCase();
    const forceTLS = scheme === 'https';
    const defaultPort = forceTLS ? '443' : '8080';
    const port = String(import.meta.env.VITE_REVERB_PORT ?? defaultPort);

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS,
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN':
                    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
            },
        },
    });
}

/**
 * Subscribe to org-scoped server updates (Reverb) and refresh Livewire when provisioning changes.
 */
function bindDplyOrganizationServerChannel() {
    if (!window.Echo || typeof window.Livewire === 'undefined') {
        return;
    }

    const el = document.getElementById('dply-broadcast-context');
    const orgId = el?.dataset?.organizationId?.trim() ?? '';

    if (!orgId) {
        if (window.__dplyOrgEchoSub) {
            window.Echo.leave(`organization.${window.__dplyOrgEchoSub}`);
            window.__dplyOrgEchoSub = null;
        }
        return;
    }

    if (window.__dplyOrgEchoSub === orgId) {
        return;
    }

    if (window.__dplyOrgEchoSub) {
        window.Echo.leave(`organization.${window.__dplyOrgEchoSub}`);
    }

    window.__dplyOrgEchoSub = orgId;

    window.Echo.private(`organization.${orgId}`).listen('.server.state.updated', (payload) => {
        window.Livewire.dispatch('server-state-updated', {
            organizationId: payload.organization_id,
            action: payload.action,
            serverId: payload.server_id,
            server: payload.server,
        });
    });
}

document.addEventListener('livewire:init', () => {
    bindDplyOrganizationServerChannel();
});

document.addEventListener('livewire:navigated', () => {
    bindDplyOrganizationServerChannel();
});
