# dply Serverless (spike)

> **Product priority:** Team focus is **BYO first** ([docs/BYO_LOCAL_SETUP.md](../../docs/BYO_LOCAL_SETUP.md)). This app is **not** required for BYO local development unless you are explicitly working on Serverless.

**Monorepo:** [docs/MONOREPO_AND_APPS.md](../../docs/MONOREPO_AND_APPS.md) explains how this directory relates to the repo root, `dply-core`, and separate `composer install` / databases.

Second product app in the monorepo: **its own Laravel install, its own database**, Composer dependency on [`shaferllc/dply-core`](../../packages/dply-core).

## Local setup

```bash
cd apps/dply-serverless
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # or configure MySQL with DB_DATABASE=dply_serverless
php artisan migrate
php artisan test
php artisan serve
```

**FaaS provider roadmap** (Lambda, Azure Functions, Google Cloud Functions, Cloudflare Workers, Netlify Functions, Vercel Functions, [DigitalOcean Functions](https://www.digitalocean.com/products/functions), …): [MULTI_PRODUCT_PLATFORM_PLAN.md §6](../../docs/MULTI_PRODUCT_PLATFORM_PLAN.md#6-providers-especially-serverless).

Set **`SERVERLESS_PROVISIONER`** (`local`, `aws`, `digitalocean`, `cloudflare`, `netlify`, `vercel`, or roadmap placeholders `azure`, `gcp`); see `config/serverless.php`.

- **`aws`** + **`SERVERLESS_AWS_USE_REAL_SDK=true`**: **`aws/aws-sdk-php`** — **GetFunction** by default; **UpdateFunctionCode** from a **local zip** under **`SERVERLESS_AWS_ZIP_PATH_PREFIX`**, or from **`s3://bucket/key`** when **`SERVERLESS_AWS_S3_ALLOW_BUCKETS`** allow-lists the bucket. IAM notes: **[docs/SERVERLESS_AWS_IAM.md](docs/SERVERLESS_AWS_IAM.md)**.
- **`cloudflare`** + **`SERVERLESS_CLOUDFLARE_USE_REAL_API=true`**: uploads the worker script from a **local file** under **`CLOUDFLARE_WORKER_SCRIPT_PATH_PREFIX`** via the Cloudflare API (**Account ID** + **API token** with Workers Scripts:Edit, or the same fields on the linked **project** credentials / settings). Otherwise **`cloudflare`** stays on the **stub** provisioner.
- **`digitalocean`** + **`SERVERLESS_DIGITALOCEAN_USE_REAL_API=true`**: **`PUT`** OpenWhisk-style **`/api/v1/namespaces/{namespace}/actions/...`** with a **base64 zip** in JSON **`exec`** (default **`nodejs:18`**, entry **`index.js`**). Requires **`DIGITALOCEAN_FUNCTIONS_API_HOST`**, **`DIGITALOCEAN_FUNCTIONS_NAMESPACE`**, **`DIGITALOCEAN_FUNCTIONS_ACCESS_KEY`** (`dof_v1_…:secret`), and zips under **`DIGITALOCEAN_FUNCTIONS_ZIP_PATH_PREFIX`** (optional per-project **`settings.digitalocean_functions_zip_path_prefix`**). Project overrides: **`credentials`** / **`settings`** for **`digitalocean_functions_api_host`**, **`namespace`**, **`access_key`**, **`digitalocean_functions_package`**, **`digitalocean_functions_action_kind`**, **`digitalocean_functions_action_main`**. Otherwise **`digitalocean`** uses the **stub** provisioner.
- **`netlify`** + **`SERVERLESS_NETLIFY_USE_REAL_API=true`**: multipart **zip** upload to **`POST /api/v1/sites/{id}/deploys`**; zips must sit under **`NETLIFY_DEPLOY_ZIP_PATH_PREFIX`** (or a subdirectory when **`settings.netlify_deploy_zip_path_prefix`** narrows the allowed tree for that project). **`NETLIFY_AUTH_TOKEN`** + **`NETLIFY_SITE_ID`** (or project **`credentials`**: `api_token` / `netlify_personal_access_token`, `site_id` / `netlify_site_id`; **`settings.netlify_site_id`**). Otherwise **`netlify`** uses the **stub** provisioner.
- **`vercel`** + **`SERVERLESS_VERCEL_USE_REAL_API=true`**: expands a **.zip** under **`VERCEL_DEPLOY_ZIP_PATH_PREFIX`** (optionally narrowed per project with **`settings.vercel_deploy_zip_path_prefix`**) and **`POST`s** **`/v13/deployments`** with inlined `files` (utf-8 or base64). Target **`VERCEL_PROJECT_ID`** (`prj_…`) or **`VERCEL_PROJECT_NAME`**; optional **`VERCEL_TEAM_ID`**. Project overrides: **`credentials`** `vercel_token` / `api_token`, `vercel_team_id` / `team_id`, `vercel_project_id` / `project_id`, `vercel_project_name` / `project_name`; **`settings`** `vercel_team_id`, `vercel_project_id`, `vercel_project_name`. Limits: **`VERCEL_DEPLOY_MAX_ZIP_ENTRIES`**, **`VERCEL_DEPLOY_MAX_UNCOMPRESSED_BYTES`**. Otherwise **`vercel`** uses the **stub** provisioner.

**Feature flags ([Laravel Pennant](https://github.com/laravel/pennant)):** `GET /serverless` is a minimal, non-secret overview ( **`SERVERLESS_PUBLIC_DASHBOARD`**, default on). **`GET /internal/spike`** is gated by **`SERVERLESS_INTERNAL_SPIKE`** (on by default in `local` + `testing` only; set `true` in staging/production when you need it). Run migrations so the **`features`** table exists when **`PENNANT_STORE=database`** (default). Tests set **`PENNANT_STORE=array`** via `phpunit.xml`.

### API and webhook (Phase D)

| Endpoint | Auth | Notes |
| -------- | ---- | ----- |
| `POST /api/webhooks/serverless/deploy` | HMAC **`X-Dply-Signature`** (and optional **`X-Dply-Timestamp`**) | Same semantics as BYO + **`Dply\Core\Security\WebhookSignature`**. Set **`SERVERLESS_WEBHOOK_SECRET`**. Optional **`Idempotency-Key`** header or JSON **`idempotency_key`** (same replay rules as the Bearer deploy API; scoped by **`trigger`** = webhook). **202** includes **`deployment_url`** (poll with Bearer token like the deploy API). |
| `POST /api/serverless/deploy` | **`Authorization: Bearer`** **`SERVERLESS_API_TOKEN`** | JSON may include `function_name`, `runtime`, `artifact_path`, optional **`project_slug`** (must exist), optional **`idempotency_key`** (or HTTP **`Idempotency-Key`**, which wins). Reusing the same key for the same **`project_slug`** (including none) returns the existing **queued**, **running**, or **succeeded** deployment without enqueueing a second job; **failed** deploys can be retried with the same key. Max length **255**. **202** body: `message`, `id`, **`status`** (`queued`), **`deployment_url`** (Bearer **GET** for status/details). |
| `GET /api/serverless/projects` | Bearer | Paginated list (`per_page` 1–100, default 25); ordered by `name`. Each row includes **`settings`** and **`has_credentials`**. Optional **`q`** filters `name` / `slug` (substring; `%` / `_` stripped). |
| `POST /api/serverless/projects` | Bearer | Create project: `name`, **`slug`** (lowercase kebab). Optional **`settings`** (object) and **`credentials`** (object); credentials are stored with Laravel’s **`encrypted:array`** cast and are **never** returned in JSON (only **`has_credentials`**). |
| `PATCH /api/serverless/projects/{slug}` | Bearer | Update **`name`**, **`settings`**, and/or **`credentials`** (replace when sent). Same redaction rules as create. |
| `GET /api/serverless/projects/{slug}` | Bearer | Project + **`latest_deployment`** summary (includes **`deployment_url`** when present). Response includes **`settings`** and **`has_credentials`**. |
| `GET /api/serverless/deployments` | Bearer | Paginated list (`per_page` 1–100); optional **`project_slug`**, **`function_name`** (exact), **`status`** (`queued` \| `running` \| `succeeded` \| `failed`). Each row includes **`deployment_url`**. **`provisioner_output`** omitted from list rows. |
| `GET /api/serverless/deployments/{id}` | Bearer | Single deployment; includes **`deployment_url`**, **`provisioner_output`** when set. |

Deployments live in **`serverless_function_deployments`** (optional **`serverless_project_id`**) and run via **`RunServerlessFunctionDeploymentJob`** (use `QUEUE_CONNECTION=database` or `redis` in production; tests use `sync`).

**Per-project provider overrides** (when a deploy is linked to a project): decrypted **`credentials`** + public **`settings`** are passed into the active provisioner. Global env remains the default; project values override when present.

- **AWS** (`SERVERLESS_PROVISIONER=aws`, real SDK): optional **`settings.aws_region`**. Optional **`settings.aws_s3_allow_buckets`**: array of bucket names or a comma-separated string; must overlap **`SERVERLESS_AWS_S3_ALLOW_BUCKETS`** (intersection). Omit the setting to use the global list only; an **empty** array/string disallows all `s3://` artifacts for that project. Optional static keys in **`credentials`**: **`access_key_id`** / **`aws_access_key_id`**, **`secret_access_key`** / **`aws_secret_access_key`**, optional **`session_token`** / **`aws_session_token`**. If only the region differs, the default AWS credential chain (env/instance profile) is still used in the new region.
- **Cloudflare** (real API): optional **`credentials.account_id`** / **`cloudflare_account_id`**, **`api_token`** / **`cloudflare_api_token`**, or **`settings.cloudflare_account_id`**. Optional **`settings.cloudflare_compatibility_date`**. With **`SERVERLESS_CLOUDFLARE_USE_REAL_API`**, only **`CLOUDFLARE_WORKER_SCRIPT_PATH_PREFIX`** is required at the app level; account/token can come entirely from the project.
- **DigitalOcean** (real API): optional **`credentials.digitalocean_functions_api_host`** / **`api_host`**, **`digitalocean_functions_namespace`** / **`namespace`**, **`digitalocean_functions_access_key`** / **`access_key`**; optional **`settings.digitalocean_functions_package`**, **`digitalocean_functions_action_kind`**, **`digitalocean_functions_action_main`**, **`digitalocean_functions_zip_path_prefix`**. Zip must match your Functions runtime layout (e.g. Node handler at **`action_main`**). With **`SERVERLESS_DIGITALOCEAN_USE_REAL_API`**, global env supplies defaults; all three connection fields can come from the project.
- **Netlify** (real API): optional **`credentials.api_token`** / **`netlify_personal_access_token`**, **`site_id`** / **`netlify_site_id`**, or **`settings.netlify_site_id`**. Optional **`settings.netlify_deploy_zip_path_prefix`**: absolute path that must resolve to **`NETLIFY_DEPLOY_ZIP_PATH_PREFIX`** or a subdirectory (multi-tenant sandboxing). With **`SERVERLESS_NETLIFY_USE_REAL_API`**, only **`NETLIFY_DEPLOY_ZIP_PATH_PREFIX`** is required globally; token/site can come from the project.
- **Vercel** (real API): optional **`credentials.vercel_token`** / **`api_token`**, **`vercel_team_id`**, **`vercel_project_id`** / **`project_id`**, **`vercel_project_name`** / **`project_name`** (same keys under **`settings`** for team/project). Optional **`settings.vercel_deploy_zip_path_prefix`**: same rules as Netlify, relative to **`VERCEL_DEPLOY_ZIP_PATH_PREFIX`**. With **`SERVERLESS_VERCEL_USE_REAL_API`**, only **`VERCEL_DEPLOY_ZIP_PATH_PREFIX`** is required globally; token and project target can come from the project.

## Hosting this app on AWS Lambda (Bref)

**Not** the same as deploying **customer** functions (that is `ServerlessFunctionProvisioner` + AWS SDK).

This app **already requires** **`bref/bref`** and **`bref/laravel-bridge`** (^3) for Lambda-oriented defaults (Octane, logging, health checks). To deploy the **control plane** to Lambda:

1. Install the [Serverless Framework](https://www.serverless.com/) CLI (`npm i -g serverless`) — see Composer **`suggest`**.
2. From `apps/dply-serverless`, run `php artisan vendor:publish --provider="Bref\LaravelBridge\BrefServiceProvider"` if **`serverless.yml`** / **`config/bref.php`** are missing.
3. Set **`APP_KEY`** (and **`DB_*`**, **`SERVERLESS_*`**, queue, etc.) for your stage via env or SSM when you run **`serverless deploy`**.
4. Use the repo’s **`serverless.yml`** (PHP **8.3** FPM + console `artisan` + **SQS worker** for queued jobs) and follow **[Bref Laravel](https://bref.sh/docs/laravel/getting-started)**.
5. Read **[docs/BREF_LAMBDA_QUEUE.md](docs/BREF_LAMBDA_QUEUE.md)** for how **`JobsQueue`**, **`worker`**, and **`QUEUE_CONNECTION=sqs`** fit together (local dev keeps **`QUEUE_CONNECTION=database`** in **`.env`**).

**Laravel Vapor** remains an alternative (managed stack); see **[docs/serverless-laravel-aws-hosting.md](../../docs/serverless-laravel-aws-hosting.md)** for Bref vs Vapor.

**`shaferllc/dply-core`** is **path-only** (`../../packages/dply-core`).

## Deploy pipeline (later)

This directory is a **separate deployable** from the BYO app at the repository root. Point CI/CD and `DB_*` at a **dedicated** database instance — see [database isolation runbook](../../docs/runbooks/database-isolation.md).
