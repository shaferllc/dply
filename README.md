# Dply (BYO)

**Bring-your-own-server** platform: connect providers, provision or attach servers, manage sites, and deploy over **SSH**. This repository’s **primary app** is the **Laravel application at the repository root** (not under `apps/`).

> **Start here:** [docs/BYO_LOCAL_SETUP.md](docs/BYO_LOCAL_SETUP.md) — step-by-step local setup for BYO only.

**Monorepo:** This repo also contains `apps/dply-cloud`, `apps/dply-auth`, and `packages/dply-core`. Serverless provider and Lambda/Bref support now live in the root app. See **[docs/MONOREPO_AND_APPS.md](docs/MONOREPO_AND_APPS.md)** for how the remaining apps relate to the root app, **per-app `composer install`**, and separate databases.

## Product focus

- **Active (default):** **BYO** — develop and run from the repo root with one `.env` and one database.
- **On hold** for day-to-day onboarding: separate product apps under `apps/` (**Cloud**) and future **WordPress** / **Edge** lines. They are **not** required to run BYO; each has its own Composer install and database when you choose to work on them.

Long-term multi-product context (rollout order, separate DBs): [docs/MULTI_PRODUCT_PLATFORM_PLAN.md](docs/MULTI_PRODUCT_PLATFORM_PLAN.md).

## Quick start (summary)

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # if using SQLite
php artisan migrate
npm install && npm run build
php artisan queue:work           # second terminal — provisioning / deploy jobs
php artisan serve
```

Then open the app URL, register, and use **Credentials** / **Servers** as needed. Full detail, troubleshooting, and optional services are in **[docs/BYO_LOCAL_SETUP.md](docs/BYO_LOCAL_SETUP.md)**.

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

