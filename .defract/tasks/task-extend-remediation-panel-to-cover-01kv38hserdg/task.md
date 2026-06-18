---
defract:
  id: task-extend-remediation-panel-to-cover-01kv38hserdg
  type: improvement
  status: active
  stage: implementation
  phase: 0
  total_phases: 1
  priority: normal
  source: backlog
  source_id: bli-extend-remediation-panel-1
  branch_strategy: worktree
  mode: human-in-the-loop
  created_by: tshafer
  assignee: tshafer
---


## Story Brief

Promoted from backlog item `bli-extend-remediation-panel-1`.

- Module: resources/views/livewire/sites/partials/deployments/_remediation-panel.blade.php
- Labels: starter

Original paste from the builder:

> The `_remediation-panel.blade.php` currently only surfaces an inline fix action for `database_connection_failed`. Extend `config/remediations.php` and the panel to handle at least one other common failure type (e.g. missing env var or composer install failure) so more deploy errors get guided recovery.

# Extend remediation panel to cover additional deploy failure types

# Extend remediation panel to cover additional deploy failure types

## What We're Building

When a deployment fails, dply can show an inline "guided recovery" panel that explains what went wrong and offers a one-click fix. Today this only appears for one specific failure (a database connection problem). This task extends that guided recovery to cover at least one more common deployment failure, so more failed deploys give the user a clear explanation and a path to fix it instead of a raw error.

## Expected Outcome

- When a deploy fails for a newly-covered reason, the user sees a plain-language explanation of what went wrong and why.
- The user gets a guided next step (an inline fix action or clear instructions) instead of having to interpret raw deploy log output.
- At least one additional common failure type (such as a missing required environment variable, or a failed dependency install step) is recognized and gets its own guided recovery.
- Failures that are not yet covered continue to behave exactly as they do today, with no regression.

## Phase Outcomes

- **Phase 1: Recognize failed dependency installs and offer a guided fix** — When a deploy dies because installing the app's dependencies ran out of memory or otherwise failed, the user now sees a plain-language explanation and a one-click recovery action instead of a wall of raw log output, then can re-deploy. Other failures keep behaving exactly as before.

## Out of Scope

- Automatically fixing failures without the user confirming the recovery action. (Auto-remediation already exists as a separate opt-in concept; this task does not change that policy, and the new recovery is offered as ask-first.)
- Covering every possible deploy failure type — this task adds guided recovery for one more common case (failed dependency install), not an exhaustive catalogue. Other candidates (missing required environment variable, build/asset-compile failures) are deliberately left for follow-up.
- Redesigning the overall look and feel of the deployment failure screen. The existing recovery panel is reused as-is.
- Building a new inline "state-aware" fix component like the database flow has. The new failure type is served by the existing one-click action panel, not a bespoke guided component.

## Scope Summary

**Size:** 5 requirements, 6 acceptance criteria, 1 implementation phase
**Key decisions:**
- Cover **composer (dependency) install failure** as the new type — it is common, currently unrecognized, and fixable with a root-level script the existing panel already knows how to render.
- Serve it through the existing one-click action path (`script` action), not a new "guided" inline component, keeping the change to config + tests.
- Make the out-of-memory case the recommended, idempotent fix (ensure swap space) while still recognizing non-actionable dependency-resolution failures with an explanation only.
**Biggest risk:** A too-broad signature regex could mis-match unrelated log lines (e.g. the word "Killed" or "memory") and surface the composer fix on the wrong failure; the signature must be anchored to composer-specific phrasing and covered by tests for both match and non-match.

## Context

