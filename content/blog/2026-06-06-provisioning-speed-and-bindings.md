---
title: "Baked snapshots and a binding catalog: making provisioning fast and sites composable"
date: 2026-06-06
slug: "2026-06-06-provisioning-speed-and-bindings"
summary: "Thirty-six commits across two themes: cut cold-provisioning time with baked images and parallel installs, and let sites declare mail, logs, storage, and cache as first-class bindings."
tags: [provisioning, bindings, sites, performance, deploys]
type: deep-dive
published: true
---

Thirty-six commits, and they sort into two clean piles. The first: make provisioning *fast*, because the worst first impression a hosting platform can give is a spinner that lives for ten minutes while a fresh box installs the entire world. The second: make sites *composable* — let a site declare the things it depends on (mail, logs, object storage, a cache) as first-class bindings instead of leaving them as undocumented tribal knowledge in someone's `.env`. Plus the usual tail of fixes that only surface once real repos and real shells get involved.

## The cold-start problem

Cold provisioning is death by a thousand `apt-get install`s. Every new server was paying the full cost of installing language runtimes, extensions, and tooling from scratch, serially, while the user watched. The single biggest lever against that is to **not do the work at provision time** — so the day's centerpiece was **baked snapshots**: pre-built Hetzner images and region-scoped DigitalOcean images with the common stack already installed.

The economics are obvious once you say them out loud. Building the image is a one-time cost; booting from it is nearly free. A region-scoped DO snapshot also dodges cross-region image-copy latency, which is its own slow tax. So instead of "boot a bare Ubuntu box, then install everything," it's "boot a box that's already 90% there, then reconcile the last 10%."

Around that I layered a stack of smaller speedups:

- **Opt-in parallel runtime installs** with package prefetch — fan out the independent installs instead of marching through them one at a time.
- A **boot head-start** with **deferrable certbot** — start the long-pole work the instant the box is reachable, and push SSL issuance later in the sequence so it's not blocking the things a user wants to see first.
- **Faster IP polling** so we notice the box is up sooner.
- Routing provisioning jobs to a **priority queue**, so a human waiting on a new server isn't stuck behind a backlog of background chores.
- A **stalled-task sweeper**, because the corollary of "go faster" is "fail honestly" — a wedged provision should be detected and surfaced, not sit there forever pretending it's still working.

That last one matters more than the raw speed. Fast-when-it-works is easy; the hard part is making *stuck* a visible, recoverable state instead of an infinite spinner.

## Bindings: what a site is allowed to need

The other half of the day was the **site binding catalog**. The idea: a site shouldn't just be code plus an env file — it should be able to declare its dependencies as structured, attachable resources. I shipped a pile of new binding types:

- **Mail** and **log drain** bindings, with realtime tiers.
- **Object storage** provider presets, so attaching a bucket is picking a preset rather than hand-assembling credentials.
- A **cache** binding type, backed by provisioning **phpredis** and **gd** extensions on the box.

Conceptually a binding is a typed edge between a site and a resource. Instead of a site carrying loose strings, it carries declarations that the platform understands and can provision, validate, and render:

```yaml
# dply.yaml — sketch of the binding shape
bindings:
  mail:
    driver: ses
  cache:
    driver: redis      # provisions phpredis on the box
  storage:
    provider: do-spaces # from the object-storage presets
  logs:
    drain: realtime
```

The win is that the platform now *knows* what a site depends on. It can show you, provision the underlying pieces, and eventually gate a deploy on a missing one — none of which is possible when those dependencies are just opaque environment variables nobody remembers setting.

Alongside the catalog I landed **site database management** with a real **resource API** behind it, a full **logging config editor**, and an in-browser **dply CLI console** in site settings — gated behind a coming-soon preview for now, but the plumbing is live. On the UI side I did some overdue hygiene: a **standardized button component** (with link support) replacing inline button classes scattered across views. Boring, but inline class soup is how a design system rots.

## What bit me

Shell quoting. Always shell quoting. Two separate bugs, same root cause — the gap between what a string means in PHP and what it means once it's handed to `/bin/sh`:

- **`escapeshellarg` was corrupting the chown user during env push.** Over-escaping an argument that was already a clean username turned a valid `chown` target into garbage. The fix was to stop reflexively escaping a value that didn't need it — escaping isn't free, and applying it to the wrong thing breaks just as surely as not applying it to the right thing.
- **Pruning old releases blew up on root-owned files** until I taught it to use `sudo`. The atomic-release cleanup walked into files it didn't own and got permission-denied. Cleanup paths are where ownership assumptions go to die.

And HTTPS repo cloning needed real work. A clone can't just assume the remote is public: I added logic to **detect the git provider from the URL**, then **authenticate the clone with the stored OAuth/PAT token** instead of hoping anonymous access would work. The provider detection is what makes the token injection possible — you can't pick the right credential until you know whose API you're talking to.

## What it set up

The honest tradeoff of a thirty-six-commit day is that it's a lot of new surface area and not much time living on any of it. The binding catalog in particular is a foundation more than a finished feature — most of the binding *types* exist now, but the connective tissue (validation, deploy-time gating on missing bindings, the resource-graph view that ties them together) is still ahead.

And the provisioning speedups are the part I'm genuinely most curious to *feel*. Baked snapshots and parallel installs should make a real, visceral difference to the first thirty seconds of using dply — but "should" and "does" are different claims, and the only way to settle it is to provision a few boxes for real and watch the clock. That's next.
