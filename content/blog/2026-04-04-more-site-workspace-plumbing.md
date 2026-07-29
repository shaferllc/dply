---
title: "more site workspace plumbing"
date: 2026-04-04
slug: "2026-04-04-more-site-workspace-plumbing"
summary: "Another single-commit grind through site workspace services, models, jobs, and config — pushing the site experience toward something complete."
tags: [sites, services, jobs, config]
published: true
---

Picking up where the last couple of days left off — the site workspace is getting most of my attention right now, and today was more of that. One commit, but a wide one at around 170 files.

The work spread across services, models, the site workspace UI and views, a migration, and some Jobs. That mix is pretty telling: services and models for the logic, jobs for the work that has to happen asynchronously, a migration to back the new state, and UI to expose it. A vertical slice through the stack rather than fiddling at one layer.

There was config in the mix too, which usually means I added something new that needed knobs. I try to keep configuration honest — sensible defaults, and only expose a setting when there's a real reason someone would want to change it. It's easy to drown a tool in options nobody asked for.

## the jobs detail

Anything that touches a real server has to go through a queued job — never inline in the request path. That rule shapes a lot of how the site workspace is built: the UI kicks off a job and then watches for the result rather than blocking on it. Some of today was making sure that pattern holds as the site features grow, instead of sneaking synchronous work in where it doesn't belong.

Quiet, steady, no disasters. The site side is starting to feel like a real peer to the server side rather than a poor cousin. More of the same ahead until it's genuinely done.
