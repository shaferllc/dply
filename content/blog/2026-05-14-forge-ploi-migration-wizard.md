---
title: "a whole migration wizard in a day"
date: 2026-05-14
slug: "2026-05-14-forge-ploi-migration-wizard"
summary: "Built out the bulk of the Forge/Ploi import-and-migrate wizard end to end, and split the containers launcher into Docker vs K8s paths."
tags: [imports, migration, wizard, servers]
published: true
---

This was the big one. Thirty-six commits, and most of them were marching a **server migration wizard** from "phase 1" all the way up into the twenties. If you're on Ploi or Forge today, the whole point is that dply should be able to pull your inventory in and walk your sites across with minimal drama.

The arc of the day, more or less in order:

- **Ploi first** — connect flow, inventory sync, an `/imports` inventory page and credentials panel (phases 1a–c).
- A **migration state machine** with a wizard deep-link (phase 2), then a step orchestrator with a handful of real handlers and a pile of placeholders (phase 3).
- Then the grind of **implementing all 13 SSH-dependent + cutover handlers** (phase 4) and wiring the real integrations behind them — ProvisionSite, DNS adapters, SSL, manual review (phase 5).
- A **Laravel Forge import driver** (phase 7) so the whole thing isn't Ploi-only, with source-agnostic factories so the wizard doesn't care where a site came from.
- Then the safety and polish layers: a pre-cutover verification checklist, cutover rollback UI, a skip-failed-step escape hatch, a 72h paused-migration email nudge, permissions gate with 7-day SSH auto-revoke, audit logging, and HTTP API endpoints (phases 8 through 23).

## what bit me

Migrations are unforgiving because the failure mode is someone's live site. So a lot of today wasn't the happy path — it was the rollback, the "this step failed, here's how you recover," and pinning every handler's command pipeline behind injectable SSH factories so the whole thing is testable instead of a prayer.

On the side, I split the **containers launcher** into a Docker wizard handoff and an inline K8s path, dropped the local OrbStack targets, and sorted the catalog sizes by price. Tidy.

A genuinely satisfying day. Migration is the unsexy feature that decides whether anyone can even get started, so getting it most of the way to real felt good.
