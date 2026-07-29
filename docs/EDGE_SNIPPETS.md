---
title: "Edge snippets"
slug: edge-snippets
category: "Edge"
order: 115
description: "Inject small HTML into matching pages at the Edge without rebuilding — banners, meta, and trusted markup."
group: edge
---

# Edge snippets

**Snippets** inject small HTML into matching pages at the Edge — banners, meta, or support widgets — without rebuilding or redeploying your app.

Requires **Dply-hosted Edge delivery**.

## What a snippet contains

| Field | Purpose |
|-------|---------|
| **Name** | Label in the dashboard |
| **Inject** | Before `</head>` or before `</body>` |
| **Path** | Pattern (`/*` for all pages, or `/blog/*`) |
| **HTML** | Markup to inject (keep small and trusted) |

## How to use it

1. Enable snippets and add an item.
2. Choose head vs body, set a path, paste HTML.
3. **Save** — delivery republishes; visitors see the inject on the next request.

## Tips

- Prefer **Tags** for third-party `https://` script URLs; use Snippets for inline markup.
- Narrow paths keep marketing scripts off app routes.
- Only inject HTML you trust — this runs in every matching visitor’s page.

## Related sections

- **Tags** — remote analytics / pixel script URLs
- **Build** — for HTML that should ship with the app repo instead
