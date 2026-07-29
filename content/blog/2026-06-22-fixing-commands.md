---
title: "fixing commands"
date: 2026-06-22
slug: "2026-06-22-fixing-commands"
summary: "Three small commits sorting out command wiring and a few workspace UI rough edges left over from the module moves."
tags: [bugfix, ui, jobs, modules]
published: true
---

"fix commnds" — typo and all — is the commit message that sums up today. Three commits, ten files, scattered across the server and site workspace UI and a job or two.

When I extracted everything into modules last week, the CLI commands moved too — each capability's commands now live in its own module's `Console/` and get registered by that module's service provider. That's the right home for them, but "right home" and "actually wired up correctly" are two different claims, and today was about closing the gap on a few that weren't quite resolving. The kind of thing where the command exists, lives in the proper place, but the registration or a path assumption was off just enough to misbehave.

The workspace UI touches were the same flavor as the rest of this week: small alignment fixes in the surfaces that drive these engines, smoothing over edges the big move left behind. And one job got a look.

## the through-line

I keep noticing this is the cost of a structural refactor you don't see in the before/after diagram: it's not one big breakage, it's a long tail of tiny "oh, that needs fixing too" moments that surface only as you use the thing. Each one is two minutes. There are just a lot of them.

Still, the tail is getting shorter. Fewer surprises each day. Soon I'll trust the new structure enough to stop poking it and start building on top of it again.
