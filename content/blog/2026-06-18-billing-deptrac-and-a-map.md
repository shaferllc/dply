---
title: "billing, deptrac, and a map for the maze"
date: 2026-06-18
slug: "2026-06-18-billing-deptrac-and-a-map"
summary: "Closing out the modular monolith: the final Billing module, a Deptrac wall to enforce the boundary, a CLAUDE.md navigation map, and a long day paying the test tax."
tags: [modules, billing, deptrac, tests, architecture]
type: deep-dive
published: true
---

Yesterday I extracted almost everything into modules. Today I closed it out — and then paid the tax. The extraction itself is satisfying right up until you run the suite and discover how many things were quietly leaning on the old layout. So this post is two stories: finishing the architecture (Billing, Deptrac, a map), and the much longer, less photogenic story of un-breaking the tests that the move surfaced.

## The final module: Billing

**Billing** came out as the twenty-ninth and final module — and it was deliberately last, because half the other modules lean on it. It owns subscriptions, Stripe sync, metering, and the usage cost calculators that Cloud, Edge, Serverless, and Logs all call into when they need to know what something costs. You can't extract the thing everyone depends on first; you extract it once everything that depends on it already knows where it lives.

With Billing out, the ADR officially reads **"entangled-tier migration complete (29 modules)."** That's the whole codebase carved into engines.

The last structural piece was bringing the **leaf models home**. A model used by exactly one module doesn't belong in the shared kernel — it belongs with its engine. So:

- the Cashier `Subscription` and `SubscriptionItem` models moved into **Billing**
- `MarketplaceItem` moved into **Marketplace** (with a factory resolver so `factory()` still finds it)
- four more — Roadmap, Serverless, Backups, Realtime leaf models — moved into their respective modules

The hub models (`Site`, `Server`, `Organization`, `User`, `SiteBinding`) stay in the kernel, because they're genuinely shared. The line is "used by ~one module" moves out; "used by everything" stays put.

## Deptrac: making the boundary load-bearing

Here's the thing about an architectural rule that lives only in an ADR: it's a polite suggestion. The whole point of yesterday's work was that **modules must never depend on the presentation shell** — UI → engine → kernel, never the reverse — and a rule like that decays the instant someone (me, at 11pm) imports a Livewire component into a service because it's convenient.

So today I added **Deptrac** to turn the rule into a wall. It statically analyzes the dependency graph and fails the build if a module reaches back into `app/Livewire/*` concrete components or `app/Http/Controllers/*`.

```yaml
# deptrac.yaml (sketch)
layers:
  - name: Modules
    collectors:
      - { type: directory, value: app/Modules/.* }
  - name: Shell
    collectors:
      - { type: directory, value: app/Livewire/.* }
      - { type: directory, value: app/Http/Controllers/.* }

ruleset:
  Modules: []          # modules may depend on nothing in the shell
  Shell:
    - Modules           # the shell may drive the engines
```

```bash
composer deptrac            # check — exits 1 on a new violation, CI-ready
composer deptrac:baseline   # regenerate the baseline after an intentional change
```

The realist's move here is the **baseline**. The codebase isn't pristine — there's known debt, a handful of existing module → shell dependencies that I'm not going to fix today. Deptrac records those in `deptrac-baseline.yaml` so the build stays green on *existing* sins while failing loudly on any *new* one. You don't get to "perfect" in one commit; you get to "can't get worse," which is the property that actually matters for a boundary you want to hold for months.

## CLAUDE.md: a map for the maze

Twenty-nine modules is a lot of doors in a hallway. So I wrote **CLAUDE.md** — a structural map of the whole codebase. Not conventions (those live in AGENTS.md), not the *why* (that's the ADR), but the *where*: the three-tier shape, a table of every module and what it owns, and a "where do I put / find X?" section that answers the question I actually ask twenty times a day.

- A server/site workspace tab → the shell, even if it drives a module.
- Domain business logic or a queued worker for a capability → that capability's module.
- A CLI command for a capability → the module's `Console/`, registered in its provider.
- A hub model → stays in the kernel; a leaf model may move into its module.

It's mostly for the agents, honestly — a fresh context window can't grep its way to good architectural judgment, but it can read a map. But it's also for me at 11pm, which turns out to be the same audience with the same problem: limited context, needs to find the right file fast, will otherwise put it in the wrong place.

## The tax: a long day of tests

The rest of the day — most of the day — was tests. Moving classes around surfaces every stale reference and assumption that the old flat layout had been hiding, and a few genuine bugs hiding *behind* those references.

The standout: `SiteDoctorCommand` was calling `trim()` on a **nullable `env_file_path`**, which crashed the command and took six tests down with it. That's the null-guard antipattern that keeps finding me — `trim(null)` is fine until strict types, and then it's a fatal. The fix is a guard; the lesson is to stop assuming a nullable column is a string.

The bigger slog was the **`SiteTest` suite**, which needed a broad retarget to the architecture as it actually exists now rather than as it existed when the assertions were written:

- workspace tests pointed at the old monolithic component, retargeted to `WorkspacePipeline` and the `WorkspaceTools` / `WorkspaceOverview` split
- create-form path assertions updated to `/home/dply/<domain>` (the path convention changed under them)
- routing-mutation and cert-repair tests updated to **async queued toasts** instead of synchronous results — the queue-everything model means the assertion is "a toast event fired," not "the thing happened inline"
- docker/k8s runtime-target checks converted to the `WorkspacePipeline` component
- the `CloudCreateWizard` tests migrated to the new multi-database `$databases[]` model and finished 6/6 green with an `Http::fake` for the DO cost-propose call

Buried in that churn was a **real AWS Lambda deploy bug** in the Serverless path, exposed by a test that was finally exercising the right code. That's the one genuinely nice thing about a day of test-fixing: occasionally the churn hands you a free production fix you'd never have gone looking for.

## What bit me, and what it set up

The thing I made sure to write into the ADR before I forgot it again: the **bidirectional sibling gotcha**. Moving a class doesn't just break the things *it* imported — it breaks anything that imported *it* by an unqualified name, in *both* directions, and the container hides the breakage until runtime. After two days of this I've learned to assume every move has a blast radius I can't see, and to treat a green suite — not a clean grep — as the only proof a move is done.

Two days, twenty-nine modules, a boundary that's now enforced instead of hoped for, and a map so the next person (or agent) doesn't have to reverse-engineer the shape. The honest cost is that none of it shipped a feature — it's pure substrate. But the boundary is drawn and guarded now, and that changes the economics of everything I build next. If I'd do one thing differently, I'd have written CLAUDE.md and stood up Deptrac *first*, on day one — because doing the extraction without a map or a guardrail meant I was holding the whole new structure in my head the entire time, and that's exactly the thing the map exists to make unnecessary.
