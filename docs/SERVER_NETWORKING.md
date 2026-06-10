---
title: "Networking"
slug: server-networking
category: "Servers"
order: 20
description: "A workspace-wide view of each server's public and private addresses, listening services, database exposure, and managed load balancers."
group: servers
---

# Networking

A workspace-wide view of every server's network surface: private IPs, the services each one runs, and which databases are reachable across the network.

## Workspace map

Each server lists its public and private addresses and the listening services dply knows about. Use it to confirm app servers can reach database and cache hosts over the private network before wiring connections.

## Database exposure

See at a glance which database engines are bound to the private network (reachable by peers) versus loopback-only. Tighten or open exposure from a server's **Databases** workspace.

## Load balancers

Networking is the hub for managed load balancers across the whole workspace — provision a balancer, attach target servers, and configure health checks here. A single server's **Load Balancers** tab shows only the balancers pointing at it.

## Related sections

- **Load Balancers** — balancers targeting one server
- **Firewall** — per-host UFW rules and provider cloud firewall
- **Databases** — bind an engine to the private network
