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

1. Before a launch, set a conservative max active and admit rate.
2. List paths that should use the room (keep the wait page’s static assets outside if needed).
3. Enable and **Save** before traffic spikes.
4. Raise the cap once the room drains cleanly; turn off when the event ends.

## Tips

- Start low and raise — easier than recovering from an overload.
- Marketing landing pages can stay outside the path list so people can read while waiting.
- Pair with **Rate limits** if you also need per-IP caps on APIs.

## Related sections

- **Rate limits** — per-IP caps
- **Error pages** — branded maintenance / 503 during planned downtime
