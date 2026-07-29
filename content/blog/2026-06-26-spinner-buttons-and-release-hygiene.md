---
title: "spinner buttons and release hygiene"
date: 2026-06-26
slug: "2026-06-26-spinner-buttons-and-release-hygiene"
summary: "A polish-and-fix day: release-hygiene scan fixes, a shared spinner button, patch/security actions, tidier empty states, and a deploy button on the sites card."
tags: [ui, hygiene, servers, bugfix, deploy]
published: true
---

Today was one of those satisfying tidy-up days where nothing is huge but everything you touch ends up a little nicer than you found it. Four commits, thirty-odd files, mostly in the server workspace and the shared UI components.

The headline fix was in the **release-hygiene scan** — the check that looks over a server's state for the stuff that quietly accumulates and bites you later. It had some rough edges, and getting it reporting cleanly matters, because a hygiene scan you don't trust is worse than no scan at all. While I was in that neighborhood I wired up **patch and security actions** so the scan isn't just diagnosis — you can actually act on what it surfaces.

## the component that pays for itself

The change I'm happiest about is the smallest: I extracted a shared **spinner button** component. I'd been hand-rolling the "button that shows a loading spinner while its action runs" pattern in a dozen places, each slightly different, and every queued-SSH action in dply needs exactly that — you click, it dispatches a job, you wait. Now it's one component. Boring, reusable, and it'll quietly make every future button better.

The rest was honest polish:

- **Empty-state** cleanup so the blank screens read like intentional design instead of "nothing here yet."
- **Header alignment** fixes, because misaligned headers are the kind of thing you can't un-see once you've noticed.
- A **deploy button right on the sites card** — small, but it shaves a click off the most common thing you'd want to do from a server's sites list.

None of it is a feature you'd put on a landing page. All of it is the difference between an app that feels considered and one that feels assembled. After a couple of weeks of deep structural work, spending a day making the surfaces feel good was genuinely a treat. Back to bigger things next.
