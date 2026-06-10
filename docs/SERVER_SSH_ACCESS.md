---
title: "SSH access graph"
slug: server-ssh-access
category: "Servers"
order: 400
description: "Visualizes who can SSH to a server through users, keys, grants, and time-boxed sessions, with audit alignment and a gated preview."
group: servers
---

# SSH access graph

The **Access graph** section visualizes **who can SSH** to this server — users, keys, sessions, and grants.

## Graph view

Nodes represent:

- **Users** and **teams** with keys provisioned here
- **SSH keys** and sync events
- **Time-boxed sessions** — temporary access with expiry

Edges show provision-on-create vs manual deploy.

## SSH sessions

Grant **temporary access** with start/end times. Expired sessions revoke via scheduled **`dply:revoke-expired-ssh-sessions`**.

## Audit alignment

Pair with **Security** digest and **Activity** for login attempts vs authorized keys.

## Coming soon preview

When gated, shows a teaser graph explaining access lineage without live data.

## Related sections

- **SSH keys** — manage authorized_keys set
- **System users** — Unix accounts on the host
- **Firewall** — restrict SSH source IPs
