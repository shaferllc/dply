# dply Edge (Phase G)

> **Product priority:** Team focus is **BYO first** ([docs/BYO_LOCAL_SETUP.md](../../docs/BYO_LOCAL_SETUP.md)). This app is **not** required for BYO local development unless you are explicitly working on the Edge product line.

**Monorepo:** [docs/MONOREPO_AND_APPS.md](../../docs/MONOREPO_AND_APPS.md) explains how this directory relates to the repo root, `dply-core`, and separate `composer install` / databases.

Fifth product app in the monorepo: **git-native JS and static** sites (control plane MVP: projects API, deploy records, stub engine). **Separate Laravel install and database** from BYO, Serverless, Cloud, and WordPress.

## Marketing site (`/`)

The default **`/`** route renders a branded landing page (Tailwind v4, same palette as the main dply app). **Log in**, **Register**, **Dashboard**, **Features**, **Pricing**, and **Docs** links target the **main BYO application** — set **`DPLY_MAIN_APP_URL`** in `.env` to that app’s origin (e.g. `https://dply.test`). Accounts use **Fortify on the main site**; this app does not duplicate web auth.

- **`public/build`** (Vite output) is **committed** so the marketing layout loads without running Node on first clone. If you change `resources/css` or `resources/js`, run **`npm install && npm run build`** (or **`npm run dev`** while iterating).
- Logo asset: `public/images/dply-logo.svg` (same mark as the root app).

## Local setup

```bash
cd apps/dply-edge
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # or set DB_DATABASE=dply_edge on MySQL/Postgres
php artisan migrate
php artisan test
php artisan serve
```

### Troubleshooting

- **HTTP 500** with `MissingAppKeyException` / “No application encryption key” in `storage/logs/laravel.log`: **`APP_KEY`** in **`apps/dply-edge/.env`** is empty or missing.
  - **New setup:** copy **`.env.example`** to **`.env`** (the example includes a valid local **`APP_KEY`**).
  - **Already have `.env`:** run **`php artisan key:generate --force`** from **`apps/dply-edge`**, or paste the **`APP_KEY=`** line from **`.env.example`** into your **`.env`**.
  - Then run **`php artisan config:clear`** if you have ever run **`config:cache`**.
- **Valet / Herd path:** the site must point at **`…/apps/dply-edge/public`**. Link from the app folder: **`cd apps/dply-edge && valet link dply-edge`** (not from the monorepo root). Wrong path → wrong **`.env`** → missing key.
- **`ViteManifestNotFoundException`:** run **`npm install && npm run build`**, or use the committed **`public/build`** from the repo.

## Monorepo

- Composer depends on [`shaferllc/dply-core`](../../packages/dply-core) via **path** (`../../packages/dply-core`).
- Platform plan: [MULTI_PRODUCT_PLATFORM_PLAN.md §9 Phase G](../../docs/MULTI_PRODUCT_PLATFORM_PLAN.md#phase-g--dply-edge-fifth-product).

## Control plane API

Bearer **`EDGE_API_TOKEN`**:

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/edge/projects` | Paginated; optional `q` filters `name` / `slug`. |
| `POST` | `/api/edge/projects` | `name`, **`slug`** (kebab), optional `settings`, `credentials` (encrypted; never returned). |
| `GET` | `/api/edge/projects/{slug}` | Project + `has_credentials` + **`latest_deployment`**. |
| `PATCH` | `/api/edge/projects/{slug}` | `name`, `settings`, `credentials`. |
| `POST` | `/api/edge/deploy` | Required **`project_slug`**; optional **`application_name`**, **`framework`** (`next`, `nuxt`, `astro`, `static`, `remix`; default **`EDGE_DEFAULT_FRAMEWORK`**), **`git_ref`**. Optional **`Idempotency-Key`**. Returns **202**; queue runs **`EdgeDeployEngine`** stub until real builds/CDN exist. |
| `GET` | `/api/edge/deployments` | Paginated; optional **`project_slug`**, **`status`**. |
| `GET` | `/api/edge/deployments/{id}` | Includes **`provisioner_output`**. |

Run **`php artisan queue:work`** when `QUEUE_CONNECTION` is not `sync`.

## Deploy engine (stub)

- **`EdgeDeployContext`** carries **`framework`** + **`git_ref`**; **`EdgeDeployEngine`** returns placeholder JSON until builds, previews, and CDN wiring exist.
- **`GET /internal/spike`** exercises the seam (**`WebhookSignature`** from **dply-core**). Gate with **`EDGE_INTERNAL_SPIKE=true`**.

## Database

Use a **dedicated** `DB_DATABASE` (e.g. `dply_edge`). Do not share a database with other product apps — see [database isolation runbook](../../docs/runbooks/database-isolation.md).
