---
title: "fewer commits, more wiring"
date: 2026-05-23
slug: "2026-05-23-site-jobs-and-models"
summary: "A lower-key day stitching site workspace views to services and jobs, with a model and migration change underneath."
tags: [sites, jobs, services, refactor]
published: true
---

Four commits, but they covered more ground than the count suggests. The center of
gravity today was the site workspace again — views, the services behind them, and
the jobs they kick off — plus a model change with a migration to match.

After yesterday's Cloud sprint this felt almost restful. I was mostly connecting
things that already existed: a site view that needed to actually dispatch the job
instead of pretending it would, a service method that wanted to live one layer
down, a model field that finally got persisted properly.

A few notes from the day:

- More of the site workspace moved onto its services rather than doing work inline
  in the Livewire layer — the same cleanup thread I've been pulling for a couple
  days now.
- A model + migration pair, the kind of change that's invisible until the moment
  it isn't there.
- Jobs got touched, which usually means I finally moved something off the request
  path where it never belonged. Anything that talks to a box should be a queued
  job, full stop.

The honest version: this was a tidy-up-and-connect day, not an invent-something
day. No drama, nothing broke loudly, and the diff is the kind you forget a week
later. But the site side is quietly getting more coherent, and that's the point.
