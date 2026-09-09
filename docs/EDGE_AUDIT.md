---
title: "Edge audit log"
slug: edge-audit
category: "Edge"
order: 121
description: "Who changed what on this Edge site — settings, bindings, firewall, and more."
group: edge
---

# Edge audit log

**Audit log** lists control-plane changes for this Edge site: who changed settings, when, and what moved (firewall, bindings, alerts, members, and similar).

Entries are written when operators save workspace actions that call `audit_log` for the site.

## How to use it

1. Open **Audit log** in the site sidebar.
2. Scan recent events for unexpected changes after an incident.
3. Drill into before/after payloads when the UI shows them.

## Tips

- This is the **dply control plane** audit trail, not Cloudflare’s account audit log.
- Deploy history itself lives under **Deploys** / **Build & deploy logs**.

## Related sections

- **Deploys** — release history
- **Members** — who can change the site
- **Danger zone** — teardown and irreversible actions
