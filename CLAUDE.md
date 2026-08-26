# CLAUDE.md — codebase map & navigation

dply is a single Laravel app (one PostgreSQL DB) that manages servers, sites,
and managed services (databases, caches, queues, realtime, logs). This file is the **structural
map**: how the code is organized and where to find things. For product/UI
**conventions** (styling, Livewire patterns, feature-flag layers, billing
model, etc.) see **`AGENTS.md`**. For the *why* of the structure see
**`docs/adr/modular-monolith-structure.md`**.

## The shape: modular monolith

Code is organized into three tiers. The dividing line is **capability vs.
presentation**: domain engines live in modules, the workspace UI that drives
them is the shell, and the hub models everything shares are the kernel.

```
app/
├── Modules/<Domain>/     ← the engines (extracted capabilities)
├── Livewire/  Http/       ← the SHELL: workspace UI + controllers + routes
├── Models/                ← the KERNEL: shared hub models
├── Services/ Jobs/ Actions/ Support/ …  ← shared kernel + infra
```

- **Modules** (`app/Modules/*`, namespace `App\Modules\<Domain>`) — self-contained
  domain engines. Each owns its `Services/`, `Jobs/`, `Console/`, sometimes its
  own `Livewire/`+`Http/`, and is wired by a `<Domain>ServiceProvider` registered
  in `bootstrap/providers.php`.
- **Shell** — `app/Livewire/*` (the server/site **workspace** components and their
  domain `*/Concerns/` traits) and `app/Http/Controllers/*`. The shell deliberately
  *stays* horizontal: workspace tabs, lifecycle UI, and routing orchestrate the
  module engines. Capabilities extract *out* of the shell; the shell does not move
  into modules.
- **Kernel** — `app/Models` hub models (`Site`, `Server`, `Organization`, `User`,
  `SiteBinding`) plus shared `Services/`, `Jobs/`, `Support/`, `Enums/`,
  the `app/Actions` framework (Attributes/Decorators/Concerns), and generic
  `app/Livewire/Concerns/*`. Everything may depend on these.

### The one enforced rule

**Modules must never depend on the presentation shell** (`app/Livewire/*`
concrete components, `app/Http/Controllers/*`). The arrow points UI → engine →
kernel, never the reverse. Enforced by `tests/Unit/ModuleBoundaryTest.php`:

```
php artisan test tests/Unit/ModuleBoundaryTest.php   # ~6s, runs in `composer test`
```

It parses every file under `app/Modules` with nikic/php-parser and fails on any
resolved reference into the shell. Generic `app/Livewire/Concerns/*`,
`app/Livewire/Forms/*` and the base `Controller` count as kernel, so modules may
use them. Imports referenced only from a `{@see}` docblock are ignored — no
runtime coupling (this matches what Deptrac counted).

Known-debt exemptions live in the test's `BASELINE` const; a *new* Module→shell
dependency fails the build. Pay one off and delete its line — a companion test
fails on stale entries so exemptions can't outlive the debt.

(Replaces Deptrac, removed 2026-08-15 — `deptrac.yaml` had been deleted in an
unrelated WIP commit three days earlier, so the boundary was silently unchecked.)

## Module map

