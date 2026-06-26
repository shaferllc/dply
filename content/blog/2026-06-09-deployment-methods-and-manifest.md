---
title: "deployment methods, a canonical manifest, and unsticking the deploy button"
date: 2026-06-09
slug: "2026-06-09-deployment-methods-and-manifest"
summary: "A two-axis deployment-methods model, a canonical dply manifest that drives the deploy gate, and finally killing the deploy button that wouldn't let go."
tags: [deploys, manifest, ui, refactor, php-fpm]
type: deep-dive
published: true
---

Sixty-two commits in a day is usually a sign I've been avoiding something, and looking back at this one I'd been avoiding three things at once. They turned out to be related: how a deploy is shaped, how the app declares what it needs, and what happens to the UI when either of those goes sideways. This is the day I stopped hand-waving at all three.

The headline work is a real **deployment-methods model**, a **canonical manifest** that finally drives the deploy gate, and the deeply unglamorous fix for a deploy button that would stick on "Deploying…" forever. None of these is glamorous in isolation. Together they're the difference between a deploy engine you trust and one you babysit.

## A two-axis model for "how do I cut over?"

For a long time "deployment method" lived in my head as a vague intention rather than a thing the system knew about. Some sites wanted zero-downtime atomic releases; some legacy boxes were fine taking a maintenance window; a few needed a full recreate. That variety was being expressed by branching logic scattered across the deploy path, which is exactly the kind of implicit state that bites you at 2am.

So I built a **two-axis `DeploymentMethod`**: one axis is the *cutover strategy* (atomic / maintenance / recreate), the other carries the rest of the deploy shape. The win isn't the enum itself — it's that everything now resolves down onto a single atomic engine underneath, with the method choosing how the final swap happens rather than forking the whole pipeline.

- **atomic** — immutable release directory, then a `current` symlink swap. The default.
- **maintenance** — drop a maintenance page, deploy in place, lift it.
- **recreate** — tear down and rebuild, for the cases where in-place is a lie.

Switching methods now **auto-migrates** the site's config instead of leaving you in a half-converted state, which was the trap with the old approach. And while I was in there I fixed an ordering bug I'd been quietly stepping around: the **health check and env-apply** were running in the wrong sequence, so a deploy could report healthy against the *previous* environment. Subtle, wrong, and exactly the kind of thing a unified engine makes visible.

## The manifest that actually has teeth

The part I'm most excited about is the **canonical manifest**. dply has had a `dply.yaml` notion for a while, but it was advisory — a thing you could write that the platform would mostly ignore. This is the day it grew teeth.

The schema now spans **four formats plus a healthcheck**, validated by a `dply:manifest:validate` command that's CI-friendly (exit non-zero on a bad manifest, no interactive prompts). But the real change is that **env declarations in the manifest drive the deploy gate**. If the manifest says the app needs `STRIPE_SECRET` and the environment doesn't have it, the deploy stops *before* the build instead of failing three minutes later with a stack trace.

```yaml
# dply.yaml
runtime:
  language: php
  version: "8.4"

healthcheck:
  path: /up
  expect_status: 200

env:
  required:
    - APP_KEY
    - DATABASE_URL
    - STRIPE_SECRET
  defaults:
    LOG_CHANNEL: stderr
```

Behind that there's a **code-shape reconciler** that reads the repo and compares it to what the dashboard thinks is true. Where they agree, the UI shows **managed read-only rows** — you can see that a value is coming from the manifest, not guess. Where they diverge you get **revert / apply / export**, so the manifest and the dashboard can argue and you get to referee.

The design rule I held the line on: **safe removal**. If a key disappears from the manifest, dply does not silently wipe it. It flags the removal and reverts to the dashboard value, because "your config quietly vanished on deploy" is the kind of surprise that ends trust in a platform. The reconcile path is also **gated and wired pre-build** in the VM deploy, so a bad reconcile can't take down a running site mid-flight.

What shipped on the manifest front:

- canonical schema foundation — 4 formats + healthcheck
- `dply:manifest:validate` for CI
- code-shape reconciler with managed-row markers, wired into the VM deploy pre-build
- env declarations driving the deploy gate, capturing defaults
- UI surface — managed read-only rows, revert / apply / export
- safe removal — flag + revert, never a silent wipe

## The deploy button that wouldn't let go

Here's the bug that generated the most user-visible frustration and the least architecture: the **deploy button would stick**. You'd click Deploy, the button would flip to "Deploying…", and then — if the job hit a lingering lock or failed in a way the UI didn't hear about — it would spin forever. The deploy was *done*; the button just never found out.

Two things were wrong. The lock could outlive the job that took it, so a new deploy saw a held lock and parked. And failures weren't surfacing fast enough — the UI's only signal was "still running," which is indistinguishable from "quietly dead." The fix was to **surface deploy failures sooner** and let the button release on a terminal state rather than waiting for a success it would never see.

This is the recurring tax of the queue-everything-and-poll model dply runs on: the worker knows the truth, the browser is guessing, and any gap between them looks like a hang. Worth it for not doing SSH in the request path — but you pay for it in exactly these spinner bugs.

## What bit me

The pre-push git hooks. I'd added **AI generators for the changelog and roadmap** that run on `git push`, routing synthesis through the local `claude` CLI provider. Lovely idea, except they'd **hang the push** — the CLI would sit waiting on a stdin that was never coming. The fix was a **hard timeout plus detaching stdin** on the pre-push generators, because nothing erodes goodwill faster than a git hook holding your terminal hostage while you're trying to ship.

There was also the long tail of env-drift work that I keep circling back to: **per-site PHP-FPM pool tuning**, a **worker env compare**, and the root-cause fix that **derived workers now inherit the parent app's env and RESOURCES**, not just static env. App-vs-worker drift has been a quiet source of "works on web, fails on the queue" bugs, and this closes a chunk of it.

## What it set up

The through-line of the day is *making the deploy engine legible*. The deployment-methods model means the system knows how it's deploying instead of inferring it. The manifest means the app can declare what it needs and have that enforced, not hoped for. The button fix means the human watching gets told the truth. Each one removes a place where I was the missing integration layer holding two halves together by memory.

If I'd do anything differently, it's the git hooks — I shipped the AI generators before I'd thought about the failure mode of an interactive CLI in a non-interactive context, and that's a lesson I apparently have to relearn every time I put an LLM in a pipeline. Detach stdin, set a timeout, assume it will hang. Future me, take note.
