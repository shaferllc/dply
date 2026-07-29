---
title: "Edge firewall"
slug: edge-firewall
category: "Edge"
order: 118
description: "Allow or block visitors by country at the Edge before your app or origin sees the request."
group: edge
---

# Edge firewall

**Firewall** (geo) allows or blocks visitors by country at the Edge — before your app or origin sees the request. Blocked visitors get **HTTP 403**.

## Modes

| Mode | Behavior |
|------|----------|
| **Off** | Allow all countries |
| **Allow listed only** | Hard allowlist — every other country is denied |
| **Block listed** | Deny listed countries; everyone else passes |

## How to use it

1. Open **Firewall** and choose a mode.
2. Search and add ISO country codes (e.g. `US`, `DE`). Remove chips to drop a country.
3. **Save** — rules apply after delivery republishes.

## Tips

- **Allow listed only** is strict: misconfigured lists lock out most of the world.
- Repo `dply.yaml` can declare countries too; the dashboard shows repo config alongside operator overrides when present.
- Combine with **Rate limits** and **Bot protection** for layered abuse control — geo is coarse, not a CAPTCHA.

## Related sections

- **Rate limits** — per-IP path caps
- **Bot protection** — challenge widgets
- **Routing** — path rules from `dply.yaml` (not country rules)
