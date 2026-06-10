---
title: "Metrics monitor"
slug: server-metrics
category: "Servers"
order: 290
description: "Charts CPU, memory, disk, and network usage for a server over selectable time ranges from agent or provider data to spot spikes and load."
group: servers
---

# Metrics monitor

The **Metrics** section (sidebar **Metrics**) charts CPU, memory, disk, and network usage for the server over time.

## Charts

Typical panels:

- **CPU** — utilization percentage
- **Memory** — used vs available
- **Disk** — root and data mounts
- **Network** — ingress/egress when the agent reports it

Pick time ranges (1h, 24h, 7d) to spot spikes vs steady load.

## Data source

Metrics arrive from the server agent or provider API depending on hosting type. Gaps may appear if the host was unreachable.

## Alerts

Configure org **notification channels** for threshold alerts where enabled. This page is visualization; alerts live in org settings.

## Related sections

- **Health** — current service state
- **Insights** — interpreted findings from trends
- **Monitor** on sites — per-app uptime checks
