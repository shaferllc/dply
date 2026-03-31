# dply Cloud (Phase E)

> **Product priority:** Team focus is **BYO first** ([docs/BYO_LOCAL_SETUP.md](../../docs/BYO_LOCAL_SETUP.md)). This app is **not** required for BYO local development unless you are explicitly working on Cloud.

**Monorepo:** [docs/MONOREPO_AND_APPS.md](../../docs/MONOREPO_AND_APPS.md) explains how this directory relates to the repo root, `dply-core`, and separate `composer install` / databases.

Third product app in the monorepo: **managed long-lived PHP / Rails** workloads on **your** infrastructure (build/publish TBD). **Separate Laravel install and database** from the BYO app and from `apps/dply-serverless`.

## Marketing site (`/`)

The default **`/`** route renders a branded landing page (Tailwind v4, same palette as the main dply app). **Log in**, **Register**, **Dashboard**, **Features**, **Pricing**, and **Docs** links target the **main BYO application** — set **`DPLY_MAIN_APP_URL`** in `.env` to that app’s origin (e.g. `https://dply.test`). Accounts use **Fortify on the main site**; this app does not duplicate web auth.

- **`npm run build`** (or **`npm run dev`**) compiles `resources/css/app.css` + Vite.
- Logo asset: `public/images/dply-logo.svg` (same mark as the root app).

## Local setup

```bash
cd apps/dply-cloud
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # or set DB_DATABASE=dply_cloud on MySQL/Postgres
php artisan migrate
php artisan test
php artisan serve
```

## Monorepo

- Composer depends on [`shaferllc/dply-core`](../../packages/dply-core) via **path** (`../../packages/dply-core`).
- Platform plan: [MULTI_PRODUCT_PLATFORM_PLAN.md §9 Phase E](../../docs/MULTI_PRODUCT_PLATFORM_PLAN.md#phase-e--dply-cloud-third-product).

## Control plane API

Bearer **`CLOUD_API_TOKEN`**:

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/cloud/projects` | Paginated (`per_page` 1–100); optional `q` filters `name` / `slug`. |
| `POST` | `/api/cloud/projects` | `name`, **`slug`** (kebab), optional `settings`, `credentials` (encrypted; never returned). |
| `GET` | `/api/cloud/projects/{slug}` | Project JSON + `has_credentials` + **`latest_deployment`** (if any). |
| `PATCH` | `/api/cloud/projects/{slug}` | `name`, `settings`, `credentials` (replace when sent). |
| `POST` | `/api/cloud/deploy` | JSON: required **`project_slug`**; optional **`application_name`** (defaults to project name), **`stack`** (`php` or `rails`, default **`CLOUD_DEFAULT_STACK`**), **`git_ref`** (default **`CLOUD_DEFAULT_GIT_REF`**). Optional **`Idempotency-Key`** header or body. Returns **202** + deployment id; queue worker runs **`CloudDeployEngine`** stub until real build/publish exists. |
| `GET` | `/api/cloud/deployments` | Paginated; optional **`project_slug`**, **`status`**. |
| `GET` | `/api/cloud/deployments/{id}` | Full row including **`provisioner_output`**. |

Run a **queue worker** in non-local environments (`php artisan queue:work`) so deploys move past **`queued`**.

## Deploy engine (stub)

- **`App\Contracts\DeployEngine`** + **`CloudDeployContext`** + **`CloudDeployEngine`** — **`RunCloudDeploymentJob`** invokes the engine; output is placeholder JSON until containers/VMs and a real adapter land.
- **`GET /internal/spike`** runs the engine once and returns JSON ( **`WebhookSignature`** class from **dply-core** for parity with Serverless). Gate with **`CLOUD_INTERNAL_SPIKE=true`** in `.env` (tests enable it via `phpunit.xml`).

## Database

Use a **dedicated** `DB_DATABASE` (e.g. `dply_cloud`). Do not point this app at the BYO or Serverless database — see [database isolation runbook](../../docs/runbooks/database-isolation.md).
