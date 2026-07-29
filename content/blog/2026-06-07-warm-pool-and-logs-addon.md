---
title: "a warm pool and the dply Logs add-on"
date: 2026-06-07
slug: "2026-06-07-warm-pool-and-logs-addon"
summary: "Added a warm pool so managed VMs skip cold provisioning, shipped the dply Logs add-on with a Vector agent, and moved config reads off the render path."
tags: [provisioning, logs, servers, modules]
published: true
---

Seven commits but a couple of them are meaty. Today was about not making people wait.

The big one is the **warm pool**: keep a few managed VMs pre-provisioned and ready, so when someone asks for a box they get one that already exists instead of watching a cold provision crawl through every install step. Yesterday was about making cold provisioning *faster*; today is about skipping it entirely when we can. I also added a backstop for **stranded warm-pool and claimed-server provisioning**, because the failure mode of a pool is a server that's neither fully warm nor fully claimed, and you really don't want one of those floating around.

## dply Logs lands

The other headline: a first cut of the **dply Logs add-on**. It installs a **Vector agent** on the server that ships host logs, and it captures scheduler output too. There's a scheduler wired in, and I hardened the install so it **fails cleanly instead of stranding on config drift** — a half-installed log agent that thinks it's fine is worse than one that admits it failed. Also added **ClickHouse TLS CA verification** on the receiving end, because shipping logs in the clear was never going to fly.

## the small but satisfying fix

Moved the webserver **config-file listing off the render path** and onto `wire:init`, and dropped the per-path config content cache. SSH in a render path is the cardinal sin around here — 30 seconds of PHP execution time and an SSH call do not mix — so anything that sneaks a remote read into a page load gets exiled to a deferred load. Quiet fix, but it's the difference between a snappy page and a mystery timeout.

Good day. The warm pool is the kind of thing nobody notices when it works, which is exactly the point.
