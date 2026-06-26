---
title: "live server stats and chasing missing metrics"
date: 2026-06-11
slug: "2026-06-11-live-server-stats"
summary: "Spent the day on live server stats, fixing metrics that weren't showing up, and tidying queue workers and headers."
tags: [metrics, servers, workers, ui]
published: true
---

Today was metrics day. The goal: when you look at a server, the numbers should be *live* and they should be *there*. Both halves turned out to need work.

The "live" part was the fun build — wiring up live server stats so the workspace reflects what the box is actually doing right now rather than whatever stale sample happened to be lying around. The "there" part was the grind: a chunk of metrics were just **missing**, silently. Tracking down why a metric doesn't appear is its own genre of debugging, because there's no error — there's just an empty chart, and an empty chart can mean a dozen different things.

Alongside that:

- **Queue workers** got an update and some config attention. Worker plumbing is one of those things where small misconfigurations show up as "why is this job not running" hours later, so it's worth getting right.
- **Headers** got updated — the boring HTTP hygiene kind, not the visual kind.

Most of the churn lived in Jobs and Config, which tracks: live stats and accurate metrics are mostly a backend-plumbing problem dressed up as a UI feature. The chart is the easy 10%.

## the real lesson

Missing metrics taught me, again, that "no data" needs to be a first-class, *explained* state. An empty panel that doesn't tell you whether it's broken, still loading, or genuinely zero is worse than no panel. I didn't fully fix that philosophy today, but I'm noting it down for when I inevitably hit it a third time.

Short day in the log, longer day in practice. Metrics always are.
