---
title: "billing, deptrac, and a map for the maze"
date: 2026-06-18
slug: "2026-06-18-billing-deptrac-and-a-map"
summary: "Finished the module extraction with Billing, added Deptrac to enforce the boundary, wrote CLAUDE.md, and spent a long time un-breaking tests."
tags: [modules, billing, tests, refactor, architecture]
published: true
---

Yesterday I extracted almost everything into modules. Today I closed it out and then paid the tax.

First the satisfying part: **Billing** came out as the final module — subscriptions, Stripe sync, metering, the usage cost calculators that half the other modules lean on. With that done the ADR officially reads "entangled-tier migration complete (29 modules)." I also moved the leaf models home: the Cashier `Subscription`/`SubscriptionItem` into Billing, `MarketplaceItem` into Marketplace, and four more (Roadmap/Serverless/Backups/Realtime) into theirs.

Then the two things that make this real instead of cosmetic:

- **Deptrac** to enforce the one rule that matters — modules must never depend on the presentation shell. The arrow points UI → engine → kernel, never the reverse. Now a new violation fails the build instead of slowly creeping back in.
- **CLAUDE.md**, a navigation map of the whole codebase: the three tiers, the module table, and a "where do I put X" section. Mostly for the agents, honestly, but also for me at 11pm.

## The tax

The rest of the day was tests. A *lot* of tests. Moving classes around surfaced a pile of stale references and a few genuine bugs hiding behind them — the `SiteDoctorCommand` was calling `trim()` on a nullable `env_file_path` and crashing six tests, and the SiteTest suite needed a broad retarget to the current architecture (WorkspacePipeline, WorkspaceTools/Overview split, the `/home/dply/<domain>` paths, async queued toasts). Buried in there was a real AWS Lambda deploy bug, which is the nice thing about test churn — it occasionally hands you a free fix.

I also recorded the "bidirectional sibling gotcha" in the ADR so it stops biting me. The boundary is drawn and now it's guarded. Feels good to have a clean map.
