---
title: "Edge tags"
slug: edge-tags
category: "Edge"
order: 116
description: "Load third-party analytics and pixel scripts from the Edge. Optional consent helper for your CMP."
group: edge
---

# Edge tags

**Tags** load third-party scripts (analytics, ads, chat) from the Edge so you can add or remove them without a git deploy.

Requires **Dply-hosted Edge delivery**.

## What a tag contains

| Field | Purpose |
|-------|---------|
| **Name** | Label in the dashboard |
| **Script URL** | `https://` source from the vendor |
| **Async** | Load without blocking the page when possible |

## Consent helper

When **Consent helper** is on, Edge exposes `window.__dplyTags.consent` and reads `localStorage` key `dply_tag_consent` so your CMP can gate which scripts run.

Wire your consent UI to set that flag before marketing tags fire.

## How to use it

1. Enable tag manager.
2. Add each tool with name + `https://` script URL.
3. Optional: enable Consent helper and connect your CMP.
4. **Save** — scripts inject on subsequent page loads.

## Tips

- Only `https://` sources are allowed.
- For one-off HTML (not a remote script), use **Snippets**.
- Prefer async for non-critical pixels.

## Related sections

- **Snippets** — inline HTML inject
- **Bot protection** / **Forms** — unrelated to tags; do not put secrets in tag scripts
