# CLAUDE.md — codebase map & navigation

dply is a single Laravel app (one PostgreSQL DB) that manages servers, sites,
and managed compute (Cloud / Edge / Serverless). This file is the **structural
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
kernel, never the reverse. This is enforced by **Deptrac** (`deptrac.yaml`):

```
composer deptrac              # check (CI-ready; exit 1 on a new violation)
composer deptrac:baseline     # regenerate baseline after an intentional change
```

Existing known-debt violations are recorded in `deptrac-baseline.yaml` — a *new*
Module→shell dependency fails the build.

## Module map

| Module | What it owns |
|--------|--------------|
| **TaskRunner** | The SSH/remote-task framework — tasks, callbacks/webhooks, key-pair gen, resolved via `SshConnectionFactory`. Near-vendored (own Models/routes/config/Tests). All remote server control flows through here. |
| **Deploy** | VM/site deploy engine — pipelines, phases, runtime detection, scheduled deploys. |
| **Cloud** | Managed-container PaaS (DO App Platform / AWS App Runner) behind `EdgeBackend`. `Actions/`, `Backends/`, `Cloudflare/`, lifecycle `Jobs/`. |
| **Edge** | First-party Netlify-style static/SSG platform (Cloudflare R2/Workers). Build/publish jobs, edge workspace UI, previews. |
| **Serverless** | FaaS (DO Functions, web functions). Adapters, `Contracts/`, create/deploy jobs. |
| **Billing** | Revenue engine — subscriptions, Stripe sync, metering, usage cost calculators (other modules depend on these). |
| **Insights** | Site/server health, metrics, URL-health checks, cost observatory. |
| **Imports** | Server/site import flows (e.g. DO import). |
| **Secrets** | Secret vault — residency, escrow, age encryption. |
| **Logs** | dply Logs server-log add-on — Vector aggregator install/policy, ClickHouse. |
| **Certificates** | SSL/TLS issuance + renewal. |
| **Backups** | Site/DB backup engine. |
| **Snapshots** | Server/site snapshots. |
| **Realtime** | Managed Pusher-compatible relay (Cloudflare Workers + DO). |
| **Notifications** | Notification channels + event dispatch (server errors, webserver ops). |
| **Marketplace** | Script/runbook marketplace + imports. |
| **Roadmap** | Public roadmap + admin kanban + post-deploy AI auto-update. |
| **Docs** | `/docs` front-matter docs system (manifest, contextual sidebar). |
| **Feedback** | Global feedback/bug slide-over + admin review. |
| **Referrals** | Referral codes + Stripe-credit rewards. |
| **Projects** | `Workspace` grouping container UI. |
| **Scaffold** | Repo scaffolding pipeline. |
| **SourceControl** | Git provider OAuth/integration (GitHub/GitLab/Bitbucket). |
| **OpsCopilot** | Fleet/infra deploy-failure triage. |
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
composer deptrac        # module-boundary check
```

## Critical do-nots (see memory / AGENTS.md for the rest)

- **Never** `migrate:fresh` / `migrate:reset` / `db:wipe` on any env (incl. testing)
  without explicit permission.
- **No SSH in the render/HTTP path** — always dispatch a queued job and poll
  (PHP 30s `max_execution_time`); resolve via `SshConnectionFactory`, never `new`.
- **Livewire single root** — full-page views with multiple top-level roots throw
  "Snapshot missing"; wrap in `<div class="contents">`.
- The user **tests manually in the browser** — don't run the test suite unless asked.
