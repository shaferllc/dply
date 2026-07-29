---
title: "Edge rate limits"
slug: edge-rate-limits
category: "Edge"
order: 113
description: "Cap requests per IP on a path pattern. Block with HTTP 429 or challenge when bot protection is enabled."
group: edge
---

# Edge rate limits

**Rate limits** cap how many requests a single IP can make to a path in a time window — useful against scrapers, credential stuffing, and runaway clients.

Requires **Dply-hosted Edge delivery**.

## What a rule contains

| Field | Purpose |
|-------|---------|
| **Path** | Pattern such as `/*` or `/api/*` |
| **Limit** | Max requests in the window |
| **Window (sec)** | Rolling window length |
| **Action** | **Block (429)** or **Challenge** |

**Challenge** needs **Bot protection** configured; otherwise prefer **Block**.

## How to set it up

1. Open **Rate limits** and enable the feature.
2. Add a rule: path, limit, window, action.
3. Prefer specific paths (`/api/login`) over site-wide `/*` when possible.
4. **Save** — rules apply after delivery republishes.

## Tips

- Protect login and form endpoints tightly; leave static assets open.
- Stack with **Bot protection** and **Forms** for layered defense.
- Too-low limits on `/*` will throttle real visitors — watch Traffic after enabling.

## Related sections

- **Bot protection** — required for Challenge action
- **Forms** — common path to rate-limit
- **Traffic & analytics** — see if legitimate traffic is being cut off
