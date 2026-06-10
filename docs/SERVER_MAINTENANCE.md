---
title: "Maintenance mode"
slug: server-maintenance
category: "Servers"
order: 270
description: "Suspend visitor HTTP traffic for all managed sites on a server while keeping SSH and deploy access, serving a static maintenance page per site."
group: servers
---

# Maintenance mode

The **Maintenance** section suspends **visitor HTTP traffic** for all managed sites on the server while keeping SSH and deploy access.

## Server-wide suspend

When enabled, the webserver serves a static **maintenance page** from `.dply/suspended/` for each affected site. Operators can still deploy and run commands.

## Per-site suspend

Site-level suspend lives in **Site → Settings**. Server maintenance affects every site on the host at once.

## Excluded runtimes

**Serverless**, **Docker**, and **Kubernetes** site runtimes are not suspended this way — they do not use the VM webserver maintenance hook.

## Enable and disable

Toggle **Maintenance mode** and confirm in the modal. Changes queue a webserver apply job; watch the console-action banner for completion.

## When to use

- Kernel reboots after **Patches**
- Database engine upgrades on **Databases**
- Emergency incident response before a fix deploys

## Related sections

- **Deploy windows** — block deploys during maintenance
- **Site → Settings** — suspend one app only
- **Webserver** — confirm config reloaded
