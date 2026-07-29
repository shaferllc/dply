---
title: "A 23-phase migration wizard, built in a day"
date: 2026-05-14
slug: "2026-05-14-forge-ploi-migration-wizard"
summary: "Marching a Forge/Ploi import-and-migrate wizard from phase 1 into the twenties — a state machine, 13 SSH handlers, source-agnostic factories, and the rollback paths that make moving a live site survivable."
tags: [imports, migration, wizard, ssh, state-machine]
type: deep-dive
published: true
---

This was the big one: thirty-six commits, almost all of them marching a server migration wizard from "phase 1" up into the twenties. The premise is blunt — if you're on Ploi or Forge today, dply should be able to pull your inventory in and walk your sites across with minimal drama. Migration is the unsexy feature that decides whether anyone can even *start* using a new platform, so it was worth a full-day push. This is a deep dive on how it's structured and why the boring parts (rollback, idempotency, testability) got most of the attention.

## A state machine, not a script

The first real decision was to model migration as a **state machine with a wizard deep-link** (phase 2), not as a linear script. A script assumes the happy path; a migration of a live site assumes nothing. Each migration moves through explicit states, and the wizard can deep-link you into whatever state your migration is currently in — so a paused or failed migration is a resumable thing, not a lost one.

On top of the state machine sits a **step orchestrator** (phase 3a) that started life with four implemented handlers and thirteen placeholders. That placeholder-first approach was deliberate: get the orchestration shape right and prove the deep-link/progress wiring (phases 3b/c) before grinding out the actual SSH work. You want the skeleton load-bearing before you hang the heavy organs on it.

## Source-agnostic from the start

Ploi came first — connect flow, inventory sync, an `/imports` inventory page and credentials panel (phases 1a–c). But the moment you build a *second* source, any assumption that "an imported site looks like a Ploi site" becomes a landmine. So when the **Laravel Forge import driver** landed (phase 7), it came with *source-agnostic factories*: the wizard takes a normalized site, and it genuinely does not care whether that site came from Ploi or Forge.

```php
interface MigrationSource
{
    public function syncInventory(Credential $credential): Collection;   // -> ImportedSite[]
    public function fetchSiteDetail(ImportedSite $site): SiteDetail;
}

// Ploi and Forge each implement the contract; the wizard only ever
// sees the normalized SiteDetail, never the provider's raw payload.
$source = MigrationSourceFactory::for($migration->source_kind);
$detail = $source->fetchSiteDetail($importedSite);
```

The factory boundary is what keeps a third source (whenever it shows up) from rippling through the whole wizard. Normalize at the edge, and everything downstream stays provider-blind — the same instinct behind the edge-backend seam from a couple of weeks ago.

## The 13 handlers, and the part that actually mattered

The middle of the day was the grind: **implementing all 13 SSH-dependent + cutover handlers** (phase 4) and wiring the real integrations behind them — ProvisionSite, DNS adapters, SSL, manual-review gates (phase 5). This is the work that moves bytes: clone the app, provision the target, set up DNS, issue certs, cut over.

But the part I'd argue actually mattered most was phase 6: **pinning every handler's command pipeline behind injectable SSH factories.** Migration handlers run remote commands. If those commands are constructed inline with a `new SshConnection(...)`, the handler is untestable — your only test is running it against a real box and praying. By resolving the connection through an injected factory, every handler becomes a unit you can drive with a fake:

```php
final class CutoverHandler
{
    public function __construct(private SshConnectionFactory $ssh) {}

    public function handle(Migration $migration): HandlerResult
    {
        $conn = $this->ssh->for($migration->targetServer);   // fakeable in tests
        // ... atomic symlink swap, verify, return a typed result
    }
}
```

"Testable instead of a prayer" is the whole game when the failure mode is someone's production website.

## Safety is the feature

Because the failure mode is a live site, most of the back half of the day was not the happy path — it was the recovery paths. In rough order, phases 8 through 23:

- a **pre-cutover verification checklist** (phase 14) so you don't flip DNS on a half-migrated site
- a **cutover rollback UI + state transition** (phase 15) — the single most important affordance in the whole flow
- a **skip-failed-step** escape hatch (phase 21) for when one step is wedged but the migration is otherwise fine
- a **migration Abort** affordance with a confirm modal (phase 20)
- a **72h paused-migration email nudge** (phase 16) so half-done migrations don't rot silently
- a **permissions gate with 7-day SSH auto-revoke** (phase 9) — we hold access only as long as we need it
- **notification publishing** for action-required moments (phase 8), **audit logging** of lifecycle events (phase 22), and **HTTP API endpoints** (phase 23)
- **rate-limit-aware retry** on the Ploi + Forge clients (phase 18), because third-party APIs throttle and a migration shouldn't die on a 429
- **re-migration history** links on inventory pages (phase 17) and an onboarding empty-state **Migrate CTA** (phase 11)

The 7-day SSH auto-revoke is the detail I'm most glad I built early. Asking a customer for SSH access to their existing fleet is a big trust ask; the right answer is to hold that access on a leash that expires on its own, so a forgotten migration doesn't become a standing credential.

## What bit me

The honest hard part wasn't writing handlers — it was internalizing that every single one needs a defined behavior for *failure mid-flight*, because "the network blipped during cutover" is a Tuesday, not an edge case. A surprising amount of the day went into the question "if this dies right here, what state is the customer's site in, and how do they get out?" That question is why the placeholder-first orchestrator and the injectable SSH factories were worth doing up front: they're what let me reason about, and test, every one of those death points.

## On the side: splitting the containers launcher

Away from migration, I split the **containers launcher** into a Docker wizard handoff and an inline K8s path, dropped the local OrbStack targets (a dev convenience that didn't belong in the real launcher), and sorted the catalog sizes by price ascending. Small, tidy cleanups — the kind worth doing while the wizard mental model is already loaded.

## What shipped

- Migration state machine + deep-linkable wizard; step orchestrator (phases 2–3)
- All 13 SSH + cutover handlers, real integrations (ProvisionSite/DNS/SSL/manual review)
- Source-agnostic factories + a Laravel Forge import driver alongside Ploi
- Injectable SSH factories across every handler — the testability foundation
- Safety layer: pre-cutover checklist, rollback, skip/abort, 72h nudge, 7-day SSH auto-revoke, audit log, HTTP API
- Containers launcher split into Docker vs. K8s; OrbStack targets dropped

## Looking back

A genuinely satisfying day — but "phase 23" is doing a lot of work in that sentence. Most of these phases are thin slices, and the suite hasn't fully caught up to a feature this large landing this fast. If I did it again I'd interleave more tests as I went rather than backloading them. Still, the bones are right: a state machine you can resume, factories that don't care where a site came from, and rollback paths that assume the worst. That's the right posture for the one feature where a bug means someone's live site goes down.
