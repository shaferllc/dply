---
defract:
  version: 1
  generated_at: "2026-06-14T00:00:00Z"
  updated_at: "2026-06-14T00:00:00Z"
  source: extracted
---

# Project Profile

## Overview

dply is a Laravel-based server and site management platform (similar to Laravel Forge) that provisions, deploys, and monitors PHP/Node/serverless applications across multiple cloud providers (DigitalOcean, Hetzner, AWS, Azure, Vultr). It supports atomic deploys, Livewire-driven UI, multi-runtime targets (VMs, containers, serverless functions), and self-deploys itself in production.

## Stack

- **Runtime**: PHP 8.3
- **Framework**: Laravel 13
- **Frontend**: Livewire 4 + Tailwind CSS 4 + Vite 7, Alpine.js (via Livewire/Blaze), CodeMirror 6
- **Styling**: Tailwind CSS 4 (with `@tailwindcss/vite` plugin), Tailwind Forms + Typography plugins
- **Queue / WebSockets**: Laravel Horizon 5, Laravel Reverb 1 (and Cloudflare Workers relay in prod)
- **Feature flags**: Laravel Pennant
- **Billing**: Laravel Cashier (Stripe)
- **Auth**: Laravel Socialite, Passkeys (`laravel/passkeys`)
- **Monitoring**: Laravel Pulse
- **MCP**: `laravel/mcp` (remote streamable-HTTP MCP server)
- **Testing**: Pest 4 + PHPUnit 12, paratest for parallelism
- **Package manager**: npm (frontend), Composer (backend)
- **CI/CD**: GitHub Actions (`tests.yml`) — Pest suite against PostgreSQL 16

## Conventions

