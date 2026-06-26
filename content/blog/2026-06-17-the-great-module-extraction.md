---
title: "the great module extraction: carving dply into a modular monolith"
date: 2026-06-17
slug: "2026-06-17-the-great-module-extraction"
summary: "78 commits pulling tangled domain engines out of one flat Laravel app and into a tiered modular monolith — capability by capability, in dependency order."
tags: [refactor, modules, architecture, deploy, edge]
type: deep-dive
published: true
---

Today was the big one. 78 commits, 3,583 files touched, and almost every commit subject is some variation of "extract X engine into `app/Modules/X`." dply had been growing as one flat Laravel app for months, and the seams had stopped being theoretical. Edge, Cloud, Deploy, Billing, Serverless were all tangled in the same `app/Services` and `app/Jobs` piles, importing each other freely, and every new feature made the next one harder to reason about. So I spent the day carving the thing into a modular monolith — on purpose, in order, with an ADR to explain why.

## The shape: three tiers, one rule

The structure that emerged isn't "microservices in a trench coat." It's still one Laravel app, one PostgreSQL database, one deploy. What changed is that the code now has *tiers* with a deliberate dividing line: **capability vs. presentation**.

- **Modules** (`app/Modules/<Domain>`) — the engines. Self-contained domain capabilities, each owning its own `Services/`, `Jobs/`, `Console/`, sometimes its own `Livewire/` and `Http/`, wired by a `<Domain>ServiceProvider`.
- **The shell** — `app/Livewire/*` and `app/Http/Controllers/*`. The workspace UI that *drives* the engines. This stays horizontal. Capabilities extract *out* of the shell; the shell does not move into modules.
- **The kernel** — the hub models everything shares (`Site`, `Server`, `Organization`, `User`, `SiteBinding`) plus the shared `Services/`, `Jobs/`, `Support/`, and the `Actions` framework.

The one rule, the whole point of the exercise: **modules must never depend on the presentation shell.** The dependency arrow points UI → engine → kernel, and never the reverse. An engine can be driven by the UI but must never reach back into a concrete Livewire component. Drawing that line is what makes each module independently understandable — and, frankly, what makes the codebase navigable for an agent that can only hold so much in context at once.

## Extraction in dependency order

You can't extract modules in alphabetical order; you extract them in *dependency* order, leaves first, or every move breaks three other things. So the day went in waves, and the ADR commits track the waves like a progress bar.

**The clean tier first** — barely any cross-dependencies, so they came out almost for free:

- **Projects**, **RemoteCli**, **Remediations**, **Launch** (this one consolidated a messy Launch/Launches split into a single module).

**The cohesive-medium tier next** — some coupling, but tractable:

- **Realtime**, **Secrets**, **Serverless**, **Insights**, **Imports** (the Forge/Ploi migration engine).

**Then the genuinely entangled tier** — the ones that import each other and the shell and the kitchen sink:

- **SourceControl**, **Certificates**, **Notifications**, **Scaffold**, **Marketplace**, **Snapshots**, **Backups**, **Logs**, **Ai**, **Cloud**.

**And the two heaviest engines, saved for last:**

- **Edge** came out in four phases — first the engine, then jobs + commands (2a), then the Livewire pages (2b), then **27 HTTP controllers, middleware, and resources** (2c). Splitting it by layer was the only way to keep each commit reviewable and the suite green between steps.
- **Deploy** followed the same pattern — engine first (phase 1), then the site-deploy job and CLI commands (phase 2).

By end of day the ADR reads: Edge fully extracted, Deploy fully extracted, only Billing remaining. Twenty-eight modules out, one to go.

## How an extraction actually goes

Each extraction is the same four-step dance, and the discipline is in doing it the same way every time so the failures are boring instead of mysterious:

1. Move the engine's classes into `app/Modules/<Domain>/...` under the `App\Modules\<Domain>` namespace.
2. Create a `<Domain>ServiceProvider`, register bindings, commands (guarded by `runningInConsole()`), and any moved full-page Livewire components as **aliases** so existing routes still resolve.
3. Register the provider in `bootstrap/providers.php`.
4. Run the suite, fix the references the move broke, commit.

```php
// app/Modules/Edge/EdgeServiceProvider.php
namespace App\Modules\Edge;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class EdgeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Keep old component names resolving after the move.
        Livewire::component('edge.create', \App\Modules\Edge\Livewire\Create::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Modules\Edge\Console\DeployCommand::class,
            ]);
        }
    }
}
```

Step 4 is where the day lived. Moving classes by capability quietly breaks every sibling that imported one of them, and Laravel's container hides a lot of that until runtime — so a chunk of the failures only surface when you actually run the suite. A handful of tests needed their stubs realigned after the moves (`ScaffoldLaravelPipelineTest` was missing a `ScaffoldRepoSeeder` arg, `RemediationScopingTest` needed scoping to `ApplyRemediationJob`, `InsightsFeatureTest` had stale editor stubs, and `SiteDeployCoordinator::completedFixerKeys` needed a null guard on a missing latest-deployment).

## What bit me: the unqualified-sibling gotcha

The single nastiest pattern, and the one I stopped to write down in the ADR so it stops biting me: **moving a class by capability silently breaks any sibling that imported it by an unqualified relative name.** When two classes lived next to each other in `app/Services`, one could reference the other loosely and PHP's autoloader would shrug and find it. Move one of them into a module and that loose reference dangles — and because nothing is statically checking the boundary yet, the break doesn't announce itself. It waits for the one test that exercises that path.

The honest tradeoff of a day like this: it produces *zero* user-visible value. No customer woke up to a better dply on the 17th. What they got is a codebase where the next feature is cheaper, where a domain can be reasoned about without loading the whole app into your head, and where I can hand a single module to an agent without it tripping over Billing. That's a bet on future velocity, paid for entirely up front in churn and risk. The way you de-risk it is exactly this: small commits, dependency order, run the suite between every move, and an ADR capturing the *why* so future-me doesn't undo it on a whim.

## What it set up

The extraction is the substrate, not the payoff. The payoff is tomorrow: finish **Billing** (the final, most-depended-on module), then wire up **Deptrac** so the module → shell boundary can't quietly rot back into spaghetti, and write a **CLAUDE.md** map so both humans and agents can find their way around the new shape. A boundary you can't enforce is just a suggestion, and a structure nobody can navigate is just a different kind of mess. Both of those are the next day's problem — but they're only possible because today did the unglamorous carving first.
