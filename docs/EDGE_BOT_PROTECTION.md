---
title: "Edge bot protection"
slug: edge-bot-protection
category: "Edge"
order: 111
description: "Challenge automated traffic on forms or every HTML page using a privacy-friendly widget. Requires Dply-hosted Edge delivery."
group: edge
---

# Edge bot protection

**Bot protection** adds a challenge widget on your Edge site so automated scripts can’t submit forms (or browse pages) as easily as real people.

This feature requires **Dply-hosted Edge delivery**. Sites on BYO delivery cannot store challenge keys in the control plane.

## What it does

After you save site + secret keys and enable the feature, Edge republishes delivery config. Matching responses include the challenge widget; form submissions can be verified against the secret on Dply-hosted Edge.

| Mode | Behavior |
|------|----------|
| **Forms only** (recommended) | Challenge on form / POST surfaces |
| **All HTML pages** | Challenge on every HTML document response |

## How to set it up

1. Create a challenge widget with your bot-protection provider and copy the **site key** (public) and **secret key**.
2. Open the Edge site → **Bot protection**.
3. Paste both keys, choose a mode, enable, and **Save**.
4. Wait for delivery republish (usually under a minute), then verify a form page in a private window.

## Tips

- Start with **Forms only** unless you need a site-wide challenge.
- Pair with **Forms → Require bot check** so Edge form endpoints reject submissions without a valid challenge token.
- Never commit the secret key to your frontend repo — it stays on Dply-hosted Edge only.
- The site key is safe to embed in HTML (it identifies the widget publicly).

## Related sections

- **Forms** — mail-backed POST endpoints that can require the bot check
- **Rate limits** — use **Challenge** action when bot protection is configured
- **Delivery** — must be Dply-hosted (managed) for this feature
