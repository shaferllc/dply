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

## Settings

| Setting | Purpose |
|---------|---------|
| **Max active visitors** | How many people can browse at once |
| **New admits / minute** | How fast the queue drains into the site |
| **Session (minutes)** | How long someone stays “active” before they may re-queue |
| **Paths** | One pattern per line (e.g. `/`, `/checkout/*`). Empty only if you intend site-wide. |

## How to use it

No third-party account is required — configure everything in Dply (**Access → Waiting room**).

1. Before a launch, set a conservative **max active** and **admits / minute**.
2. List **paths** that should use the room (one per line). Prefer `/checkout` or `/launch` over site-wide `/*` when you can; keep static marketing pages outside the list so they stay snappy.
3. Enable and **Save** before traffic spikes (delivery republishes in under a minute).
4. Raise the cap once the room drains cleanly; turn off when the event ends.

## Tips

- Start low and raise — easier than recovering from an overload.
- Marketing landing pages can stay outside the path list so people can read while waiting.
- Pair with **Rate limits** if you also need per-IP caps on APIs.

## Related sections

- **Rate limits** — per-IP caps
- **Error pages** — branded maintenance / 503 during planned downtime
