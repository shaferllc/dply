---
title: "Edge rate limits"
slug: edge-rate-limits
category: "Edge"
order: 113
description: "Cap requests per IP on a path pattern. Block with HTTP 429 or challenge when bot protection is enabled."
group: edge
---

# Edge rate limits

**Rate limits** count requests **per visitor IP** on matching paths. When someone exceeds the limit in the time window, Edge stops them before your site or origin does the work.

Useful against scrapers, credential stuffing, and runaway clients.

Requires **Dply-hosted Edge delivery**.

## Rate limits vs waiting room

| | **Rate limits** | **Waiting room** |
|--|-----------------|------------------|
| Goal | Stop abusive volume from one IP | Cap total concurrent humans |
| Unit | Requests / IP / window | Active sessions site-wide |
| When exceeded | **429** or bot **Challenge** | “You’re in line” queue page |

Use **Waiting room** for launches; use **Rate limits** for abuse and API protection.

## What happens when a limit is hit

### Block (429)

Edge returns plain HTTP **429 Too Many Requests** with a `Retry-After` header. Best for APIs, bots, and automated clients.

### Challenge

Edge serves a bot-check page on the **same URL**. Passing the check lets that request through.

**Challenge** needs **Bot protection** (site + secret keys) enabled. Without keys, Challenge behaves like Block.

## What a rule contains

| Field | Purpose |
|-------|---------|
| **Path pattern** | e.g. `/*`, `/api/*`, `/login` |
| **Max requests** | Cap per IP in the window |
| **Window (seconds)** | How long the counter covers |
| **When exceeded** | **Block (429)** or **Challenge** |

First matching path rule applies for that request.

Example: `60` requests / `60` seconds on `/api/*` ≈ one request per second average per IP.

## How to set it up

1. Open **Rate limits** and enable the feature.
2. Add a rule: path, limit, window, action.
3. Prefer specific paths (`/api/login`, form endpoints) over site-wide `/*` when possible — a tight `/*` limit also throttles CSS/JS for real browsers.
4. **Save** — rules apply after delivery republishes (usually under a minute).

## Tips

- Protect login and form endpoints tightly; leave static asset paths open.
- Stack with **Bot protection** and **Forms** for layered defense.
- After enabling, watch **Traffic & analytics** / live requests for unexpected 429s.

## Related sections

- **Bot protection** — required for Challenge action
- **Waiting room** — concurrent visitor queue for launches
- **Forms** — common path to rate-limit
- **Traffic & analytics** — see if legitimate traffic is being cut off
