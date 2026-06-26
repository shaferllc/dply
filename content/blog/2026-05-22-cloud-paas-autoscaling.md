---
title: "building out Cloud, the managed PaaS"
date: 2026-05-22
slug: "2026-05-22-cloud-paas-autoscaling"
summary: "A big push on the Cloud product — managed PaaS scaffolding plus autoscaling and health checks, with a huge dependency bump dragged along."
tags: [cloud, paas, autoscaling, dependencies]
published: true
---

Today was a real one. The headline is **Cloud** — the managed PaaS side of dply,
where your app runs as containers on a backend instead of on a VM you babysit.
I built out a big chunk of the product surface and then went straight at the
operational bits: **autoscaling and health checks**.

Autoscaling and health checks are the two things that separate "I deployed a
container" from "I have a managed platform." If the thing can't notice it's
unhealthy and can't grow under load, it's a demo, not a product. So that's where
the time went.

## the other half of the diff

This was a 2,000-file day, but don't let that fool you — a chunk of it is a
**dependency bump** (the npm_and_yarn group, five updates in one go) plus a `pint`
pass tidying PHP formatting across the tree. Those inflate the file count without
inflating the brain effort. The actual product work is the Cloud build-out and
the autoscaling/health-check layer.

I also had to **fix some stale ConsoleDrawerTest failures** — tests that had
quietly drifted out of sync with the console drawer until this dependency churn
shook them loose. Classic: you don't find the rotten test until something
unrelated rattles the shelf.

## what bit me

Dependency bumps are never free. You bump five packages "just to stay current"
and then spend an hour figuring out which one changed a behavior your tests were
silently relying on. Worth it, but never the five-minute job it looks like.

Cloud is starting to feel like an actual product instead of a backend wrapper.
Next I want the create flow to feel as seamless as the runtime now does.
