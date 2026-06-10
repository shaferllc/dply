---
title: "Errors"
slug: server-errors
category: "Servers"
order: 50
description: "A single newest-first stream of failures on a server and its sites, covering what lands here, dismissing or retrying errors, and how they are collected."
group: servers
---

# Errors

A single stream of every failure on this server and the sites it hosts — newest first — so you don't have to dig through logs to find what broke.

## What lands here

Deploy failures, SSL and certificate problems, connectivity issues, cron failures, and other operational errors are collected automatically and grouped by cause.

## Handling errors

**Dismiss** an error once you've handled it to clear it from the stream. Where an error is retryable — a failed deploy step, for example — use **Retry** to run it again.

## How it's collected

Errors are gathered by a scheduled sweep rather than live model hooks, so an entry can appear shortly after a failure even when the originating job recorded it out-of-band.

## Related sections

- **System logs** — raw service and system log output
- **Insights** — proactive health findings
- **Site → Errors** — the same stream scoped to one site
