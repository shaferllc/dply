---
title: "Server overview"
slug: server-overview
category: "Servers"
order: 300
description: "The home screen for a BYO VM server workspace showing status, IP, quick context cards, and the provision journey for setup and reconnection."
group: servers
---

# Server overview

The **Overview** tab is the home screen for a BYO VM server workspace. Use it to confirm the server is reachable, see quick context, and jump into **Sites** or **Run**.

## Status and hero

The top bar shows:

- **Server name** and provider (Hetzner, DigitalOcean, etc.)
- **Status badge** — provisioning, ready, unreachable, etc.
- **IP address** — copy for SSH or DNS

Most stack tools stay disabled until provisioning finishes and SSH is healthy.

Each sidebar section has a matching guide in **Documentation → Server workspace guides** (panel on every workspace page).

## Quick context cards

Typical cards include:

- **Sites** — count and links to hosted sites
- **Webserver** — active engine (Nginx, Caddy, etc.)
- **Resources** — CPU, memory, or disk snapshots when metrics are available

Use these to confirm you are on the expected host before running commands.

## Provision journey

New servers show a **provision journey** on Overview until setup completes. Each step lists status and output. If SSH drops mid-install, use **Reconnect** or **Resume** from the same page.

## When SSH is not ready

Sidebar sections that touch the host ( **Run**, **Console**, **Configuration**, **Files**, etc.) show a shared empty state: *Provisioning and SSH must be ready…*

Wait for **ready** status or fix SSH keys under **SSH keys**.

## Related sections

- **Sites** — sites hosted on this server
- **Health** — deeper health cockpit
- **Settings** — server name, provider metadata, reconnect
