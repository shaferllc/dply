# dply WordPress

> **Product priority:** Team focus is **BYO first** ([docs/BYO_LOCAL_SETUP.md](../../docs/BYO_LOCAL_SETUP.md)). This app is **not** required for BYO local development unless you are explicitly working on the WordPress product line.

**Monorepo:** [docs/MONOREPO_AND_APPS.md](../../docs/MONOREPO_AND_APPS.md) explains how this directory relates to the repo root, `dply-core`, and separate `composer install` / databases.

Fourth product app: **hosted managed WordPress** — dply operates customer sites on **dply-controlled** infrastructure (no customer VMs or SSH in v1). **Separate Laravel install and database** from BYO, Serverless, and Cloud.

**Architecture:** [ADR-007: hosted runtime and provisioning](../../docs/adr/0007-wordpress-hosted-runtime-provisioning.md).

## Marketing site (`/`)

The default **`/`** route renders a branded landing page (Tailwind v4, same palette as the main dply app). **Log in**, **Register**, **Dashboard**, **Features**, **Pricing**, and **Docs** links target the **main BYO application** — set **`DPLY_MAIN_APP_URL`** in `.env` to that app’s origin (e.g. `https://dply.test`). Accounts use **Fortify on the main site**; this app does not duplicate web auth.

- **`npm run build`** (or **`npm run dev`**) compiles `resources/css/app.css` + Vite.
- Logo asset: `public/images/dply-logo.svg` (same mark as the root app).

## Local setup

```bash
cd apps/dply-wordpress
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # or set DB_DATABASE=dply_wordpress on MySQL/Postgres
php artisan migrate
php artisan test
php artisan serve
```

## Monorepo

- Composer depends on [`shaferllc/dply-core`](../../packages/dply-core) via **path** (`../../packages/dply-core`).
- Platform plan: [MULTI_PRODUCT_PLATFORM_PLAN.md §9 Phase F](../../docs/MULTI_PRODUCT_PLATFORM_PLAN.md#phase-f--dply-wordpress-fourth-product).

## Control plane API

Bearer **`WORDPRESS_API_TOKEN`**:

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/wordpress/projects` | Paginated (`per_page` 1–100); optional `q` filters `name` / `slug`. |
| `POST` | `/api/wordpress/projects` | `name`, **`slug`** (kebab), optional `settings`, `credentials` (encrypted; never returned). See **Hosted settings** below. |
| `GET` | `/api/wordpress/projects/{slug}` | Project JSON + `has_credentials` + **`latest_deployment`**. |
| `PATCH` | `/api/wordpress/projects/{slug}` | `name`, `settings`, `credentials` (replace when sent). |
| `POST` | `/api/wordpress/deploy` | Required **`project_slug`**; optional **`application_name`**, **`php_version`** (default **`WORDPRESS_DEFAULT_PHP_VERSION`**), **`git_ref`**. Optional **`Idempotency-Key`**. Returns **202**; queue runs **`WordpressDeployEngine`**. Project must have hosted target metadata (see below). |
| `GET` | `/api/wordpress/deployments` | Paginated; optional **`project_slug`**, **`status`**. |
| `GET` | `/api/wordpress/deployments/{id}` | Includes **`provisioner_output`**. |

Run **`php artisan queue:work`** when `QUEUE_CONNECTION` is not `sync`.

### Hosted settings (`wordpress_projects.settings`)

Deploy requires **`settings.environment_id`** and/or **`settings.primary_url`** (https). Optional: **`runtime`** (`hosted`), **`compute_ref`**, **`data_ref`**.

## Deploy engine

- **`App\Contracts\DeployEngine`** → **`WordpressDeployEngine`** → **`HostedWordpressProvisioner`** (ADR-007, no BYO SSH).
- Default provisioner: **`LocalHostedWordpressProvisioner`** — deterministic JSON output and `revision_id` (SHA-256 of project slug, git ref, PHP version, application name) until an HTTP/SDK adapter targets the real tenant fleet.

## Operations

| Path | Notes |
| ---- | ----- |
| `GET /health` | JSON liveness: `ok`, `checks.database` (always enabled). |
| `GET /up` | Laravel default health (from `bootstrap/app.php`). |
| `GET /internal/spike` | Dev/CI: same deploy engine as jobs with a synthetic project context + **dply-core** `WebhookSignature` reference. **Off** unless **`WORDPRESS_INTERNAL_SPIKE=true`** (tests enable via `phpunit.xml`). Do not enable in production. |

## Lifecycle roadmap (follow-on slices)

Not implemented in the control plane yet; track as separate work:

1. **Core and plugin updates** — channel `git_ref` / WordPress release policy into `HostedWordpressProvisioner` or dedicated jobs.
2. **Backups** — scheduled DB + `wp-content` to object storage; retention and restore APIs.
3. **Staging** — second environment per project; promote / clone workflows.

## Database

Use a **dedicated** `DB_DATABASE` (e.g. `dply_wordpress`). Do not point this app at BYO, Serverless, or Cloud databases — see [database isolation runbook](../../docs/runbooks/database-isolation.md).
