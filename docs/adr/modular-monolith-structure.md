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
- Deferred for later phases: moving Blade views into modules; consolidating split folders; Deptrac enforcement.
