# ADR: Modular monolith — `app/Modules/*` structure

Status: accepted (2026-06-17)

## Context

`app/` is ~3,500 PHP files laid out **horizontally** (by technical type: `Services/`, `Livewire/`, `Actions/`, `Jobs/`, `Models/`, `TaskRunner/`, …), with domain sub-foldering inside each type (`Services/Billing`, `Livewire/Billing`, …). The pain is **findability**: a single domain's code is scattered across ~12 top-level dirs. The Logs domain alone is ~126 files spread across 12 dirs, and has two service homes (`Services/Logs` **and** `Services/Logging`).

This is **not** a coupling-enforcement effort. We are reducing scatter, not erecting hard boundaries between domains.

A precedent already exists: `composer.json` maps `"App\\Modules\\TaskRunner\\": "app/TaskRunner/"` and `app/TaskRunner/*` declares `namespace App\Modules\TaskRunner`. It was never rolled past TaskRunner, and the folder/namespace don't align.

## Decision

1. **Shape.** Flip to domain-first modules: `App\Modules\<Domain>` → `app/Modules/<Domain>`. Folder matches namespace. **No new composer PSR-4 entry is required** — the existing `"App\\": "app/"` already resolves `App\Modules\…` → `app/Modules/…`; a move simply *deletes* any per-module special-case (as the TaskRunner pilot did with `"App\\Modules\\TaskRunner\\": "app/TaskRunner/"`). `app/Modules/*` filling up is the progress bar; `app/*` shrinking to a shared kernel is the goal. Inside each module, replicate the familiar type-folders (`Services/`, `Jobs/`, `Livewire/`, `Console/`, domain `Models/`).

2. **Domains (~13).** Servers, Sites, Deploy, Edge, Serverless, Cloud, Billing, Logs, Backups, Marketplace, Secrets/Credentials, Platform, Integrations. The long tail of single-occurrence folders nests under these.

3. **Shared kernel** (stays at `app/` root, depends on nothing; everything may depend on it): `Providers/`, `Http/{Kernel, Middleware, base Controller}`, `Console/Kernel`, `Exceptions/`, `helpers.php`, cross-cutting `Support/`, shared `Enums/`/`Contracts/`, and the **hub models** `Site`, `Server`, `Organization`, `User`, `SiteBinding` (+ their pivots).

4. **Model-move rule.** A model moves into module X only if **≥90% of its references are within X** *and* **fewer than 5 other modules reference it**. By this test `Site` (~1,163 refs), `Server` (~1,189), `Organization`, `User`, `SiteBinding` (spans Logging/Mail/Storage/Broadcasting) are permanently kernel. Almost all of the 200 models stay put in phase 1; only true leaf models migrate.

5. **Platform modules.** `Ssh` (SshConnection, TaskRunner, factories — remote-exec capability) and `Cloud` (provider SDK wrappers: Hetzner/AWS/DO/…) are modules that are *allowed* to be depended-on-by-many, like the kernel. Layering rule: **feature → platform → kernel**, never sideways or upward. Enforced by convention/review now; **Deptrac** added once 2–3 modules exist.

6. **What moves into a module:** PHP classes only — `Services`, `Jobs`, `Actions`, `Console` commands, `Listeners`, `Observers`, Livewire **classes**, domain **Models**.

7. **What stays global (phase 1):**

   | Artifact | Reason |
   |---|---|
   | Blade views (incl. `resources/views/livewire/*`) | ~600 view-path strings (`view('…')` + Livewire `render()`); re-keying is high silent-break risk, zero findability gain |
   | Migrations (`database/migrations`) | timestamp-ordered; per-module dirs break ordering |
   | Routes (`web.php`) | mostly full-page Livewire; just update class FQCNs |
   | Factories, `config/` | tied to mostly-kernel models / global by nature |

   A module's Livewire class moves; its Blade view does not (`render()` keeps returning `view('livewire.billing.invoice')` pointing at the unmoved global view). Moving views is a possible **later** phase, gated on a second guard test asserting every `view('…')` string resolves.

8. **Livewire alias preservation.** Moving a component to `App\Modules\…\Livewire` stops Livewire auto-discovery, so each module's `ServiceProvider` re-registers its components via **convention + override map**: the convention `<oldsubpath>` reproduces the old `billing.*` alias exactly (old `Livewire/Billing` subfolder == new module name); the handful of non-conforming aliases (top-level `dashboard`, `command-palette`, …) get explicit override entries. A **guard test** greps every `<livewire:…>` / `@livewire(…)` alias out of `resources/views` and asserts each resolves to a registered class. **Written first**, green on today's layout, kept green through every migration.

