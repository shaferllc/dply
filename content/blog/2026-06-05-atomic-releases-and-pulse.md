---
title: "atomic deploys, a repo picker, and a backups dashboard"
date: 2026-06-05
slug: "2026-06-05-atomic-releases-and-pulse"
summary: "A huge day: atomic immutable release deploys with symlink swap, a reworked repo picker, the backups dashboard, and a stack of deploy fixes."
tags: [deploys, ui, backups, refactor]
published: true
---

Forty-eight commits. One of those days where the dam breaks and a bunch of half-finished threads all land at once.

The headline is deploys. dply now ships **atomic immutable releases with a symlink swap** instead of mutating a checkout in place. Each deploy goes into its own release directory, and going live is just repointing `current`. Rollback becomes "point the symlink back", which is exactly the property you want at 2am. I also had to teach it to **restart the systemd worker units on the release swap** — turns out an atomic web swap means nothing if your queue workers are still running yesterday's code against today's database.

## the rest of the haul

- **Repo picker rework**: extracted the Git repository picker into a shared trait, added keyboard nav, a retry button, and labels showing *which* linked account actually answered the repo read. Falls back to the default branch when a configured branch 404s, and warns you when it does.
- **Backups dashboard** with schedule controls and a live Pulse, plus Redis/Database/Worker server cards on Pulse.
- **HTTP→HTTPS redirect** enforcement, with the deploy commit message AI-generated from the change.
- A **Realtime coming-soon panel** behind a feature flag, and a changelog that now generates titled entries during deploy.

## what bit me

The git identity resolution was the gremlin. It kept choking on decrypt failures, so I hardened that and moved it to a scoped container binding. And there was a lovely Livewire crash from a stale `repo_tab=setup` sticking around after setup had already ended — the kind of bug that only appears when you do things in the "wrong" order, which users always do.

This is the most "real platform" the deploy path has ever felt. Now I want to live on it for a few days and see what it breaks.
