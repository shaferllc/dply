---
title: "a big sweep across the workspaces"
date: 2026-05-30
slug: "2026-05-30-workspace-services-cleanup"
summary: "A wide, slightly unglamorous day touching both server and site workspaces, the services layer, docs, and a pile of tests."
tags: [services, tests, ui, hygiene]
published: true
---

No headline feature today, just a lot of ground covered. The commit messages were all "wip", which is usually a sign I was moving fast and not narrating it to myself. Over a thousand files moved, but most of that is the kind of churn that doesn't show up in a screenshot.

The bulk of it lived in the **server and site workspace views** plus the **services** layer underneath them. When I'm in both of those at once it almost always means I'm pulling logic down out of a Livewire component and into a service so the view gets thinner. That's the boring work that keeps the next feature from being miserable.

## the spread

- Server and site workspace views got reworked side by side.
- The services and server-services layer took the brunt of the real changes.
- Docs got a refresh — usually means I finally wrote down something I'd been carrying in my head.
- Tests came along for the ride, and there was some package-level churn too.

The annoying part of days like this is they're hard to feel good about. Nothing is "done", everything is "better". But better-everywhere is how you buy yourself a clean run at the next big thing.

Tomorrow I'd like one thing I can actually point at.
