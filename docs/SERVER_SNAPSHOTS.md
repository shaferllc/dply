---
title: "Cache snapshots"
slug: server-snapshots
category: "Servers"
order: 60
description: "Point-in-time RDB snapshots of a server's Redis-compatible cache engine, covering on-demand and scheduled captures, restore, and the S3-style destination."
group: servers
---

# Cache snapshots

Point-in-time **RDB snapshots** of the Redis-compatible cache engine on this server — for capturing cache or queue state you don't want to lose.

## Running snapshots

Trigger a snapshot on demand, or set a **cron schedule** to capture them automatically. Each snapshot streams to your configured S3-style backup destination.

## Restore

Restore any snapshot back onto the engine in one click. A restore overwrites current cache state, so confirm before restoring on a busy server.

## Destination

Snapshots use the same S3-style backup destination as your database backups — configure that destination in backup settings before scheduling.

## Related sections

- **Cache engines** — install and configure Redis/Valkey
- **Database backups** — point-in-time backups for SQL engines
