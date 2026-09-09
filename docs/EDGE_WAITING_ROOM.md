---
title: "Edge waiting room"
slug: edge-waiting-room
category: "Edge"
order: 114
description: "Queue excess visitors during launches so active traffic stays within a safe cap. Configure admit rate, session length, and paths."
group: edge
---

# Edge waiting room

**Waiting room** queues excess visitors during launches or flash traffic so your site stays up instead of melting under a stampede.

Requires **Dply-hosted Edge delivery**.

## Where do visitors wait?

**On the same Edge URL they opened** — there is no separate queue hostname or email hold.

1. Someone requests a **protected path** on your Edge site (for example `https://your-site.on-dply.site/checkout`).
2. If capacity remains (under **max active** and **admits / minute**), Edge sets a short session cookie and serves your normal page.
3. If the room is full, Edge returns a simple **“You’re in line”** HTML page on that same URL (HTTP **503**). The page auto-refreshes every few seconds until a slot opens.
4. After **session** minutes expire, the cookie ends and they may re-queue on the next visit.

The wait page is served by the Edge worker (not your build). Custom branding of that interstitial is not available yet.

## Settings

| Setting | Purpose |
|---------|---------|
| **Max active visitors** | How many admitted browsers can browse at once |
| **New admits / minute** | How fast the queue drains into the site |
| **Session (minutes)** | Cookie lifetime after admit; then they may re-queue |
| **Protected paths** | One pattern per line (e.g. `/`, `/checkout/*`). Empty defaults to `/*` (entire site). |

## How to use it

No third-party account is required — configure everything in Dply (**Access → Waiting room**).

1. Before a launch, set a conservative **max active** and **admits / minute**.
2. List **paths** that should use the room. Prefer `/checkout` or `/launch` over site-wide `/*` when you can; keep static marketing pages outside the list so people can read while others queue.
3. Enable and **Save** before traffic spikes (delivery republishes in under a minute).
4. Raise the cap once the room drains cleanly; turn off when the event ends.

## Tips

- Start low and raise — easier than recovering from an overload.
- Marketing landing pages can stay outside the path list so people can still read while waiting.
- Pair with **Rate limits** if you also need per-IP caps on APIs.

## Related sections

- **Rate limits** — per-IP caps
- **Error pages** — branded maintenance / 503 during planned downtime
