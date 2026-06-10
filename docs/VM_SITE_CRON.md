---
title: "Site cron jobs"
slug: vm-site-cron
category: "Sites & deploys"
order: 90
description: "Define site-scoped crontab entries such as schedule:run or custom scripts, sync repo-defined jobs from dply.yaml, and test jobs with run-now output."
group: sites
---

# Site cron jobs

The **Cron jobs** section defines **site-scoped** crontab entries — typically `artisan schedule:run`, Rails runners, or custom scripts in the deploy path.

## Job list

Each job shows schedule, command, and user. **`dply.yaml` `crons`** sync on deploy merges repo-defined jobs.

## Run now

Test a job immediately; output streams like server cron runs.

## vs Server cron

| **Site cron** | **Server cron** |
|---------------|-----------------|
| Runs in site deploy directory | Host-wide maintenance |
| Laravel scheduler | System backups, apt |

## Supervisor dot

If Supervisor is missing, **Daemons** shows setup hints — cron still works via system crontab.

## Related sections

- **Schedule** — calendar view
- **Laravel** — recommended scheduler entry
- **Server → Cron jobs** — host-level jobs