| Module | What it owns |
|--------|--------------|
| **TaskRunner** | The SSH/remote-task framework — tasks, callbacks/webhooks, key-pair gen, resolved via `SshConnectionFactory`. Near-vendored (own Models/routes/config/Tests). All remote server control flows through here. |
| **Deploy** | VM/site deploy engine — pipelines, phases, runtime detection, scheduled deploys. |
| **Database** | Managed database engine — the `DatabaseBackend` abstraction and its DigitalOcean / Vultr / Neon / PlanetScale / Supabase / Upstash implementations, plus day-two operations on a cluster (users, resize, metrics, backups + restore-to-new, trusted-source grants). The record is still `App\Models\CloudDatabase`; the on-box `ServerDatabase` lifecycle stays in the kernel. |
| **Billing** | Revenue engine — subscriptions, Stripe sync, metering, usage cost calculators (other modules depend on these). |
| **Insights** | Site/server health, metrics, URL-health checks, cost observatory. |
| **Imports** | Server/site import flows (e.g. DO import). |
| **Secrets** | Secret vault — residency, escrow, age encryption. |
| **Logs** | dply Logs server-log add-on — Vector aggregator install/policy, ClickHouse. |
| **Certificates** | SSL/TLS issuance + renewal. |
| **Cache** | Managed cache engine — `ManagedCache`/`CacheSite` records, the Postgres-backed store, and the cache workspace UI. |
| **Providers** | Infrastructure provider clients — DigitalOcean / AWS (EC2, App Runner, EKS) / Azure / GCP compute + DNS, plus Cloudflare and Namecheap. |
| **Backups** | Site/DB backup engine. |
| **Snapshots** | Server/site snapshots. |
| **Realtime** | Managed Pusher-compatible relay (Cloudflare Workers + DO). |
| **Queue** | Managed job queue — SQS-compatible endpoint over a Postgres store, plus dply-owned worker fleets that autoscale on queue pressure (`docs/adr/dply-queue.md`, `docs/adr/managed-queue-workers.md`). |
| **Notifications** | Notification channels + event dispatch (server errors, webserver ops). Also owns the **Laravel notification drivers** under `Channels/<Provider>/` (Intercom, PagerDuty, MicrosoftTeams) registered by `NotificationsServiceProvider` — the module's only provider, added when the first driver landed. |
| **Marketplace** | Script/runbook marketplace + imports. |
| **Docs** | `/docs` front-matter docs system (manifest, contextual sidebar). |
| **Feedback** | Global feedback/bug slide-over + admin review. |
| **Referrals** | Referral codes + Stripe-credit rewards. |
| **Projects** | `Workspace` grouping container UI. |
| **Scaffold** | Repo scaffolding pipeline. |
| **SourceControl** | Git provider OAuth/integration (GitHub/GitLab/Bitbucket). |
| **Remediations** | Guided remediation jobs/services. |
| **RemoteCli** | Remote CLI execution. |
| **ConfigRevisions** | Config-file revision history. |
| **Ai** | LLM synthesis/abstraction (`dply_ai`). |
| **Launch** | Full-stack launch wizard. |

## Where do I put / find X?

- **A server/site workspace tab or page** → shell (`app/Livewire/Servers|Sites/…`).
  Even if it drives a module, the *UI* stays in the shell.
- **Domain business logic, an engine, a queued worker for a capability** → that
  capability's module (`app/Modules/<Domain>/Services|Jobs`).
- **A CLI command for a capability** → the module's `Console/`, registered in its
  ServiceProvider (`$this->commands([...])` guarded by `runningInConsole()`).
- **A hub model** (Site/Server/Organization/User/SiteBinding) → stays in
  `app/Models` (kernel). A leaf model used ~only by one module *may* move into it
  (some still pending — see the ADR).
- **Shared SSH/provisioning jobs** (provider IP polling, systemd, env-push, SSL on
  a box) → shell `app/Jobs` — modules dispatch them but don't own them.
- **A Livewire alias** for a moved full-page/embedded component → register it in the
  module ServiceProvider's `boot()` (`Livewire::component('alias', Class::class)`).
  Guard tests in `tests/Feature/LivewireAliasGuardTest.php` enforce resolution.

## Common commands

```
composer dev            # serve + queue + logs + reverb (local)
composer test           # config:clear + artisan test (Pest/PHPUnit)
composer analyse        # phpstan
composer test           # includes the module-boundary check (tests/Unit/ModuleBoundaryTest.php)
```

### Test suites

`phpunit.xml` declares four suites; `defaultTestSuite` is `Unit,Feature`, so a
bare `artisan test` runs exactly those two.

```
composer test:unit / test:feature      # one suite
composer test:modules                  # app/Modules/*/Tests   — NOT green yet
composer test:app                      # app/Actions/**/tests  — NOT green yet
composer test:arch                     # tests/Arch — Pest arch rules (~45s)
composer test:all                      # all five
composer test:parallel / test:coverage / test:profile
```

`Modules` and `App` cover the ~124 test files that live next to their code.
They went uncollected for a long time and rotted (see the comment in
`phpunit.xml`); they are registered so they can be run and paid down, but stay
out of the default run until green.