9. **Execution — strangler by PR.** Per-module recipe, identical every time:
   1. Branch at a quiet moment for that domain (no open feature branch touching it).
   2. `git mv` class files into `app/Modules/<X>/…`; rewrite `namespace` + `use` via Rector/scripted sed (compiler-checked → misses fail loud).
   3. Add `App\Modules\<X>\<X>ServiceProvider` to `bootstrap/providers.php` (registers Livewire aliases + module bindings).
   4. `composer dump-autoload`; run **phpstan/larastan + alias guard test + full suite**. Green = done.
   5. **Behavior-zero PR** — moves files and updates references, nothing else — merged same-day to slam the rebase window shut.

   **Sequence:** TaskRunner (pilot — no Livewire, no Models; also realigns the broken precedent) → Feedback (small, validates alias machinery on a real feature) → big domains (Servers, Sites, Deploy, Billing, Logs) in **branch-availability order**.

## Definition of done (per module)

Alias guard test + full suite + phpstan green. A module isn't "done" until all three pass.

## Hard rules

- **Move PRs change zero behavior.** The `Logs`/`Logging` consolidation and the `Deploy`/`DeployContract`/`DeployIntelligence` collapse are **separate later PRs**, never folded into a move.
- **Never move a domain with an unmerged feature branch.** Sequence against the branch backlog, not against tidiness urgency.

## Consequences

