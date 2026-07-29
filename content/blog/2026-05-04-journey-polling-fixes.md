---
title: "killing the '100% restart' bug"
date: 2026-05-04
slug: "2026-05-04-journey-polling-fixes"
summary: "Cleanup day on the provisioning journey — fixed a polling thrash that restarted progress, plus server-create polish and a layout simplification."
tags: [server, ui, hygiene, deploys]
published: true
---

After two days of building, today was about going back and fixing the rough edges the building exposed. Smaller commit count, but each one was a real annoyance squashed.

The headliner was the journey page. It had a nasty habit of hitting 100% and then appearing to restart — and the culprit was multiple browser tabs each polling and stepping on each other, thrashing the progress state. Watching a provision crawl to done and then snap back to the start is exactly the kind of thing that makes you not trust a tool, so that one felt good to kill.

A couple more:

- server-create review polish, plus fixing fake-cloud staleness and the provision callbacks that weren't always firing cleanly
- stripping the per-server `apps/<server-slug>` Capistrano-style layout out of server provisioning — it was a leftover assumption that didn't fit how dply actually lays sites out, so simpler is better

## the fake-cloud tax

The fake-cloud staleness bug is a recurring tax of having a simulated provider for local dev: it has to behave enough like the real thing that bugs surface locally, but it's a separate code path that quietly drifts. Today it was handing back stale state and making the journey look wrong even when the real flow was fine.

No new surface area today, just a more trustworthy one. That's a fair trade after the week I've had.
