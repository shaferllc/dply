---
title: "Site schedule"
slug: vm-site-schedule
category: "Sites & deploys"
order: 260
description: "View the server Schedule calendar filtered to this site's cron and related jobs to plan upcoming runs and deploy windows."
group: sites
---

# Site schedule

The **Schedule** section opens the **server Schedule calendar** filtered to this site's cron and related jobs.

## Calendar view

See upcoming runs for:

- Site **Cron jobs** defined here
- Linked server jobs that affect this app (read-only)

Useful for planning deploys around heavy **`artisan schedule:run`** minutes.

## Navigation

This item routes to **Server → Schedule** with site context in the URL — same component, scoped highlighting.

## Feature flag

Requires org **`workspace.schedule`**.

## Related sections

- **Cron jobs** — edit site entries
- **Server → Schedule** — full-server view
- **Deploy windows** — policy vs actual run times
