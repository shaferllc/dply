# Dply

Single Laravel application that runs the dply platform: connect providers, provision or attach servers, manage sites, and deploy across BYO (SSH), Serverless (AWS Lambda, DigitalOcean Functions, etc.), Docker, and Kubernetes engines. Future product lines (Cloud, WordPress, Edge) re-enter as additional engines + modules in this same app.

> **Start here:** [docs/BYO_LOCAL_SETUP.md](docs/BYO_LOCAL_SETUP.md) — step-by-step local setup.

**Repository:** the Laravel app lives at the repository root. The only other tracked piece is **`packages/dply-core`**, a small shared PHP library consumed via a Composer path repository. There are no `apps/*` Laravel installs and no separate identity service. See **[docs/MONOREPO_AND_APPS.md](docs/MONOREPO_AND_APPS.md)**.

## Product focus

- **Active:** BYO + Serverless + Docker + Kubernetes engines, all served from the root app.
- **Planned:** Cloud, WordPress, Edge — added as new engines + modules in this same app when their behavior is real.

Long-term product roadmap: [docs/MULTI_PRODUCT_PLATFORM_PLAN.md](docs/MULTI_PRODUCT_PLATFORM_PLAN.md).

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

## Features (BYO)

- **DigitalOcean**: API token → create droplets (region/size), SSH key injection (see in-app flows).
- **SSH**: Dashboard and jobs run commands via **phpseclib**; key-based auth.
- **Existing servers**: Add by IP + user + private key.
- **Billing**: Laravel Cashier (Stripe) available when configured.

## Stack

- **Laravel 13**, **Livewire 4**, **Laravel Cashier**
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

