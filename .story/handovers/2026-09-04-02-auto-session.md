# Session handover — test-debt triage: ISS-017, ISS-015, ISS-018

**Date:** 2026-09-04 · **Branch:** `apt-source-resilience` · Working tree clean, 3 commits pushed to the branch (not to main)

Targeted autonomous session over three test-debt issues. All three resolved. Two of the three turned out to be filed inaccurately, and the third's stated cause was wrong — the corrections are the substance of this session.

## What shipped

### ISS-017 — already fixed, ledger closed (`3901fca9`)

The signature fix had landed in `94ea1c4f5`: `createFromGroup`/`createFromTags` no longer default `$table` to `'servers'`, and both `DispatchesTaskRunnerToServers` callers require and pass it. Verified against HEAD (`ConnectionManagerTest`: 100 passed, 134 assertions) rather than re-implemented.

One real residue fixed: `md/CONNECTION_FEATURES.md` still demonstrated both calls against `'servers'` — the exact call that throws on dply, since that table has neither a `group` nor a `tags` column. The docs were still advertising the capability the signature change had just withdrawn.

### ISS-015 — four causes, not one (`f5198f89`)

`TaskShowCommandTest` went 16 failing / 22 passing → 4 failing / 34 passing.

1. Table labels (`'User: x'`, `'Exit Code: 0'`, the `'-'` placeholder) asserted against `--format=json` runs. JSON spells those as keys and encodes `null`, not `-`.
2. Output and error bodies asserted without passing `--output` / `--error`. Both surfaces gate those fields behind their flags, so the assertions could never match.
3. **`expectsOutputToContain()` cannot match two substrings in one write.** Laravel registers one Mockery `doWrite` expectation per substring and each write is consumed by a single expectation; `--format=json` emits the whole document in ONE `line()` call, so only the first substring can ever match however correct the output is. Verified empirically. Those tests now capture via `Artisan::output()` + `toContain()`. Recorded as **L-004** — the failure message names the substring and points at the command, so this reads as a product bug and cost most of the debugging time.
4. `'"progress": null'` was simply wrong: `progress` is derived, and `getProgressAttribute()` maps `Pending` to 0.

One command change, deliberately not test-only: `outputTable()` printed *nothing* when `--output` was passed for a task with no output, so asking for output and getting silence was indistinguishable from a broken flag. It now says `No output available`. Without that, the two empty/null-output tests could only have been made green by asserting nothing is printed — the trap ISS-015's own text warned about. The `--error` path still has the same silence; noted, not fixed blind.

### ISS-018 — the filed diagnosis was wrong, and the real cause is worse (`ec7a7268`)

The report blamed the undefined `api` guard. That guard is real and fixed, but it was not what silenced the run.

**`AuthenticatedDecorator::handleUnauthenticated()` ended its non-JSON branch with `redirect()->route($route)->send(); exit;`.** Proved by printing markers either side of a direct `handle()` call: the first printed, the redirect HTML dumped to stdout, the second never ran. `exit` terminates the process outright — the runner died mid-test with zero bytes, which is why nothing named the responsible line. This was never test-only: the same call from a queue worker kills the worker mid-job, and skips terminable middleware. Now throws `HttpResponseException`. Recorded as **L-005**.

**Second defect, fixed at the root:** `DecorateActions::hasMethod()` uses `method_exists()` (visibility-blind) while `callMethod()` used `call_user_func_array()` from the decorator's scope. A `protected` hook passed the guard and then died in `__call` claiming the method does not exist. `AsAuthenticated`'s own docblocks document `getAuthGuard()`, `getAuthRedirectRoute()` and `handleUnauthenticated()` as `protected` — so the documented shape was the broken one, for every decorator using the trait. `callMethod()` now reflects when the method genuinely exists, keeping `call_user_func_array()` for names `__call` legitimately serves.

**The App suite now reports a summary.** 53 failed / 8 skipped / 103 passed, where it previously printed none at all. Those 53 match a per-file baseline taken before any change, file for file, and the 11 decorator tests are unchanged — so the shared-trait change regressed nothing.

## Decisions

- **`config/auth.php` deliberately untouched.** Named-guard support IS intended (property hook, method hook, and an `AuthenticatedDesignPattern` behind it), but dply genuinely has no `api` guard and adding one so a test can pass would be backwards. The test defines it for its own duration, plus a new companion test that a user authenticated only on the *default* guard is still rejected — without it the original passes just as happily if `$authGuard` is ignored entirely.
- **Root-cause fixes in the shared trait, not per caller.** One guard in `callMethod()` beats making one test action's method public and leaving ~30 decorators broken.
- **ISS-001 / T-001 excluded from this session.** Their remaining scope is confirming the real `repo.mysql.com` key URL *and fingerprint* and pinning it in `server_provision.mysql_repo_key_fingerprints`. That is a trust anchor for package installs on production hosts and needs out-of-band verification from a networked machine; an autonomous session would either fabricate a fingerprint or spin at PLAN.
- **ISS-013 excluded** — its own text says deleting the empty test file needs the owner's approval.
- **Modules/App suites run directly, never via `composer test`.** Both are outside `defaultTestSuite`, so a default run would not have executed any of the code touched here — a green `composer test` would have been meaningless evidence.

## Filed, not absorbed

- **ISS-019** — `TaskShowCommandTest` creates tasks the schema rejects (null name; 1000-char name vs `varchar(255)`). Same class as ISS-016. Needs a decision, not a mechanical edit: assert the boundary the schema enforces, or make `name` genuinely nullable/longer via migration.
- **ISS-020** — `task:show` silently picks the newest of several same-named tasks (`->latest()->first()`); its test expects an error. Product decision; both behaviours defensible. The same ambiguity exists in sibling commands that resolve by name.
- **ISS-021** — the missing-argument test expects exit 1, but Symfony throws before `handle()` runs. The command and the expectation are both fine; only the mechanism is wrong (assert the throw).
- **ISS-022** — **RefreshDatabase migrations intermittently deadlock against `dply_testing`.** Two Postgres backends contend during migration; three identical consecutive runs gave 2-failed, then clean, then clean. This makes any RefreshDatabase run non-deterministic, which directly undermines using these suites to measure progress. Worth checking whether anything long-running resolves to the testing database, and whether parallel/TIA runs can overlap migrations on one shared test DB.

## Next

1. **ISS-022 first if the Modules/App suites are going to be paid down** — until runs are deterministic, neither a red nor a green result is trustworthy evidence.
2. **T-001 / ISS-001** remain the live production bug and need a human with network access to confirm the MySQL key URL and fingerprint.
3. ISS-019 and ISS-020 both need a decision from you before anyone implements them.
4. The `--error` silence in `outputTable()` mirrors the `--output` one that was fixed; trivial to make symmetric if wanted.
5. `T-004`'s status is still unresolved from the previous handover — its implementation landed but the ticket is open pending your confirmation, and the provisioning fixes still have no ticket.
