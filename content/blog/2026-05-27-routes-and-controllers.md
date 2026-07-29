---
title: "wiring routes and the views behind them"
date: 2026-05-27
slug: "2026-05-27-routes-and-controllers"
summary: "Nine commits across server and site workspaces, with routes, controllers, and config getting some overdue attention."
tags: [routes, ui, servers, sites]
published: true
---

Nine commits today, and the standout from the area list is that **routes and
controllers** actually showed up — which for this app is a bit of an event. dply
is Livewire-first by design, so most behavior lives in components, not in
`web.php`. When I find myself in the routing layer it's usually for the few things
that genuinely belong there: downloads, webhooks, OAuth callbacks, that sort of
thing.

So today was partly about those edges. The rest was spread across the server and
site workspace views and a config pass to back the changes.

Roughly:

- **Routes and a controller or two** — the HTTP entry points that don't fit the
  Livewire model and shouldn't be forced into it.
- Both **server and site workspace views**, the perennial center of the app.
- **Config** changes to support whatever the new routes needed to know.

The discipline I keep reminding myself of: just because something is easy to bolt
onto a controller doesn't mean it goes there. If it's interactive UI behavior, it
wants to be a Livewire component. If it's a webhook or a file stream, the route is
the right home. Getting that line right keeps the codebase legible, and I'd rather
spend the extra two minutes now than untangle it in three months.

A workmanlike day. The plumbing's a little more correct than it was, and nobody
but me will ever notice — which is roughly the job description.
