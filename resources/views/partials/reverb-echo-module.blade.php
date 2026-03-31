{{--
    Laravel Echo + Pusher for Reverb (esm.sh). Pin versions to package.json.

    If you still see WebSocket errors from an OLD hashed app-XXXX.js (e.g. wss://localhost:8080), your
    public/build is stale: run `npm run build:fresh` (public/build is gitignored).

    For https://your-site.test without nginx: Reverb TLS on REVERB_SERVER_PORT (Valet/Herd certs when
    REVERB_HOST matches). Or use http:// + REVERB_SCHEME=http. Run `php artisan reverb:start`.
--}}
<script type="importmap">
{
    "imports": {
        "laravel-echo": "https://esm.sh/laravel-echo@2.3.1?deps=pusher-js@8.4.3",
        "pusher-js": "https://esm.sh/pusher-js@8.4.3"
    }
}
</script>
<script type="module">
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

function readPayload() {
    if (window.__DPLY_REVERB__ && typeof window.__DPLY_REVERB__.key === 'string' && window.__DPLY_REVERB__.key.trim() !== '') {
        return window.__DPLY_REVERB__;
    }
    const raw = document.querySelector('meta[name="dply-reverb-config"]')?.getAttribute('content');
    if (!raw) {
        return null;
    }
    try {
        const p = JSON.parse(raw);
        if (p && typeof p.key === 'string' && p.key.trim() !== '') {
            return p;
        }
    } catch (e) {
        return null;
    }
    return null;
}

const server = readPayload();
if (server && server.enabled !== false) {
    const scheme = String(server.scheme ?? 'http').toLowerCase() === 'https' ? 'https' : 'http';
    const forceTLS = scheme === 'https';
    const hostTrimmed = server.host != null && String(server.host).trim() !== '' ? String(server.host).trim() : '';
    const wsHost = (hostTrimmed || window.location.hostname).toLowerCase();
    let port = typeof server.port === 'number' && Number.isFinite(server.port) ? server.port : null;
    if (port == null) {
        port = scheme === 'https' ? 443 : 8080;
    }

    const pageIsHttps = window.location.protocol === 'https:';
    const isLocal = ['localhost', '127.0.0.1', '[::1]'].includes(wsHost);
    const bypass = server.bypass_local_guard === true;

    if (!bypass && pageIsHttps && !forceTLS) {
        console.info('[dply] Echo disabled: https page + Reverb http (mixed content). Proxy wss or use http:// for the app.');
    } else if (!bypass && forceTLS && isLocal) {
        console.info('[dply] Echo disabled: wss to localhost — no Valet cert for localhost. Set REVERB_HOST to your *.test site (TLS Reverb) or REVERB_SCHEME=http + http:// app.');
    } else {
        window.Pusher = Pusher;
        window.Echo = new Echo({
            broadcaster: 'reverb',
            key: String(server.key).trim(),
            wsHost,
            wsPort: port,
            wssPort: port,
            forceTLS,
            enabledTransports: ['ws', 'wss'],
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                },
            },
        });
        window.dispatchEvent(new CustomEvent('dply:echo-ready'));
    }
}
</script>
