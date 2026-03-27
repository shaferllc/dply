# dply WordPress (Phase F)

> **Product priority:** Team focus is **BYO first** ([docs/BYO_LOCAL_SETUP.md](../../docs/BYO_LOCAL_SETUP.md)). This app is **not** required for BYO local development unless you are explicitly working on the WordPress product line.

**Monorepo:** [docs/MONOREPO_AND_APPS.md](../../docs/MONOREPO_AND_APPS.md) explains how this directory relates to the repo root, `dply-core`, and separate `composer install` / databases.

Fourth product app in the monorepo: **managed WordPress** (control plane MVP: projects API, deploy records, stub engine). **Separate Laravel install and database** from BYO, Serverless, and Cloud.

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
| `POST` | `/api/wordpress/projects` | `name`, **`slug`** (kebab), optional `settings`, `credentials` (encrypted; never returned). |
| `GET` | `/api/wordpress/projects/{slug}` | Project JSON + `has_credentials` + **`latest_deployment`**. |
| `PATCH` | `/api/wordpress/projects/{slug}` | `name`, `settings`, `credentials` (replace when sent). |
| `POST` | `/api/wordpress/deploy` | Required **`project_slug`**; optional **`application_name`**, **`php_version`** (default **`WORDPRESS_DEFAULT_PHP_VERSION`**), **`git_ref`**. Optional **`Idempotency-Key`**. Returns **202**; queue runs **`WordpressDeployEngine`** stub until real WP lifecycle exists. |
| `GET` | `/api/wordpress/deployments` | Paginated; optional **`project_slug`**, **`status`**. |
| `GET` | `/api/wordpress/deployments/{id}` | Includes **`provisioner_output`**. |

Run **`php artisan queue:work`** when `QUEUE_CONNECTION` is not `sync`.

## Deploy engine (stub)

- **`App\Contracts\DeployEngine`** + **`WordpressDeployContext`** + **`WordpressDeployEngine`** — placeholder until core install, updates, backups, and staging are implemented.
- **`GET /internal/spike`** exercises the seam ( **`WebhookSignature`** from **dply-core**). Gate with **`WORDPRESS_INTERNAL_SPIKE=true`** (tests enable via `phpunit.xml`).

## Database

Use a **dedicated** `DB_DATABASE` (e.g. `dply_wordpress`). Do not point this app at BYO, Serverless, or Cloud databases — see [database isolation runbook](../../docs/runbooks/database-isolation.md).