The deploy remediation system matches a failed deployment's log output against a catalog of failure signatures (`config/remediations.php`), and the `_remediation-panel.blade.php` partial renders a "dply recognized this failure" Fix panel with one or more actions. The Builder's Brief states the panel "currently only surfaces an inline fix action for `database_connection_failed`", but the catalog has since grown to also cover phpredis, PDO drivers, missing nginx vhost, and disk-full — so the panel is already generic over `script`- and `handler`-backed actions (see `resources/views/livewire/sites/partials/deployments/_remediation-panel.blade.php` and `app/Services/Remediations/RemediationCatalog.php`). What is genuinely missing is recognition of a failed dependency install, which is one of the most common early-deploy failures on small VMs (composer running out of memory) and config issues (unresolvable requirements). Matching is performed by `RemediationCatalog::match()` against the combined `log_output` + `phase_results` text assembled in `SurfacesDeploymentRemediation::deploymentFailureText()`. Remediation scripts run as root over SSH via `ApplyRemediationJob`.

## Requirements

### Catalog entry

- R1: Add a new remediation entry to `config/remediations.php` keyed `composer_install_failed` (or similarly descriptive) whose `signature` regex matches the representative composer-install failure phrasings: an out-of-memory abort during install (e.g. `Allowed memory size of N bytes exhausted`, `proc_open(): fork failed`, `Killed` adjacent to a composer step) and an unresolvable-requirements abort (e.g. `Your requirements could not be resolved to an installable set of packages`, `Problem 1` from composer). The regex must be anchored to composer-specific phrasing so it does not match unrelated failures.
- R2: The entry must provide a plain-language `title` and `explanation` consistent in tone with the existing entries (sentence case, explains what failed and why, no raw log jargon).
- R3: The entry must provide at least one actionable `script` action keyed and labelled clearly, marked `recommended`, that addresses the common out-of-memory case — ensure swap space exists so `composer install` can complete on a low-RAM box. The script must be idempotent and non-destructive (safe to re-run); mark it `auto_safe` only if it meets the catalog's idempotent + non-destructive + high-confidence bar. (Follow the script conventions of the existing `disk_full` / `php_ext_redis_missing` entries.)

### Panel behaviour

- R4: The existing `_remediation-panel.blade.php` must render the new remediation's action(s) for a failed deployment whose log matches the new signature, using the same panel UI as the other script-backed remediations — no new component. Confirm no panel template change is required beyond what the generic action loop already provides; if a change is required, keep it minimal and consistent with the existing markup.

### Regression safety

- R5: All currently-recognized failures (`database_connection_failed`, `php_ext_redis_missing`, `php_pdo_driver_missing`, `webserver_vhost_missing`, `disk_full`) must continue to match their own signatures and be unaffected by the new entry, and deploy logs that match none of the catalog signatures must continue to surface no panel.

## Acceptance Criteria

- [ ] `config/remediations.php` contains a new entry whose `signature` matches a representative composer out-of-memory failure log and a representative unresolvable-requirements failure log; verified by a catalog-match test asserting `RemediationCatalog::match($log)` returns the new code for both sample logs.
- [ ] The new entry's `signature` does NOT match an unrelated failure log (e.g. a database-connection error or arbitrary "Killed" text outside a composer context); verified by a test asserting `match()` returns the correct other code (or null) for those inputs.
- [ ] The new entry exposes at least one `script` action with `key`, `label`, and `recommended` set; verified by a test asserting `RemediationCatalog::action('<code>', '<action-key>')` resolves and is non-null.
- [ ] Loading the deploy panel for a failed deployment whose log matches the new signature renders the "dply recognized this failure" panel with the new action button; verified by a Livewire test on `DeploymentDetail` (or the deploy hub) asserting the title/label is present.
- [ ] Every previously-recognized remediation still matches its own signature; verified by existing remediation tests remaining green plus an assertion that the five prior codes resolve via `match()` for their sample logs.
- [ ] The full Pest suite for the remediation surfaces passes; verified by `vendor/bin/pest tests/Feature/Livewire/Sites/RemediationScopingTest.php` and the new catalog test, with the new behaviour covered.

## Implementation Phases

