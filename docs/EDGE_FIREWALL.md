---
title: "Edge firewall"
slug: edge-firewall
category: "Edge"
order: 118
description: "Allow or block visitors by country at the Edge before your app or origin sees the request."
group: edge
---

# Edge firewall

**Firewall** (geo) allows or blocks visitors by country at the Edge — using the request’s country code — before your pages, forms, or origin see the traffic.

Blocked visitors get a plain **HTTP 403** on the same URL (not a branded page yet).

Requires **Dply-hosted Edge delivery** for Worker enforcement.

## Modes

| Mode | Behavior |
|------|----------|
| **Off** | Allow all countries (default) |
| **Allow listed only** | Hard allowlist — only listed countries enter; everyone else is 403’d |
| **Block listed** | Deny listed countries; everyone else passes |

**Block listed** is usually safer for a geo fence. **Allow listed only** can lock out most of the world if the list is incomplete.

An empty country list does **not** enforce — add at least one ISO code when mode is Allow or Block.

## What blocked visitors see

```
HTTP/1.1 403 Forbidden
Forbidden — content is not available in this region (XX).
```

Plain text from Edge (not your build). Custom branded block pages are not available yet.

## How to use it

1. Open **Firewall** and choose a mode.
2. Search and add ISO country codes (e.g. `US`, `DE`). Remove chips to drop a country.
3. **Save** — rules apply after delivery republishes.

## Tips

- Country comes from Edge geo (ISO 3166-1 alpha-2). VPNs and privacy proxies can look like another country.
- Repo `dply.yaml` can declare countries too; the dashboard shows repo config alongside operator overrides when present.
- Combine with **Rate limits** and **Bot protection** for layered abuse control — geo is coarse, not a CAPTCHA.

## Related sections

- **Rate limits** — per-IP path caps
- **Bot protection** — challenge widgets
- **Routing** — path rules from `dply.yaml` (not country rules)
