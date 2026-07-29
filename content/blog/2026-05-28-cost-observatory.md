---
title: "a transparent cost observatory"
date: 2026-05-28
slug: "2026-05-28-cost-observatory"
summary: "Shipped a transparent cost observatory into analytics, plus a wide sweep across server and site workspaces and services."
tags: [billing, analytics, servers, ui]
published: true
---

The feature I actually care about today: a **transparent cost observatory** baked
into analytics. The whole idea is that you should be able to see where your money
is going on the platform without it feeling like a billing interrogation — what
each server, site, and add-on is actually costing, laid out plainly instead of
buried in an invoice at the end of the month.

"Transparent" is the operative word and the hard part. It's easy to show a total.
It's much harder to decompose that total into honest, per-resource numbers that a
person can look at and go "ah, that's the box doing it." That decomposition is
where most of the work went — pulling the cost story out of the server services
and getting it onto a screen.

This was a 28-commit, thousand-file day, so plenty rode along with it:

- The **cost observatory** itself, living in the analytics surface.
- A broad sweep across **server and site workspace views and UI**, since the cost
  data has to surface in the places you already look.
- **Config and server-services** work underneath to feed the numbers.
- Tests, naturally — money math is the last place you want to be guessing.

The annoying part of any billing feature is that there's no "close enough." A
view that's off by a rounding error in a UI is a shrug; the same error next to a
dollar sign is a support ticket and a trust problem. So I was slower and more
paranoid than usual, which is correct.

I like building the kind of billing UI I'd actually want to be shown. More of that.
