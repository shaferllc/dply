---
title: "Services inventory"
slug: server-services
category: "Servers"
order: 360
description: "An inventory of systemd units and long-running processes dply tracks on the server, with state, boot-enablement, and start/stop/restart actions."
group: servers
---

# Services inventory

The **Services** section lists systemd units and long-running processes dply knows about on the server.

## Service table

Rows typically include:

- **Unit name** — `nginx`, `php8.3-fpm`, `mysql`, etc.
- **State** — active, inactive, failed
- **Enabled** — start on boot
- **Actions** — start, stop, restart (where permitted)

## Expected services

Provisioning sets **`meta.expected_services`** tags that also filter **Console** help and sidebar visibility (**Databases**, **PHP**).

## Restart safely

Prefer **Webserver** or **PHP** panels for graceful reloads. Hard restarts here may drop in-flight requests.

## Related sections

- **Health** — aggregate service probes
- **Daemons** — Supervisor-managed workers (not all systemd units)
- **Manage** — install missing packages before services appear
