---
title: "the great module extraction"
date: 2026-06-17
slug: "2026-06-17-the-great-module-extraction"
summary: "Spent the whole day pulling domain engines out of the app root and into a real modular monolith, one capability at a time."
tags: [refactor, modules, architecture, deploy, edge]
published: true
---

Today was the big one. 78 commits, and almost all of them say "extract X engine into app/Modules/X." dply has been growing as one flat Laravel app for a while now, and the seams were starting to show — Edge, Cloud, Deploy, Billing, Serverless all tangled together in the same `app/Services` and `app/Jobs` piles. So I finally sat down and carved the thing into a modular monolith.

The shape that emerged is three tiers: domain engines live in modules, the workspace UI that drives them stays in the shell, and the hub models everything shares are the kernel. I wrote an ADR to capture the *why* so future-me doesn't undo it on a whim.

## What got pulled out

- Cleanest first: **Projects**, **RemoteCli**, **Remediations**, **Launch** — the "clean tier," barely any cross-dependencies.
- Then the medium-coupled ones: **Realtime**, **Secrets**, **Serverless**, **Insights**, **Imports**.
- Then the genuinely entangled tier: **SourceControl**, **Certificates**, **Notifications**, **Scaffold**, **Marketplace**, **Snapshots**, **Backups**, **Logs**, **Ai**, **Cloud**.
- And the two biggest engines, saved for last(ish): **Edge** (in four phases — engine, jobs, Livewire, then 27 HTTP controllers) and **Deploy** (engine, then the site-deploy job + commands).

By end of day the ADR reads: Edge fully extracted, Deploy fully extracted, only Billing remaining.

The annoying part was the "unqualified sibling" gotcha — moving a class by capability quietly breaks any sibling that imported it by relative name, and the failures don't all surface until you run the suite. I documented it in the ADR as a warning to myself. A couple of tests (ScaffoldLaravelPipeline, RemediationScoping, InsightsFeature) needed their stubs realigned after the moves.

3,583 files touched. Tomorrow: finish Billing, wire up Deptrac so the boundary can't quietly rot, and clean up whatever I broke.
