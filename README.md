# Dply

Single Laravel application that runs the dply platform: connect providers, provision or attach servers, manage sites, and deploy across BYO (SSH), Serverless (OpenWhisk / Lambda / DigitalOcean Functions), managed Cloud containers (DigitalOcean App Platform, AWS App Runner), and Edge static / hybrid (Cloudflare Workers + R2). All product lines share one org, one vault, one billing surface.

> **Start here:** [docs/BYO_LOCAL_SETUP.md](docs/BYO_LOCAL_SETUP.md) — step-by-step local setup.

**Repository:** the Laravel app lives at the repository root. The only other tracked piece is **`packages/dply-core`**, a small shared PHP library consumed via a Composer path repository. There are no `apps/*` Laravel installs and no separate identity service. See **[docs/MONOREPO_AND_APPS.md](docs/MONOREPO_AND_APPS.md)**.

## Product lines

All product lines ship from this one app and one Postgres database. New surfaces are gated behind Pennant flags (`surface.*`) so internal dogfooders and design partners can opt in without a redeploy.

| Surface | Status | Flag | What it is |
|---------|--------|------|------------|
| **BYO servers** | Shipped, on by default | — | Provision / attach VMs (DO, Hetzner, Linode, Vultr, UpCloud, Scaleway, AWS EC2, Equinix Metal, Fly.io). Nginx, TLS, PHP-FPM, Node, static, databases, cron, daemons, firewall over SSH. |
| **Cloud apps** | Shipped, gated | `surface.cloud` | App-first managed PaaS (Laravel-Cloud-style). Cloud apps run as containers on DigitalOcean App Platform or AWS App Runner via the `EdgeBackend` interface. |
| **Edge** | Shipped, gated | `surface.edge` | First-party Netlify-style static + hybrid SSR on Cloudflare Workers + R2. Git previews, custom domains, deploy hooks, build logs, traffic analytics. |
| **Serverless** | Shipped, gated | `surface.serverless` | FaaS via DigitalOcean Functions / OpenWhisk + AWS Lambda. Per-function flat fee, web functions, invocation logs. |
| **WordPress** | Planned | — | Managed WordPress on dply-controlled infra. Not yet implemented. |

Long-term product roadmap: [docs/MULTI_PRODUCT_PLATFORM_PLAN.md](docs/MULTI_PRODUCT_PLATFORM_PLAN.md). Edge phase plan: [docs/edge-roadmap-next.md](docs/edge-roadmap-next.md).

Across every surface you get one org-scoped vault, one billing relationship, one audit trail, one notification fabric (Slack / Discord / Telegram / Teams / webhooks at org / team / user scope).

## Quick start (summary)

```bash
composer install
cp .env.example .env
php artisan key:generate
# Create an empty PostgreSQL database matching DB_DATABASE in .env (see docs/BYO_LOCAL_SETUP.md).
php artisan migrate
npm install && npm run build
php artisan queue:work           # second terminal — provisioning / deploy jobs (required)
php artisan serve
```

Or run **`composer dev`** (server + queue + Vite + Reverb + logs together), or **`php artisan solo`** for panes (Queue, Reverb, optional Jetty when `JETTY_START_COMMAND` is set).

Then open the app URL, register, and use **Credentials** / **Servers** as needed. Full detail (DigitalOcean from localhost, tunnels, `DPLY_PUBLIC_APP_URL`, Jetty) is in **[docs/BYO_LOCAL_SETUP.md](docs/BYO_LOCAL_SETUP.md)**.

## Migrating from incumbents

dply ships import wizards for the obvious moves:

- **Forge** → `/imports/forge` (server + site inventory, env, deploy hooks)
- **Ploi** → `/imports/ploi` (servers, sites, migration progress)
- **Vercel** (Edge) → `/edge/import` (projects, env, framework presets)

After import you keep an ongoing parity view — see the [differentiation backlog](docs/DIFFERENTIATION_IDEAS.md) for where this is headed.

## API + CLI

Everything the dashboard does is scriptable.

- **Edge public REST API** (Wave A): OpenAPI spec at [`public/openapi/edge.json`](public/openapi/edge.json). Bearer-auth with org-scoped tokens (Settings → API tokens). Covers sites, deployments, previews, domains, aliases, cache purge, usage, logs, and lint.
- **`dply` CLI** ([`packages/dply-cli/`](packages/dply-cli/), published as `@dply/cli`): zero-dependency Node CLI for the Edge API. `dply login` uses OAuth device flow (GitHub-CLI-style); `dply deploy`, `dply promote`, `dply rollback`, `dply domains`, `dply usage`.
- **Fleet operator API** (internal): see below.

## Stack

- **Laravel 13**, **Livewire 4**, **Laravel Cashier**
- **PostgreSQL** (single control-plane DB; SQLite is not used)
- Encrypted storage for provider tokens and SSH private keys (`APP_KEY` required)

## Security

- Protect `APP_KEY` and use HTTPS in production.
- Do not commit `.env` or real keys.

## License

MIT.

## Fleet operator API

Internal JSON endpoints for fleet-wide dashboards (e.g. Fleet Console). Set **`FLEET_OPERATOR_TOKEN`** in `.env` here and on the console. Use **`Authorization: Bearer <token>`** or **`X-Fleet-Operator-Token`**. Unconfigured token → **503**.

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/operator/summary` | Snapshot counts / metrics |
| GET | `/api/v1/operator/readme` | Root `README.md` as JSON (`format`, `content`, `title`) |

Public product APIs are versioned under **`/api/v1/`** (see `routes/api.php`). Webhooks and UI routes are in `routes/web.php`.
