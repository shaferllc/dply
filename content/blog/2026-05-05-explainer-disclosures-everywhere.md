---
title: "an explainer on every tab"
date: 2026-05-05
slug: "2026-05-05-explainer-disclosures-everywhere"
summary: "Rolled an x-explainer disclosure across every server workspace tab, then taught the cache workspace to show live Redis stats."
tags: [ui, server, redis, components]
published: true
---

Today had a clear, almost meditative shape: I built an `x-explainer` disclosure component and then went tab by tab adding one to every workspace in the server view.

The list, basically all of them — Overview, Sites, Services, Settings, SSH keys, Run, Monitor, Manage, Insights, Firewall, Daemons, Logs, Cron, PHP, Databases. The point is that a managed platform hides a lot of real machinery behind friendly buttons, and people rightly want to know what's actually happening on their box. A collapsible explainer on each tab lets the UI stay clean for people who don't care, while being honest with the people who do. Transparency as a default, not a docs page nobody finds.

## the cache tab got interesting

The other half of the day was making the cache workspace less of a static card and more of a window into Redis:

- a SCAN-based key browser in the Stats sub-tab, so you can actually poke at what's in there
- a bounded MONITOR tail, then wired up to live over Reverb broadcasts so it streams
- auto-picking the next free port when you install a cache service, instead of making you think about it

The MONITOR tail is the one to keep an eye on — MONITOR is a firehose and Redis hates it running unbounded, so the "bounded" part is load-bearing. But seeing keys and live commands right in the workspace makes the whole thing feel a lot more alive. Good day of unglamorous, high-trust UI work.
