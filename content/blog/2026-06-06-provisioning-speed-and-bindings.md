---
title: "faster provisioning and a pile of new bindings"
date: 2026-06-06
slug: "2026-06-06-provisioning-speed-and-bindings"
summary: "Made provisioning faster with parallel installs and baked snapshots, added mail/log/storage/cache bindings, and shipped site database management."
tags: [provisioning, modules, sites, deploys]
published: true
---

Thirty-six commits, two clear themes: make provisioning faster, and give sites more things they can plug into.

On speed, the big lever was **baked snapshots** — pre-built Hetzner and region-scoped DO images so a new box doesn't have to install the world on every cold start. On top of that: opt-in **parallel runtime installs** with package prefetch, a **boot head-start** with deferrable certbot, faster IP polling, and routing provisioning jobs to a **priority queue**. There's also a new **stalled-task sweeper** so a wedged provision doesn't sit there forever pretending it's still working.

## new bindings

This was the other half of the day — sites can now declare a lot more about what they need:

- **Mail** and **log drain** bindings, with realtime tiers.
- **Object storage** provider presets for storage bindings.
- A **cache** binding type, plus provisioning phpredis/gd extensions.
- A site binding catalog to tie it all together.

Also landed **site database management** with a real resource API behind it, an in-browser **dply CLI console** in site settings (gated behind a coming-soon preview for now), and a full logging config editor.

## what bit me

Shell quoting, as always. `escapeshellarg` was happily corrupting the chown user during env push, and pruning releases blew up on root-owned files until I taught it to use sudo. And cloning HTTPS repos needed real work: detect the git provider from the URL, then authenticate with the stored OAuth/PAT token instead of just hoping the remote was public.

Lots of surface area today. The provisioning speedups are the ones I'm most curious to feel in practice.
