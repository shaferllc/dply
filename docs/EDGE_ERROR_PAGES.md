---
title: "Edge error pages"
slug: edge-error-pages
category: "Edge"
order: 119
description: "Brand 404 and 500 HTML and flip maintenance mode (503) at the Edge without a redeploy."
group: edge
---

# Edge error pages

**Error pages** let you brand **404** and **500** responses and take the site offline with **maintenance mode (503)** — all at the Edge, without touching your repo.

## What you can set

| Control | Purpose |
|---------|---------|
| **Maintenance mode** | Visitors get 503 until you turn it off and Save |
| **404 page** | HTML when a path isn’t found (blank = built-in default) |
| **500 page** | HTML for unexpected errors (blank = built-in default) |

## How to use it

1. Paste full HTML for 404 and/or 500, or leave blank for defaults.
2. Turn on **Maintenance mode** for a hard stop during incidents or cutovers.
3. **Save** to republish delivery. Changes apply on the next request — no rebuild.

## Tips

- Keep error HTML self-contained (inline CSS). External assets may fail if the site is broken.
- Repo `dply.yaml` can declare error pages; dashboard values are the operator override.
- For launch queues (not full downtime), prefer **Waiting room** over maintenance.

## Related sections

- **Waiting room** — soft queue during spikes
- **Delivery** — host map / cache tools
- **Build** — SPA fallback and static routing that affect what becomes a 404
