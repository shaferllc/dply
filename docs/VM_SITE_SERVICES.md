---
title: "Site services (systemd)"
slug: vm-site-services
category: "Sites & deploys"
order: 110
description: "Manage site-scoped systemd units for web upstreams, workers, and schedulers, including when to use Services versus Supervisor Daemons and unit syncing."
group: sites
---

# Site services (systemd)

The **Services** section manages **systemd units** scoped to this site — `dply-site-{siteId}.service` for the web upstream and `dply-site-{siteId}-{name}.service` for workers and schedulers.

## When to use Services vs Daemons

| Workload | Use |
|----------|-----|
| Node / Rails / Python **web app** (start command + port) | **Runtime → Overview** (unit generated automatically) |
| Node / Python / Rails **systemd workers** | **Services** |
| Laravel Horizon, `queue:work`, Reverb | **Daemons** (Supervisor) |
| Rails Sidekiq (recommended) | **Daemons** (Supervisor) — optional Sidekiq preset on Services |
| Cron / `schedule:run` | **Cron jobs** or Laravel Schedule |

PHP and static sites do not use site-scoped systemd units — PHP-FPM and nginx handle the web tier. Queue workers for PHP/Laravel belong on **Daemons**.

## Units tab

- **Web unit** — read-only; edit start command and port on **Runtime → Overview**
- **Worker / scheduler units** — add with name + command, or load a preset
- **Sync to server** — writes unit files to `/etc/systemd/system/` and runs `systemctl enable --now`

## Preview tab

Shows generated unit file content before sync. Files are marked *Managed by Dply — do not edit* on the server.

## Related sections

- **Daemons** — Supervisor-managed programs (queue workers, Horizon, Sidekiq)
- **Runtime** — language, start command, internal port
- **Cron jobs** — one-shot scheduled commands
