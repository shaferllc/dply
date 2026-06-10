---
title: "Server insights"
slug: server-insights
category: "Servers"
order: 250
description: "Surface advisory recommendations and anomalies for a server, including version drift, disk growth, unused daemons, and right-size hints linked to fixes."
group: servers
---

# Server insights

The **Insights** section surfaces recommendations and anomalies — misconfigurations, version drift, and cost nudges — scoped to this server.

## Insight cards

Examples include:

- **PHP version** end-of-life warnings
- **Disk** growth faster than retention settings
- **Unused** daemons or cron jobs
- **Right-size** hints when metrics show low utilization

Each card links to the fix location (**PHP**, **Hygiene**, **Daemons**, etc.).

## Refresh cadence

Insights recompute on page load and after major workspace actions. They are advisory, not blocking gates.

## Coming soon preview

When the feature is gated, the page shows sample insight types without live analysis.

## Related sections

- **Health** — live pass/fail probes
- **Metrics** — raw charts backing recommendations
- **Cost** card on Overview — org billing context
