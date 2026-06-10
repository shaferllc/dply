---
title: "Site settings"
slug: vm-site-settings
category: "Sites & deploys"
order: 270
description: "Edit core site metadata, choose atomic or simple deploy strategy, suspend the site, and reach notification routing."
group: sites
---

# Site settings

The **Settings** section holds core site metadata and high-impact toggles.

## General fields

Edit:

- **Site name** — workspace label
- **Project** — org workspace grouping
- **Primary hostname** — see **Routing** for full domain management

## Deploy strategy

Choose **Atomic (zero-downtime)** — release dirs + `current` symlink — or **Simple** in-place updates. Atomic is recommended for production traffic.

## Suspend site

**Suspend** serves a static maintenance page from `.dply/suspended/` until resumed. Server-wide suspend is on **Server → Maintenance**.

## Notifications shortcut

Links to **Notifications** for deploy success/failure routing.

## Related sections

- **Deploy** — webhooks and hooks detail
- **Routing** — domains and redirects
- **Danger zone** — delete site
