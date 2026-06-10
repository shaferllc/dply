---
title: "Activity log"
slug: server-activity
category: "Servers"
order: 70
description: "An audit log of workspace control-plane actions on a server (deploys, firewall edits, SSH syncs, console runs) with actor, action, time, and status."
group: servers
---

# Activity log

The **Activity** section records workspace actions on this server — deploys, firewall edits, SSH syncs, and console runs.

## Log entries

Each row typically shows:

- **Actor** — user or API token
- **Action** — verb and target (site, rule, key)
- **Time** — timestamp in org timezone
- **Status** — success or failure

Console **Run** commands log command text (truncated) but not full output — output may contain secrets.

## Filter and search

Filter by action type, site, or date range when the UI exposes filters. Export may be available for compliance.

## Audit vs system logs

| **Activity** | **Logs** (system) |
|--------------|-------------------|
| dply control-plane actions | Raw `/var/log` files on the host |
| Who changed firewall rules | `auth.log`, nginx error log |

## Related sections

- **Logs** — tail remote system log files
- **Security** — auth.log digest
- Org **Audit log** — cross-server history (admin)