### Phase 1: Recognize composer-install failures and offer a guided fix
**Scope:** Teach the deploy recovery system to recognize when a deployment failed while installing the app's dependencies, and give the user a clear explanation plus a one-click recovery action for the common out-of-memory case, reusing the existing recovery panel. Failures already covered keep behaving exactly as before.
**Files:**
- `config/remediations.php` — add the `composer_install_failed` remediation entry (signature, title, explanation, one or more `script` actions).
- `tests/Feature/Livewire/Sites/RemediationScopingTest.php` (or a new sibling test under `tests/Feature/`, e.g. `RemediationCatalogTest.php`) — add catalog match/non-match coverage and a panel-render assertion for the new entry.
- `resources/views/livewire/sites/partials/deployments/_remediation-panel.blade.php` — only if a minimal markup change proves necessary; expected to need none (the generic action loop already renders script actions).
**Verification:**
- New catalog test asserts `match()` returns the new code for both sample composer logs and the correct other-code/null for unrelated logs.
- New test asserts the new entry's `script` action resolves via `RemediationCatalog::action()`.
- Livewire test asserts the panel renders the new action button for a matching failed deployment.
- Existing `RemediationScopingTest` and handler allow-list tests remain green (run `vendor/bin/pest tests/Feature/Livewire/Sites/RemediationScopingTest.php tests/Feature/Jobs/ApplyRemediationHandlerAllowListTest.php`).
**Estimated effort:** Small

## Edge Cases

- **Composer step succeeds but a later step fails with "memory"/"Killed" text:** the signature must be anchored to composer phrasing so a generic OOM elsewhere does not falsely surface the composer fix.
- **Unresolvable-requirements failure (a code/lockfile problem, not fixable by swap):** recognize it for the explanation, but do not imply the swap action will fix it — the explanation should be honest that a dependency-resolution failure needs a code/lockfile change. (If a single entry cannot serve both cleanly, the implementer may split into two entries or scope the actionable fix to OOM only — decide during implementation; the actionable script must only be offered where it actually helps.)
- **Box already has sufficient swap/RAM:** the swap script must be idempotent — detect existing swap and no-op rather than stacking swapfiles or failing.
- **No log output / empty failure text:** `match()` already returns null on empty text; the new entry must not change that.
- **Multiple catalog entries could match the same log:** `match()` returns the first matching entry in declaration order. Place the new entry so it does not shadow or get shadowed by an unintended earlier entry (e.g. `disk_full`); verify ordering with a test if the new signature overlaps any existing one.

## Technical Notes

- Matching is first-match-wins in `config/remediations.php` declaration order (`RemediationCatalog::match()`), against the combined `log_output` + recursive `phase_results` strings (`SurfacesDeploymentRemediation::deploymentFailureText()`). Order the new entry to avoid accidental shadowing.
- `script` actions run as root over SSH via `ApplyRemediationJob`; follow the defensive bash conventions in the existing `php_ext_redis_missing` / `disk_full` scripts (guard with `|| true` where appropriate, echo a clear success line, `exit 1` with a diagnostic on genuine failure). A swap-ensuring script should check `swapon --show` / `/swapfile` before creating, use `fallocate`/`dd` + `mkswap` + `swapon`, and persist via `/etc/fstab` idempotently.
- `auto_safe` is an opt-in auto-remediation flag — only set it if ensuring swap is genuinely idempotent, non-destructive, and high-confidence. When unsure, omit it (ask-only), matching `disk_full`.
- The panel already branches on `empty($remediation['guided'])`; a plain `script`-backed entry (no `guided` key) renders through the standard action loop with no template change. Do not set `guided` unless you intend to build a bespoke inline component (out of scope).
- Reuse the existing `RemediationScopingTest` helpers (`ownedSite()`, factory setup) for any new Livewire assertion to stay consistent with the suite. Per project policy, do not run `migrate:fresh`/`db:wipe`; the suite uses `RefreshDatabase`.
- Keep the change surgical: this is a catalog + tests change. Avoid touching unrelated remediation entries or the panel markup unless strictly required.
