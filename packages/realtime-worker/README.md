# @dply/realtime-worker

A Pusher / Laravel Reverb–compatible realtime relay that runs on Cloudflare
Workers + Durable Objects. It speaks the Pusher wire protocol, so `laravel-echo`
and `pusher-js` connect to it unchanged, and `pusher-php-server` (Laravel's
`pusher` broadcast driver) can publish to it with credentials only.

This is the data plane for dply's managed **Realtime** resource: deploy it once
to the platform Cloudflare account, then dply provisions per-customer apps into
it by writing credentials to the `APPS` KV namespace — it never re-deploys the
Worker.

## Architecture

```
laravel-echo / pusher-js ──wss──▶ GET /app/{appKey} ──▶ AppHub Durable Object
pusher-php-server ────────POST──▶ /apps/{appId}/events ─▶  (one per app id)
                                                            • channels
dply (Laravel) ──writes creds──▶ APPS KV namespace          • presence rosters
                                                            • client events
```

- **One Durable Object instance per app id** (`idFromName(appId)`) holds every
  live WebSocket for that app and fans out channel + presence messages.
- Connections use the **WebSocket Hibernation API**: per-connection state lives
  in each socket's attachment, so the DO can evict from memory and rebuild any
  view (subscribers, presence) by scanning `getWebSockets()`.
- Tenancy comes from the app key → DO routing, so channel names are the bare
  Pusher names (`private-orders`, `presence-room`) — fully Echo-compatible.

## Endpoints

| Method | Path                    | Who         | Purpose                                  |
| ------ | ----------------------- | ----------- | ---------------------------------------- |
| `GET`  | `/app/{appKey}`         | browsers    | WebSocket connect (Upgrade required)     |
| `POST` | `/apps/{appId}/events`  | app servers | Publish an event to channel(s)           |
| `GET`  | `/health`               | monitoring  | Liveness                                 |

Channel auth (private/presence) is handled by the **customer's own** app server
(standard Laravel `/broadcasting/auth`), signing with the app secret — exactly
like Pusher. The Worker only verifies those signatures; it never holds an auth
endpoint itself.

## The dply ↔ Worker contract (KV)

dply writes one JSON record per app under **two** keys so both connect and
publish can resolve it:

- `key:{appKey}` → record
- `id:{appId}`  → record

```json
{
  "id": "rt_01HX…",
  "key": "rtk_AbC123…",
  "secret": "rts_…",
  "enabled": true,
  "maxConnections": 1000
}
```

To deprovision, delete both keys (or set `enabled: false` to hard-stop new
connections + publishes immediately).

## Publish auth

`POST /apps/{appId}/events` accepts either:

1. **dply header auth** (simple): `X-Dply-Key` + `X-Dply-Secret`.
2. **Pusher REST signature** (drop-in for `pusher-php-server`):
   `?auth_key&auth_timestamp&auth_signature&body_md5`, signed as
   `HMAC_SHA256(secret, "POST\n/apps/{id}/events\n{sorted query}")`.

Body shape (Pusher-compatible):

```json
{ "name": "OrderShipped", "channels": ["private-orders"], "data": { "id": 42 }, "socket_id": "123.456" }
```

## Connecting from a customer app (Laravel Echo)

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
  broadcaster: 'pusher',
  key: import.meta.env.VITE_DPLY_REALTIME_KEY, // the app key
  wsHost: 'realtime.on-dply.site',
  wsPort: 443,
  wssPort: 443,
  forceTLS: true,
  enabledTransports: ['ws', 'wss'],
  cluster: 'mt1', // ignored by the relay; pusher-js requires a value
});
```

Server side, point Laravel's `pusher` broadcaster at the relay (host +
key/secret) and it publishes over the Pusher REST signature path above.

## Develop

```bash
npm install
npm test          # vitest: md5 + auth (known HMAC/MD5 vectors)
npx tsc --noEmit  # typecheck
npm run dev       # wrangler dev (local DO + KV)
```

## Deploy (operator, once)

1. Create a KV namespace, put its id in `wrangler.toml` (`APPS` binding).
2. `npm run deploy`.
3. Add a route for `realtime.on-dply.site/*` (or your chosen host).
4. Point dply at it via `config/realtime.php` (see the dply app).
