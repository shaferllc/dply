---
title: "Site uptime monitor"
slug: vm-site-monitor
category: "Sites & deploys"
order: 190
description: "Describes configuring HTTP uptime checks against a site's public URL, including interval, expected status, timeout, status history, and alerting."
group: sites
---

# Site uptime monitor

The **Monitor** section configures **HTTP uptime checks** against this site's public URL.

## Check settings

Define:

- **URL** — usually primary domain HTTPS
- **Interval** — probe frequency
- **Expected status** — typically 200
- **Timeout** — fail threshold

## Status history

View recent up/down transitions. Down events can notify channels configured in **Notifications**.

## vs Server Metrics

| **Site Monitor** | **Server Metrics** |
|------------------|-------------------|
| End-user URL reachability | Host CPU/memory/disk |
| Per-app SLO | Infrastructure capacity |

## Related sections

- **Routing → Domains** — hostname probed
- **Certificates** — TLS failures cause false downs
- **Notifications** — alert routing