### Fast local runs (Pest TIA)

[Test Impact Analysis](https://pestphp.com/docs/tia) is configured in
`tests/Pest.php` (`pest()->tia()->locally()`) and records a dependency graph in
`~/.pest/tia/<key>` — `vendor/bin/pest --baseline` prints the path.

```
composer test:tia          # full suite, replayed  — ~30s
composer test:tia:fresh    # re-record the graph   — ~7.5min
```

Two constraints decide whether TIA engages, and both are easy to trip:

- **It only applies to whole runs.** Any `--group` / `--exclude-group` / path
  filter prints *"TIA does not apply to partial runs"* and runs normally. That
  is why `composer test` (which needs `--exclude-group=arch` for CI parity)
  does not benefit — use `test:tia` while iterating.
- **Every collected test must be a Pest test.** One PHPUnit class aborts the
  run with *"Tia mode requires Pest tests"*. `tests/` is 100% Pest as of
  2026-08-16; keep it that way or `test:tia` breaks for everyone.

`locally()` keeps TIA off in CI, per Pest's guidance — `.github/workflows/tests.yml`
must keep running the suite in full.

### Measuring coverage

Coverage is **43.5%** of statements as of 2026-08-16 (`app/`, excluding the
test dirs listed in `phpunit.xml`'s `<source>`) — 100,924 / 231,793.

Adding the `Modules` suite (`--testsuite=Unit,Feature,Modules`) takes it to
**44.2%**: TaskRunner alone jumps 6.0% → 22.0%, because all 73 module-local
test files are TaskRunner's and none of them run by default. That is the
single largest coverage lever left, but it drags in 463 failures — pay those
down before moving the suite into `defaultTestSuite`.

```
composer test:coverage:clover   # ~7min, writes coverage.xml (gitignored)
composer test:coverage          # sequential text table, much slower
```

**TIA and coverage do not combine** — measured on Pest 5.0.5, three ways:

| command | behaviour | time | number |
|---|---|---|---|
| `--tia --coverage-clover` | replays; clover sees only the re-run tests | 1min | **6.0%** ✗ |
| `--tia --coverage` | prints *"recording a coverage baseline"* and re-runs everything, **every time** — never replays | 6–7min | no table under `--parallel` |
| `--no-tia --coverage-clover` | plain full run | 6–7min | **43.2%** ✓ |

So there is no fast path to a coverage number, and **a replay-measured
percentage is not a property of the codebase** — it reports whatever TIA chose
to re-execute, which on a clean tree is essentially the currently-failing
tests. Fixing failures drives it toward 0%; it is not a metric to target.

Also: **`--coverage`'s text table does not render under `--parallel`** at all
(paratest merges worker coverage in the parent and the summary is dropped).
Use `--coverage-clover` when parallel, or drop `--parallel` — though a
sequential full run took 19min here and died on a `Premature end of PHP
process`, so clover is the practical option.

### Architecture tests

`tests/Arch/ArchTest.php` holds Pest arch rules — enums are enums, Concerns are
traits, Jobs implement `ShouldQueue`, Livewire classes extend `Component`,
controllers are suffixed, and no `dd`/`dump`/`shell_exec`/`eval` reaches app
code. Every `ignoring()` there is an inspected exception, commented in place.

They sit in their own suite (not `defaultTestSuite`) because the whole-app scan
costs ~45s and needs 2G — the file raises `memory_limit` itself, since PHPUnit's
`<ini>` setting overrides `php -d`. CI runs them as a separate step.

The **module boundary is not** an arch rule: `tests/Unit/ModuleBoundaryTest.php`
already owns it, with a BASELINE of tracked debt an arch rule would duplicate.

### Test groups

Every test carries a layer group (`unit`, `feature`, `app`, `modules`) plus
domain groups derived from its filename and directory — `servers`, `sites`,
`deploy`, `cloud`, `edge`, `serverless`, `billing`, `queue`, `console`,
`webserver`, `containers`, `livewire`, and ~25 more. The map lives at the
bottom of `tests/Pest.php`; add a token there rather than tagging files.

```
php artisan test --group=servers
php artisan test --group=sites --group=deploy        # union
php artisan test --group=billing --exclude-group=livewire
```

Domain groups register only when a `--group` / `--exclude-group` flag is
present — resolving them costs seconds per run, so unfiltered runs skip it.

## Critical do-nots (see memory / AGENTS.md for the rest)

- **Never** `migrate:fresh` / `migrate:reset` / `db:wipe` on any env (incl. testing)
  without explicit permission.
- **No SSH in the render/HTTP path** — always dispatch a queued job and poll
  (PHP 30s `max_execution_time`); resolve via `SshConnectionFactory`, never `new`.
- **Livewire single root** — full-page views with multiple top-level roots throw
  "Snapshot missing"; wrap in `<div class="contents">`.
- The user **tests manually in the browser** — don't run the test suite unless asked.

===

<laravel-boost-guidelines>
=== .ai/core rules ===

# Laravel Auditor Guidelines

These guidelines apply to any agent performing a Laravel audit with Laravel Auditor. They are intentionally agent-agnostic.

## Purpose

Laravel Auditor equips an AI coding agent with a specialized, evidence-based methodology for auditing Laravel applications. The agent is the reasoning engine; Laravel Auditor provides the methodology, structured context, rules, and tools.

## Working rules

1. **Evidence over guesses.** Every meaningful finding must reference concrete, verifiable project context: a file path and line/range, a symbol, a route, a migration, a query, a configuration key, a dependency, or a test.
2. **Prefer deterministic facts.** When a fact can be obtained programmatically (via the Laravel Auditor context tools or Artisan), obtain it that way rather than inferring it from raw text.
3. **Distinguish confirmed findings from hypotheses.** Use the confidence levels precisely. Only `confirmed` findings have full supporting evidence; everything else is labeled by its actual confidence.
4. **Say when evidence is incomplete.** If you cannot verify a high-severity claim, say so and lower the confidence. Never claim an audit is more certain than the evidence allows.
5. **Do not invent package or runtime behavior.** Verify against installed packages, their documentation, and the actual application code. Never assert behavior you have not checked.
6. **Never claim exploitability without sufficient evidence.** A finding is not automatically exploitable because the pattern is unusual.
7. **Do not recommend upgrades solely because a package is old.** Only flag a dependency when there is a concrete reason (breaking incompatibility, known vulnerability with evidence, EOL with operational impact).
8. **Style preferences are never high severity.** Keep them at `low` or `info`, or omit them.
9. **Read-only by default.** Auditing must never modify application code.
10. **Few high-quality rules over noisy volume.** A focused, trustworthy report is the product.

## Severity and confidence

- **Severity**: `critical`, `high`, `medium`, `low`, `info`.
- **Confidence**: `confirmed`, `high`, `medium`, `low`.

Use both precisely. A `confirmed` finding has verified evidence. A `high` severity claim with only partial evidence should carry `medium` or `low` confidence.

## Structured findings

Each finding should carry, at minimum:

- `rule_id` — stable rule ID when one matches (e.g. `AUD-SEC-001`).
- `id`, `rule_id`, `title`, `domain`, `severity`, `confidence`, `status`.
- `summary`, `why_it_matters`.
- `evidence` — concrete references.
- `affected_resources`, `symbol` — where relevant.
- `recommendation`, optional `remediation`, optional `verification_notes`.
- `metadata.priority` — `p0`–`p3` when you rank findings.

Write findings to JSON and render them with `php artisan auditor:report --findings=storage/auditor-findings.json`. See `guidelines/findings.md`.

## Using the tools

The Laravel Auditor context tools provide deterministic Laravel context:

- `project_info`, `routes`, `models`, `migrations`, `database_schema`.
- `dependencies`, `configuration`, `policies_authorization`, `jobs_events_schedules`, `tests`, `subsystems`.

List them with `php artisan auditor:context --list`. Prefer `php artisan auditor:rules --applicable` before investigating ecosystem-specific issues.

`dependencies.composer_audit` is **on by default**. `tests` case listing is **off by default**. Do not treat `composer_audit.available: false` or a file-count test total as proof there are no advisories or that every test case was listed. If `available` is false, read `reason` (or run `composer audit --format=json` yourself). Enable `laravel-auditor.context.test_listing` or run the test runner yourself for accurate case counts.

Prefer these tools over raw file scraping for structured facts. Use file inspection for code-level detail and tracing.

Useful commands: `auditor:status`, `auditor:rules`, `auditor:context`, `auditor:report`, `auditor:ci`, `auditor:mcp`.

## When Boost is present

Laravel Auditor extends Laravel Boost rather than replacing it. Continue using Boost's Laravel documentation search and general context. Use Auditor's audit-specific skills, rules, and context tools for the audit work itself. Do not duplicate what Boost already provides.

=== .ai/dsa rules ===

# DSA / organizing-model audit

When asked for a DSA, data-structure, ownership, or subsystem audit, use the `laravel-audit-dsa` skill.

Rules:

- Read-only. Do not implement fixes during the audit.
- Inventory subsystems first (`php artisan auditor:context subsystems`).
- One worker per ownership boundary. At most two findings per subsystem.
- Skip when the local code is already clear.
- Verify every finding yourself before it enters the report.
- Rank P0–P3. Keep P3 small. Set `metadata.priority` to `p0`–`p3` and `metadata.subsystem` to the inventory id.
- Prefer `AUD-DSA-*` rules when they fit. Otherwise use the six core domains.

Do not recommend a new type just to hide existing branching.

=== .ai/findings rules ===

# Finding schema

Every audit finding should match the Laravel Auditor finding schema (`schema/finding.schema.json`).

Required fields:

- `id` — unique finding instance id (for example `F-2026-0001`)
- `rule_id` — stable rule id when one matches (for example `AUD-SEC-001`)
- `title`, `domain`, `severity`, `confidence`
- `summary`, `why_it_matters`

Include whenever possible:

- `status` — `open` for new findings (`accepted`, `dismissed`, `fixed` later)
- `evidence` — file, route, symbol, config, migration, query, test, or dependency references
- `affected_resources`, `symbol`
- `recommendation`, optional `remediation`, optional `verification_notes`
- `metadata.priority` — `p0`, `p1`, `p2`, or `p3` when you rank the report
- `metadata.subsystem` — subsystem id on a DSA pass

Minimal shape:

```json
{
  "id": "F-2026-0001",
  "rule_id": "AUD-SEC-001",
  "title": "Missing authorization boundary",
  "domain": "security",
  "severity": "high",
  "confidence": "confirmed",
  "status": "open",
  "summary": "Any authenticated user can delete another user's post.",
  "why_it_matters": "The destroy action never authorizes the Post policy.",
  "evidence": [
    {
      "type": "file",
      "reference": "app/Http/Controllers/PostController.php",
      "line": 42,
      "end_line": 48
    }
  ],
  "recommendation": "Authorize the deletion with a PostPolicy or route middleware.",
  "metadata": { "priority": "p0" }
}
```

Write an array of findings (or `{ "findings": [ ... ] }`) to JSON and render:

```bash
php artisan auditor:report --findings=storage/auditor-findings.json
php artisan auditor:ci --findings=storage/auditor-findings.json --fail-on=high
```

An example payload lives in `examples/findings.json`. Preview it with:

```bash
php artisan auditor:report --example
```

=== .ai/performance rules ===

# Performance finding contract

These rules govern every `performance`-domain finding, on top of the core guidelines. They exist to keep performance reports trustworthy: verified, mechanistic, and free of micro-optimization noise.

## Report the pipeline, not just the pattern

A performance finding must show that you moved through the full investigation pipeline:

```text
Signal → Context → Behavior → Verification → Impact → Finding
```

Findings that skip verification or impact are rejected by this contract even when the underlying pattern is real.

## Semantic equivalence is mandatory

Before recommending any optimization, verify it produces identical observable results **in this exact usage**:

1. Confirm what else consumes the value (collection reuse invalidates most query-side rewrites).
2. Check accessors, casts, and appended attributes for PHP-only behavior.
3. Match comparison strictness and null handling between Collection and SQL semantics.
4. Respect custom collection classes and overridden methods.
5. Keep model requirements intact (primary/foreign keys for narrowed selects).
6. Account for side effects (model events on bulk writes, lazy-load timing).

Record what you checked in `verification_notes`. "This is usually faster" is not verification.

## Impact metadata

For performance findings, describe impact structurally in `metadata.impact` using mechanism wording:

```json
"metadata": {
    "priority": "p1",
    "impact": {
        "resource": "database + memory",
        "mechanism": "Retrieves every matching order into PHP memory to compute one number; the query-level aggregate returns a single row instead.",
        "amplification": "Scales with paid-order count; dashboard is rendered on every admin login."
    }
}
```

- `resource` — which cost category applies: database queries, rows transferred, memory, CPU, network, disk I/O.
- `mechanism` — what the current form wastes and what the replacement avoids.
- `amplification` — what makes the cost grow: loop counts, table growth, request frequency.

Never invent numeric measurements ("10x faster"). Describe mechanisms; cite numbers only from evidence that actually exists (a logged slow-query entry, an N+1 count from a debugbar capture).

## Severity discipline

Severity follows reach × frequency × amplification, not pattern scariness:

| Severity | Typical confirmed instance |
| --- | --- |
| high | Query explosion or heavy amplification on hot/high-volume paths |
| medium | Clear unnecessary work with real traffic or growth behind it |
| low | Real but small inefficiency on modest data |
| info | Worth knowing, not worth acting on |

Do not inflate: a correct but tiny optimization stays out of the report entirely.

## Noise floor

Do not report when any of these hold:

- The materialized collection/result has another consumer.
- The replacement cannot be shown equivalent for this usage.
- The dataset is bounded and small.
- The fix trades real readability or correctness risk for negligible gain.

A developer reading the performance section should believe every line is worth investigating.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel-octane/core rules ===

# Laravel Octane

This application uses Laravel Octane, a long-running PHP server. The application bootstraps once and handles many requests within the same process.

- Never store request-specific state in singletons or static properties, because it can leak across requests.
- Use `config('octane.server')` to detect the active driver (`swoole`, `roadrunner`, or `frankenphp`).
- Prefer scoped bindings (`$this->app->scoped()`) over singletons for per-request services.

When working on Octane-specific features (concurrency, shared tables, memory, driver configuration, testing), invoke `octane-development` for detailed rules.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== mrpunyapal/laravel-auditor/core rules ===

## Laravel Auditor

Laravel Auditor equips an **existing** AI coding agent with an evidence-based Laravel audit methodology. It does not scan the app by itself. Use it when the user asks to audit, review, or assess a Laravel application.

### Relationship to Boost

Laravel Auditor extends Laravel Boost. It does **not** replace Boost's general Laravel context. Continue using Boost's documentation search and general Laravel guidelines. Use Laravel Auditor when asked to audit, review, or assess an existing Laravel application.

### What Laravel Auditor provides

- A structured audit workflow: **Discover** project facts, **Scope** relevant domains, **Investigate** with evidence, **Verify** high-severity findings, and **Report** structured findings.
- Six audit domains: security, performance, architecture, database, testing, and Laravel conventions.
- Stable audit rules with rule IDs (e.g. `AUD-SEC-001`), severity, confidence, evidence requirements, and false-positive considerations.
- Context tools that expose deterministic Laravel facts: project info, routes, models, migrations, database schema, dependencies, configuration, authorization, jobs/events/schedules, tests, and subsystems.
- A finding schema: id, rule ID, title, domain, severity, confidence, status, summary, why-it-matters, evidence, affected resources, recommendation, remediation, verification notes, and optional `metadata.priority` (`p0`–`p3`).

### Installing

```bash
composer require --dev mrpunyapal/laravel-auditor
php artisan boost:install
```

When Boost is not installed, use the package's own installer instead:

```bash
php artisan auditor:install --agents=claude_code
```

Useful commands: `auditor:status`, `auditor:rules` (`--applicable`), `auditor:context`, `auditor:report`, `auditor:ci`, and `auditor:mcp`.

`composer audit` is **on by default**. Test-case listing is **off by default**. Do not treat `composer_audit.available: false` or an empty advisory list as “no vulnerabilities” unless the check actually ran.

### Audit skill

Use the `laravel-audit` skill when asked to audit or review a Laravel application. It contains the full workflow, evidence requirements, and severity/confidence guidance. Domain skills (`laravel-audit-security`, `laravel-audit-performance`, `laravel-audit-architecture`, `laravel-audit-database`, `laravel-audit-testing`, `laravel-audit-conventions`) go deeper once the scope is chosen. Use `laravel-audit-dsa` for a bounded subsystem / data-structure / ownership audit (`auditor:context subsystems`, P0–P3 ranking).

### Key rules

- Evidence first: every meaningful finding cites concrete file paths, lines, routes, or config keys.
- Prefer deterministic project facts over model guesses.
- Distinguish confirmed findings from hypotheses.
- Never claim exploitability without sufficient evidence.
- Read-only by default: never modify application code during an audit.
- Few high-quality findings over noisy volume.

=== mrpunyapal/laravel-auditor/dsa rules ===

## Laravel Auditor DSA

When asked for a DSA, data-structure, ownership, or subsystem audit, use the `laravel-audit-dsa` skill.

- Read-only. Do not implement fixes during the audit.
- Inventory subsystems first (`php artisan auditor:context subsystems`).
- One worker per ownership boundary. At most two findings per subsystem.
- Skip when the local code is already clear.
- Verify every finding yourself before it enters the report.
- Rank P0–P3. Keep P3 small. Set `metadata.priority` to `p0`–`p3` and `metadata.subsystem` to the inventory id.
- Prefer `AUD-DSA-*` rules when they fit. Otherwise use the six core domains.

Do not recommend a new type just to hide existing branching.

=== mrpunyapal/laravel-auditor/findings rules ===

## Laravel Auditor findings

Write structured findings, not a chat-only review. Match `schema/finding.schema.json`.

Required: `id`, `rule_id`, `title`, `domain`, `severity`, `confidence`, `summary`, `why_it_matters`.

Include whenever possible: `status` (`open` for new findings), `evidence`, `affected_resources`, `symbol`, `recommendation`, `remediation`, `verification_notes`, and `metadata.priority` (`p0`–`p3`).

Write JSON to `storage/auditor-findings.json` and render:

```bash
php artisan auditor:report --findings=storage/auditor-findings.json
php artisan auditor:ci --findings=storage/auditor-findings.json --fail-on=high
```

Preview the packaged example with `php artisan auditor:report --example`.

=== mrpunyapal/laravel-auditor/performance rules ===

## Laravel Auditor performance findings

Performance findings follow the full pipeline: Signal → Context → Behavior → Verification → Impact → Finding. A suspicious pattern is not automatically a performance bug.

### Before reporting

1. Check what else consumes the value. `$users = User::where(...)->get(); $count = $users->count();` with the collection rendered by the view is **correct code** — do not recommend `->count()`. Only materialize-then-reduce with no other consumer is a finding.
2. Verify semantic equivalence for this exact usage: accessors/casts, comparison strictness (Collection `where()` is loose, SQL is not), null handling, custom collection classes, primary/foreign keys for narrowed selects, model events on bulk writes.
3. Describe impact by mechanism — "avoids transferring every matching row into PHP" — never invented multipliers like "10x faster". Cite numbers only from real runtime evidence.
4. Add `metadata.impact` when evidence allows: `resource` (database/memory/network/IO), `mechanism` (what is wasted and what the fix avoids), `amplification` (loop counts, table growth, request frequency).

### Severity discipline

- `high`: query explosion or heavy amplification on hot paths (data-driven per-row queries, unbounded retrieval on public endpoints).
- `medium`: clear unnecessary database work, avoidable materialization, repeated queries, rendering-path queries with real traffic.
- `low`: small inefficiencies on modest data.
- Correct micro-optimizations on tiny datasets are not findings.

### Noise floor

Do not report: reused collections/results, replacements you cannot show equivalent, bounded small datasets, caching without a key/invalidation story, concurrency for dependent requests, or eager loading "just in case" (over-eager loading is itself waste).

Use `php artisan auditor:rules --domain=performance --applicable` to list the rules (`AUD-PER-*`, plus `AUD-LW-003`/`AUD-FIL-003`/`AUD-IN-003` when those packages are installed). The `laravel-audit-performance` skill contains the full methodology and checklist.

</laravel-boost-guidelines>
