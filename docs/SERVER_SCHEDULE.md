---
title: "Server schedule"
slug: server-schedule
category: "Servers"
order: 340
description: "A calendar view of upcoming server crontab entries and linked site cron executions, gated by the workspace.schedule feature."
group: servers
---

# Server schedule

The **Schedule** section shows a calendar view of upcoming **server** and linked **site** cron executions.

## Calendar view

See jobs plotted by time across:

- Server crontab entries from **Cron jobs**
- Site crons for sites on this host (read-only overlay)

Use this to avoid stacking heavy jobs at the same minute.

## Feature flag

**Schedule** requires the org **`workspace.schedule`** feature. When disabled, the sidebar link is hidden.

## Timezone

Times display in the organization timezone. Cron expressions on the server use the host's local time unless noted on the job.

## Related sections

- **Cron jobs** — add or edit server jobs
- **Site → Schedule** — same calendar scoped to one site
- **Deploy windows** — policy windows vs actual job times
