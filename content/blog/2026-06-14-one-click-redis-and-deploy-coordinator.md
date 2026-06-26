---
title: "one-click redis and unifying the deploy surface"
date: 2026-06-14
slug: "2026-06-14-one-click-redis-and-deploy-coordinator"
summary: "Shipped one-click Redis with auto-install, unified the deploy page and sidebar onto one coordinator, and added a 5xx auto-capture sweeper."
tags: [redis, deploys, refactor, insights]
published: true
---

Good day. Three solid features and one refactor I'd been dreading, all landed.

The one users will love: **one-click Redis** for cache, sessions, and queue — with auto-install. Picking "I want Redis for my cache" and then having to think about installing and wiring it is exactly the friction dply is supposed to remove. Now it's a button, and the install happens for you.

The refactor: I **unified the deploy page and the sidebar onto a single `SiteDeployCoordinator`**. Before, those two surfaces had drifted into having their own slightly-different ideas about deploy state, which is how you end up with the sidebar saying one thing and the page saying another. Collapsing them onto one coordinator means there's now a single source of truth for "what is this deploy doing." This is the kind of change that's invisible when it works and infuriating when it doesn't.

On the reliability side:

- A **Tier-2 5xx auto-capture sweeper** plus uptime-probe self-heal. The sweeper catches server errors that the first-tier capture misses, and the self-heal keeps a stalled probe worker from silently freezing all the monitors.
- Deploy guardrails: an **internal-exempt flag**, pre-gating paused deploys, and a cap on standing automation — so automated deploys can't run wild and so paused sites actually stay paused.

## the annoying part

Half the day's commits are honestly named things like "bugfix" and "changes," which means a good chunk of this was chasing the small breakages that fall out of a refactor like the coordinator one. You move two surfaces onto shared code and immediately discover all the places they were quietly disagreeing.

Net-net: deploys are more coherent, errors get caught more reliably, and Redis is a click. Solid Saturday.
