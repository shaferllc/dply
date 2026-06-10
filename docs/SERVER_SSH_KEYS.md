---
title: "SSH keys on server"
slug: server-ssh-keys
category: "Servers"
order: 410
description: "Syncs a server's authorized_keys with keys managed in dply from profile, team, and server sources, with sync and connection repair."
group: servers
---

# SSH keys on server

The **SSH keys** section syncs **authorized_keys** on the server with keys managed in dply.

## Key sources

Keys may come from:

- **Profile → SSH keys** — personal keys with optional auto-provision on new servers
- **Organization / team** keys
- **Server workspace** additions scoped to this host

## Sync behavior

**Sync keys** pushes the effective key set over SSH (root with deploy-user fallback). Audit events record each sync.

## Repair connection

If SSH fails, dply may use the **root** provision key to reconnect and re-sync — see friendly error copy instead of raw exceptions.

## Sub-tab pattern

Shares tab chrome with **Firewall**, **Cron**, and **Daemons**.

## Related sections

- **SSH access graph** — lineage and time-boxed sessions
- **Profile → SSH keys** — add personal keys
- **Settings** — default SSH user and port