- **Livewire-first UI** — all interactive UI lives in `app/Livewire/**` components and traits; raw controllers only for downloads, webhooks, or OAuth — see MEMORY
- **No synchronous SSH in render paths** — SSH is always queued via Jobs; Livewire renders collapse N probes into one deferred bash script (`wire:init` pattern)
- **Queue all SSH operations** — dispatched jobs, never inline; PHP 30s `max_execution_time` enforcement
- **Atomic releases** — deploys use immutable release folders + `current/` symlink swap via `AtomicSiteDeployer`
- **Pest** — test suite is being migrated from PHPUnit to Pest via drift; `tests/Unit` done, `tests/Feature` pending — see `tests/Pest.php`
- **Pint** for PHP formatting (dev dep `laravel/pint`)
- **PSR-4 autoloading** — `App\` → `app/`, `App\Modules\TaskRunner\` → `app/TaskRunner/`
- **No `migrate:fresh` / `db:wipe`** in any environment without explicit permission — project policy

## File Structure

```
dply/
├── app/
│   ├── Actions/          # Single-action classes (server/site operations)
│   ├── Console/          # Artisan commands
│   ├── Enums/            # PHP 8.1+ enums (DeploymentMethod, etc.)
│   ├── Events/           # Laravel events
│   ├── Http/             # Controllers (thin; mostly Auth, webhooks, downloads)
│   ├── Jobs/             # ~200 queued jobs (SSH ops, deploys, provisioning)
│   ├── Livewire/         # Primary UI layer (Servers, Sites, Billing, Admin, …)
│   │   ├── Servers/
│   │   ├── Sites/
│   │   ├── Billing/
│   │   └── … (20+ subdirectories)
│   ├── Mcp/              # MCP tool definitions (laravel/mcp)
│   ├── Models/           # Eloquent models (Server, Site, Deployment, …)
│   ├── Policies/         # Laravel policies
│   ├── Providers/        # Service providers
│   ├── Services/
│   │   ├── Deploy/       # Deploy engines (VM, Docker, K8s, Serverless, Lambda)
│   │   ├── Billing/
│   │   ├── Certificates/
│   │   ├── Cloudflare/
│   │   └── … (cloud provider adapters: DO, AWS, Azure, Hetzner, Vultr)
│   ├── Support/          # Value objects, helpers, SSH, TaskRunner
│   └── TaskRunner/       # Async SSH task execution module
├── config/               # Laravel + custom configs (remediations, features, subscription, …)
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── packages/
│   ├── dply-cli/         # Laravel Zero CLI
│   ├── edge-worker/      # Cloudflare Worker (Edge platform)
│   ├── log-parser/       # Local path package
│   ├── nginx-config/     # Local path package
│   └── realtime-worker/  # Cloudflare Worker (managed Pusher-compatible relay)
├── resources/
│   ├── css/              # app.css + deploy-pipeline.css (Tailwind 4)
│   ├── js/               # app.js, passkeys, file-browser-editor, roadmap-admin-dnd
│   └── views/livewire/   # Blade templates co-located by Livewire component
├── routes/               # web.php, api.php, auth.php, ai.php, channels.php, console.php
├── tests/
│   ├── Feature/          # Feature tests (Pest, ~1210 passing)
│   └── Unit/             # Unit tests (Pest-converted)
└── deploy/               # Atomic deploy scripts + ATOMIC_RELEASES.md
```

## Key Dependencies

### Frontend
- `tailwindcss@^4.2.2` — utility-first styling (v4, `@tailwindcss/vite` plugin)
- `vite@^7.3.3` — bundler via `laravel-vite-plugin`
- `laravel-echo@^2.3.1` + `pusher-js@^8.4.3` — real-time client (connects to Reverb or Cloudflare relay)
- `codemirror@^6.0.1` + `@codemirror/*` — in-app code/config editors (PHP, YAML, JSON, XML, Nginx)
- `sortablejs@^1.15.7` — drag-and-drop (roadmap admin)

### Backend
- `laravel/framework@^13.0` — application framework
- `livewire/livewire@^4.0` — primary UI layer
- `laravel/horizon@^5.45` — queue management + worker visibility
- `laravel/reverb@^1.9` — WebSocket server (local dev; prod uses Cloudflare relay)
- `laravel/cashier@^16.5` — Stripe billing
- `laravel/pennant@^1.23` — feature flags
- `laravel/pulse@^1.7` — server/app performance monitoring
- `laravel/mcp@^0.8.0` — MCP server (9 tools, 2 resources; PR1 built)
- `laravel/passkeys@^0.1.0` — passkey authentication
- `phpseclib/phpseclib@^3.0` — SSH2 client (inline SSH execution)
- `aws/aws-sdk-php@^3.373` — S3, EC2, EKS, Lambda, App Runner, SES
- `symfony/http-client@^8.1` — outbound HTTP (cloud provider APIs)
- `laravel/socialite@^5.25` — OAuth (GitHub, GitLab, Bitbucket)
- `pestphp/pest@^4.7` — test framework

## Build Commands

| Command | Description |
|---------|-------------|
| `composer dev` | Start server, queue, pail (log tail), Reverb — kill-others mode |
| `composer dev:all` | Same + Vite dev server |
| `composer dev:horizon` | Use Horizon instead of queue:listen |
| `npm run dev` | Vite dev server only |
| `npm run build` | Production asset build |
| `composer test` | Run Pest suite (clears config first) |
| `composer setup` | Full bootstrap: install, key gen, migrate, npm build |
| `php artisan horizon` | Start Horizon (prod workers) |

## Project-Specific Notes

- See `AGENTS.md` for agent/AI collaboration conventions (no `CLAUDE.md` present — `AGENTS.md` is the equivalent).
- Prod has three separate `.env` files: web app (`shared/.env`), worker (`shared/.env` on worker box), and local dev (`.env`). These drift — `check-env-drift.sh` + `ENV_SYNC.md` document the problem.
- The app self-deploys itself (`AtomicSiteDeployer`); `deploy.sh` is break-glass only.
- CI runs Pest against PostgreSQL 16; local dev uses SQLite (`database.sqlite`).
- Feature flags (Pennant) gate nearly every new surface; `pennant:purge` is required when flipping a `workspace.*` default.
- Local packages at `packages/log-parser` and `packages/nginx-config` are path-symlinked via `composer.json`.
- `packages/edge-worker` and `packages/realtime-worker` are Cloudflare Workers (JS/TS), not PHP.
- The MCP server at `/mcp` reuses existing `dply_` API tokens — no OAuth required.
