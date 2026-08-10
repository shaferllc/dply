---
title: "Realtime"
slug: realtime
category: "Services"
order: 300
description: "A managed Pusher-compatible WebSocket relay. Point Laravel Echo at it, broadcast from your app, and pay per app by connection tier."
group: realtime
---

# Realtime

**Realtime** is a dply-hosted WebSocket relay that speaks the Pusher protocol. Your app broadcasts to it and your frontend subscribes with `laravel-echo`, exactly as they would with Pusher or a self-hosted Reverb — but there is no relay for you to run, scale, or keep alive.

Find it under **Services → Realtime**.

> There is no dply SDK to install. The relay speaks the Pusher wire protocol, so the official `pusher-js` and `laravel-echo` packages work unmodified.

## Creating an app

An app is the unit of tenancy: one set of credentials, one connection cap, one line on your bill.

1. Go to **Services → Realtime** and choose **New app**.
2. Pick a **connection tier** (see below).
3. Confirm the monthly charge.

Provisioning writes your credentials into the relay and takes a moment. The app is usable as soon as its status reads **Active**.

Apps can also be created for you: attaching a broadcasting binding to a site provisions one automatically.

## Connection tiers

A tier sets the maximum number of **concurrent connections** the app accepts, and its monthly price.

| Tier | Max concurrent connections | Price |
|---|---|---|
| Starter | 5,000 | $15/mo |
| Growth | 25,000 | $49/mo |
| Scale | 100,000 | $149/mo |

The cap is **enforced at the relay**, not merely billed on. Once an app is at its ceiling, further connection attempts are refused until existing ones drop. This is deliberate: a hard cap means a traffic spike costs you rejected connections, never a surprise invoice.

The app page shows peak concurrent connections so you can see how much headroom you have before a spike starts costing you connections. Change tier at any time from the app page; upgrades take effect immediately and downgrades apply on save.

Each **active** app is billed at its tier. Deleting an app removes it from your bill.

## Connecting your app

Set these in your Laravel app's `.env`. Every value is on the app's detail page.

```
BROADCAST_CONNECTION=pusher

PUSHER_APP_ID=...
PUSHER_APP_KEY=...
PUSHER_APP_SECRET=...
PUSHER_HOST=realtime.on-dply.site
PUSHER_PORT=443
PUSHER_SCHEME=https
PUSHER_APP_CLUSTER=mt1
```

For sites dply deploys, these are injected at deploy time — there is nothing to copy.

`PUSHER_APP_CLUSTER` is required by the Pusher client libraries but is not meaningful here; any value works.

### Frontend

Standard Echo configuration, no dply-specific options:

```js
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    wsHost: import.meta.env.VITE_PUSHER_HOST,
    wsPort: 443,
    wssPort: 443,
    forceTLS: true,
    enabledTransports: ['ws', 'wss'],
})
```

## Private and presence channels

Authentication is your application's job, exactly as with Pusher. dply relays the handshake; it does not decide who may join a channel. Keep your `routes/channels.php` authorization callbacks as they are.

## What is not included

- **Client events** (`whisper`) are relayed but not persisted.
- **Message history / replay** — the relay is not a log. If you need to recover missed messages, that belongs in your database.
- **Webhooks on channel occupancy** are not currently emitted.

## Troubleshooting

**Connections refused, app shows Active.** You are likely at the tier's connection ceiling. Check peak connections on the app page and move up a tier.

**Client connects then immediately drops.** Almost always a key mismatch — confirm `PUSHER_APP_KEY` in the frontend build matches the app, remembering that Vite bakes `VITE_*` values in at build time, so a changed key needs a rebuild, not just a redeploy.

**Broadcasts never arrive but no errors appear.** Check `BROADCAST_CONNECTION=pusher` is actually set in the deployed environment, and that the event implements `ShouldBroadcast`.

## Billing

Each active app bills monthly at its tier price and appears as its own line on your workspace subscription. See **Billing & plan** for the current total.
