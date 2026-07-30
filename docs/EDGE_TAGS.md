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

1. Enable tag manager (or turn on Consent helper — that enables it for you).
2. Add each tool with name + `https://` script URL, or click an **Example** chip (GA4, GTM, Meta, Clarity, Hotjar, Plausible).
3. Replace any placeholder IDs in the URL (`G-XXXXXXXXXX`, etc.) with your real account values.
4. Optional: enable Consent helper and connect your CMP.
5. **Save** — scripts inject on subsequent page loads.

## Example loaders

These are starter `<script src>` URLs. Tags inject the loader only — some vendors also need a small config snippet (use **Snippets** for that).

| Example | Starter URL |
|---------|-------------|
| Google Analytics (GA4) | `https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX` |
| Google Tag Manager | `https://www.googletagmanager.com/gtm.js?id=GTM-XXXXXXX` |
| Meta Pixel | `https://connect.facebook.net/en_US/fbevents.js` |
| Microsoft Clarity | `https://www.clarity.ms/tag/XXXXXXXXXX` |
| Hotjar | `https://static.hotjar.com/c/hotjar-XXXXXXX.js?sv=6` |
| Plausible | `https://plausible.io/js/script.js` |

## `dply.yaml`

```yaml
tags:
  enabled: true
  consent_required: false
  tools:
    - name: Google Analytics
      src: "https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX"
      async: true
```

Dashboard **Save** overrides the repo for the whole `tags` section. Generate an export from **Build → Generate dply.yaml** when you want the dashboard state as the file baseline.

## Tips

- Only `https://` sources are allowed.
- Empty script URL rows are ignored on Save.
- Consent helper can publish alone (no script URLs yet).
- For one-off HTML (not a remote script), use **Snippets**.
- Prefer async for non-critical pixels.

## Related sections

- **Snippets** — inline HTML inject
- **Bot protection** / **Forms** — unrelated to tags; do not put secrets in tag scripts