- Laravel 13 / `bootstrap/providers.php`: each module adds one provider entry.
- "Feature in one folder" becomes "feature *behavior* in one folder": the `Site` model lives in the kernel, and (phase 1) Blade views stay global — so a Billing screen's class is in `app/Modules/Billing/Livewire` while its view is in `resources/views/livewire/billing`.
- **TaskRunner pilot done (2026-06-17):** `app/TaskRunner` → `app/Modules/TaskRunner` (literal name kept; namespace `App\Modules\TaskRunner` unchanged → zero `use`-statement churn across 293 referencing files). Composer special-case deleted. The move also required updating ~24 hardcoded `base_path('app/TaskRunner/Tests/fixtures/...')` strings, `phpstan-livewire-scan.neon`, and 5 maintenance scripts — the kind of path-coupling each future move must grep for. Renaming TaskRunner → `Ssh` is deferred (it would force a 293-file `use` rewrite — a separate rename, not a relocation).
- **Lesson for the recipe:** add "grep for hardcoded `app/<Dir>/` path strings (fixtures, neon, scripts, config)" as an explicit step — namespace rewrites are compiler-checked, but path strings fail silently.
- Pre-existing latent fatal found in `tests/Feature/TaskRunnerCancellationTest.php` (trait/class `$callbackUrl` visibility+default conflict); unrelated to the move, left for a separate fix per the behavior-zero rule.
- **Feedback module done (2026-06-17):** first move with real namespace changes (7 files: Livewire `Sidebar` + admin `Index`, `PruneFeedbackAttachmentsCommand`, `FeedbackScreenshotController`, 2 notifications). Established the module-ServiceProvider pattern: `App\Modules\Feedback\FeedbackServiceProvider` (in `bootstrap/providers.php`) re-registers the `feedback.sidebar` Livewire alias (broken by moving out of `App\Livewire` auto-discovery) and re-registers the console command (broken by moving out of `app/Console/Commands` auto-discovery). Full-page components (`Index`) and the controller are referenced by `::class` in `routes/web.php` — no alias needed, just FQCN updates. `render()` used explicit `view('livewire.…')` strings, so global views needed no change. `FeedbackReport` model **deferred** (kept in `app/Models`) per the model rule. Verified: autoload, `artisan list`, `schedule:list`, `route:list`, alias guard test.
- **Lesson for the recipe:** moving classes out of framework auto-discovery dirs (`App\Livewire\*`, `app/Console/Commands`) silently de-registers them — the module ServiceProvider must explicitly re-register Livewire components (alias) and commands. Grep for `::class` route refs and `$schedule->command(...)` refs too.
- **Roadmap module done (2026-06-17):** richer move — 16 files across Services, Support, Console×2, Jobs, Mail, Livewire×2 (full-page). Two important lessons surfaced (both caught by the domain's 22 existing tests, not by `route:list`/autoload):
  1. **Rewrite scope must include `database/`** (and `config/`), not just `app routes tests`. A `database/factories/RoadmapReleaseFactory` referencing a moved `Support\` class broke until the rewrite was extended there. Migrations rarely matter; factories/seeders do.
  2. **Full-page route components (`Route::livewire(uri, ::class)`) ALSO need explicit registration**, not just embedded-alias components. Moving the class out of `App\Livewire` breaks Livewire's class→name derivation at *render* time; `route:list` resolves the route fine, so only an HTTP-render test catches it. Register each with its original auto-derived name (`Livewire::component('roadmap.index', RoadmapIndex::class)`). **This retroactively fixed a latent bug in the Feedback module's admin `Index`** (untested there — verified by parity).
- **Full-page guard added (2026-06-17):** `LivewireAliasGuardTest` now has a second test that enumerates full-page route components from the route table and asserts each resolves the way the router binds them (`livewire.factory->resolveComponentClass`, no instantiation). **Scoped to `App\Modules\*` on purpose** — resolving *all* app full-page components is unsafe: it triggers autoload of every page class, and a single one with an uncatchable compile-time fatal kills the whole runner (see next). Proven to bite: neutering a registration turns it red and names the component + route + fix.
- **Pre-existing fatal found & fixed (unrelated to the migration):** building the guard surfaced an uncatchable trait-collision fatal in `App\Livewire\Servers\Create\StepWhere` — `ManagesProviderCredentials` (empty default hook) and `ServerCreateActions` (real impl) both declared `afterProviderCredentialStored`, introduced in a `wip` commit, meaning `/servers/create`'s StepWhere page was 500-ing on `main`. Resolved with `ServerCreateActions::… insteadof ManagesProviderCredentials`. This is the same class of latent bug as the TaskRunnerCancellationTest `$callbackUrl` conflict — worth a sweep for other trait collisions independent of the module work.
- **Models deferred** for Roadmap too (`RoadmapItem`/`Release`/`Suggestion`/`AiRun` stay in `app/Models`); note this created a transitional `app/Models` → module `Support\` dependency (the models `use` the moved `RoadmapQuarter`/`RoadmapReleaseTrain`), which argues those models should eventually follow into the module.
- **Referrals module done (2026-06-17):** cleanest move (7 files, self-contained `Services/Referrals` + clearly-named strays). Exercised two new vectors, both **explicitly registered** so the global FQCN rewrite handled them with zero extra wiring: a **listener** (`Event::listen(WebhookReceived, ProcessReferralInvoicePayment)` in `AppServiceProvider` — the `use` + `::class` both repoint) and **middleware** (`CaptureReferralCode` in the `bootstrap/app.php` stack — `use` repoints). Full-page `profile.referrals` registered in the module provider (full-page guard now covers it). `ReferralReward` deferred. Behaviorally verified: 5 `ReferralTest` cases pass, incl. the webhook→listener→reward chain.
- **Inventory-first is load-bearing:** picking `Status` then `Backups` by name/size both misfired — `Status` was 2 real files (rest were name-collisions in other domains), and `Backups` is a cross-cutting capability woven through Servers/Sites/Settings/Notifications namespaces (not isolable without boundary decisions). Always inventory path-based + flat-name + namespace-root before committing to a domain; entangled/hub-adjacent domains (Backups, Logs, Servers, Sites) are deferred, plan-separately work.
- **Docs module done (2026-06-17):** 10 files (5 Services, Support, Livewire `Sidebar`, controller, 2 commands; `GenerateActionDocsCommand`/`actions:docs` left in `App\Actions\Console` as Actions tooling, not the docs subsystem). Two more silent-break vectors surfaced:
  1. **Rewrite scope must also include `resources/`** — Blade views hard-reference class FQCNs (`app(\App\Support\Docs\ContextualDocResolver::class)->…`, 12 view files). Full canonical scope is now: `app routes bootstrap database config tests resources`.
  2. **Controllers that `extends Controller` with no explicit import** rely on same-namespace resolution of `App\Http\Controllers\Controller`; moving the namespace silently breaks it. Add `use App\Http\Controllers\Controller;` when relocating such a controller. (Earlier controllers happened to import it; `DocsController` did not.)
  - 3 stale `DocsSidebarTest` cases fail (they read the **retired** `docs.groups`/`docs.markdown` config registries, replaced long ago by the front-matter manifest) — pre-existing, unrelated to the move (config data byte-identical to main); left for a separate test-cleanup.
- **ConfigRevisions / OpsCopilot / Imports done (2026-06-17):**
  - **ConfigRevisions** — pure service-layer module (no Livewire/commands/models) → **no ServiceProvider needed at all**; just relocate + repoint consumers.
  - **OpsCopilot** — Services + Job + one full-page Fleet component; provider registers only the full-page alias (`fleet.ops-copilot`). Jobs need no registration (dispatched by class).
  - **Imports** (largest, 47+ files) — Services with Forge/Ploi/Handlers sub-namespaces, 4 full-page components, 3 commands, plus a **Policy and Observer**. Key learning: Policy (`Gate::policy`) and Observer (`Site::observe`) were **explicitly registered in AppServiceProvider**, so the global FQCN rewrite repointed them with zero extra work — no convention-discovery breakage. Only convention-*discovered* policies/observers (`#[ObservedBy]`, Model→Policy naming) would need re-registration; this codebase wires them explicitly. The many other `Import`-named classes belong to other domains (Edge/Certificates/Marketplace/Sites) and stayed put — "Imports" the domain = the Forge/Ploi migration feature only.
- **Pre-existing failures fixed (all confirmed via stash, unrelated to moves):** TaskRunner `$callbackUrl` trait collision; Docs tests reading retired `docs.groups` config (→ DocsManifest); `ServerConfigFileEditorTest` stale probe index (engine list grew 6→8, php glob shifted to index 8); `LlmSynthesizerTest` ran the local `claude` CLI instead of the faked HTTP path (didn't set `dply_ai.llm.provider`). Pattern: every "failure" the refactor surfaced was a real latent bug/stale test, fixed at root cause.
- **Projects / Remediations / RemoteCli / Launch done (2026-06-17):** routine moves. Projects (full-page Index/Show). Remediations + RemoteCli are pure service/job capabilities (no provider). Launch **consolidated the Launch/Launches split** into one module (Services+Support+Livewire). Fixes surfaced & root-caused along the way: `SiteDeployCoordinator` null-deploy 500 (real prod bug), `InsightsFeatureTest` saveTarget-signature-drift crash + its masked `SiteDeployCoordinator` 500, and a `RemediationScopingTest` false positive — the over-broad `Queue::assertNothingPushed()` caught a mount-time job; **verified via an isolated probe that `forSite()` scoping is correct (no cross-tenant leak)** before narrowing to `assertNotPushed(ApplyRemediationJob::class)`.
- **Clean routine-move tier exhausted (13 modules).** Remaining domains fall in two buckets:
  - **Too thin for a standalone module** (anti-fragmentation): `Storage` (1 file — `ObjectStorageBucketProvisioner`, belongs with Backups/bindings) and `Webhooks` (2 files in `Services\Webhooks`; the other ~20 "webhook" classes are cross-cutting across Actions/Cloud/Edge/Sites/Notifications). Fold these into broader modules later rather than create 1–2 file modules.
  - **Entangled / hub-adjacent — need per-domain boundary planning, not mechanical moves:** Backups, Logs, Servers, Sites, Billing, Certificates, SourceControl, Cloud, Edge, Deploy, Snapshots, Scaffold, Marketplace, Secrets, Serverless, Realtime, Notifications, Webserver, Fleet, Ai. Each needs an explicit boundary call (e.g. "is `WorkspaceBackups` a Backups module or a Servers tab?", "what stays in `App\Services\Servers` vs moves to a Backups/Snapshots module?").
- **Canonical recipe (current):** inventory (path+flat+namespace+strays) → `git mv` → rewrite FQCNs/namespaces across `app routes bootstrap database config tests resources` → fix relocated flat-file namespace decls + any same-namespace base-class imports → module ServiceProvider (re-register commands, Livewire aliases incl. full-page route components) → register provider → `dump-autoload` + `optimize:clear` → verify (autoload, `artisan list`, `route:list`, both Livewire guards, the domain's own tests) → grep for stale refs repo-wide. Models deferred unless a clean leaf.
- Deferred for later phases: moving Blade views into modules; consolidating split folders; Deptrac enforcement.
