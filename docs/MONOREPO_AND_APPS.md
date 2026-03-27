# Monorepo layout: BYO + `apps/*` + `packages/*`

One **Git repository** contains **multiple deployable Laravel applications** and **shared PHP packages**. Each app is **independent**: its own `composer.json`, `composer.lock`, `vendor/`, `.env`, `APP_KEY`, database, and deploy pipeline. They only **share source code** on disk and (optionally) the **`shaferllc/dply-core`** library via Composer **path** repositories.

**Default product:** [BYO local setup](BYO_LOCAL_SETUP.md) (repository root). The apps under `apps/` are **separate products**; install them only when you work on those lines.

---

## How it fits together

```text
dply/   (clone root — single git repo)
├── composer.json          ← BYO (product 1) — “main” app
├── app/, routes/, …
├── packages/
│   └── dply-core/           ← shared PHP package (webhook signing, etc.)
└── apps/
    ├── dply-serverless/     ← product 2 — own composer.json + .env + DB
    ├── dply-cloud/          ← product 3
    ├── dply-wordpress/      ← product 4
    └── dply-edge/           ← product 5
```

| Piece | Role |
| ----- | ---- |
| **Repository root** | **BYO** — servers, sites, SSH deploys, orgs, billing hooks. This is what most contributors run day to day. |
| **`packages/dply-core`** | **Library**, not a runnable app. Required by BYO and by apps that list it in `composer.json`. Versioned in-repo; consumed via **path** (`../../packages/dply-core` from an `apps/*` child). |
| **`apps/dply-serverless`** | **Serverless control plane** — function deploy API, provider adapters, own migrations. |
| **`apps/dply-cloud`** | **Cloud control plane** — stub deploy engine, projects + deployments API, own migrations. |
| **`apps/dply-wordpress`** | **WordPress control plane** — managed WP direction; projects + deployments API, stub engine. |
| **`apps/dply-edge`** | **Edge control plane** — git-native JS/static; projects + deployments API (`framework` slug), stub engine. |

All five product **lines** are represented in-repo; default onboarding still targets **BYO only** — see [BYO_LOCAL_SETUP.md](BYO_LOCAL_SETUP.md).

---

## Shared package: `dply-core`

- **Location:** `packages/dply-core/`
- **Consumed by:** root `composer.json` and each app that requires `shaferllc/dply-core` with a Composer **`repositories` → `path`** entry pointing at `../../packages/dply-core` (relative to that app’s directory).
- **Workflow:** Change code under `packages/dply-core/`, then run `composer update shaferllc/dply-core` (or `composer install`) in **each** consumer that should pick up the new files. Path repos symlink or copy from the working tree; no separate publish step for local dev.

Do **not** assume one `composer install` at the root installs dependencies for `apps/*` — it does **not**.

---

## Install: BYO (repository root)

**Directory:** repository root (where the top-level `artisan` lives).

```bash
cd /path/to/dply
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # if DB_CONNECTION=sqlite
php artisan migrate
npm install && npm run build
```

Run queue worker + web server as in [BYO_LOCAL_SETUP.md](BYO_LOCAL_SETUP.md).

---

## Install: `apps/dply-serverless`

**Directory:** `apps/dply-serverless` — treat it like its **own** Laravel project.

```bash
cd /path/to/dply/apps/dply-serverless
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # or configure MySQL/Postgres in .env
php artisan migrate
npm install && npm run build     # if you use the Vite UI
```

**Environment:** Use a **different** `DB_DATABASE` (and ideally a different DB server in production) than BYO — see [database isolation runbook](runbooks/database-isolation.md).

**Optional:** Real provider adapters need env vars documented in [apps/dply-serverless/README.md](../apps/dply-serverless/README.md) (`SERVERLESS_*`, `NETLIFY_*`, etc.).

**Bref / Lambda:** That app includes `serverless.yml` and Bref docs for hosting **this** control plane on AWS; that is separate from **customer** function deploys.

---

## Install: `apps/dply-cloud`

**Directory:** `apps/dply-cloud`.

```bash
cd /path/to/dply/apps/dply-cloud
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # or configure DB_* in .env
php artisan migrate
npm install && npm run build     # optional for asset pipeline
```

Set **`CLOUD_API_TOKEN`** if you use `/api/cloud/projects`. **`CLOUD_INTERNAL_SPIKE=true`** enables `GET /internal/spike` outside testing.

Details: [apps/dply-cloud/README.md](../apps/dply-cloud/README.md).

---

## Install: `apps/dply-wordpress`

```bash
cd /path/to/dply/apps/dply-wordpress
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # or configure DB_* in .env
php artisan migrate
```

Set **`WORDPRESS_API_TOKEN`** for `/api/wordpress/*`. Details: [apps/dply-wordpress/README.md](../apps/dply-wordpress/README.md).

---

## Install: `apps/dply-edge`

```bash
cd /path/to/dply/apps/dply-edge
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # or configure DB_* in .env
php artisan migrate
```

Set **`EDGE_API_TOKEN`** for `/api/edge/*`. Details: [apps/dply-edge/README.md](../apps/dply-edge/README.md).

---

## Quick comparison

| | BYO (root) | dply-serverless | dply-cloud | dply-wordpress | dply-edge |
| --- | --- | --- | --- | --- | --- |
| **Path** | `./` | `apps/dply-serverless/` | `apps/dply-cloud/` | `apps/dply-wordpress/` | `apps/dply-edge/` |
| **`composer install`** | At root | In app dir | In app dir | In app dir | In app dir |
| **`vendor/`** | Root only | App only | App only | App only | App only |
| **`.env` / `APP_KEY`** | One per env | One per env | One per env | One per env | One per env |
| **Database** | BYO | Serverless | Cloud | WordPress | Edge |
| **`dply-core`** | `./packages/dply-core` | `../../packages/dply-core` | (same) | (same) | (same) |

---

## Nginx (and PHP-FPM): where to point `root`

Each Laravel app is served from its **`public/`** directory only. Nginx **`root`** must be the **absolute path to that `public` folder**, not the monorepo root and not the app folder above `public`.

| Product | Example `root` (adjust to your deploy path) |
| ------- | --------------------------------------------- |
| **BYO** | `/var/www/dply/public` |
| **dply-serverless** | `/var/www/dply-serverless/public` |
| **dply-cloud** | `/var/www/dply-cloud/public` |
| **dply-wordpress** | `/var/www/dply-wordpress/public` |
| **dply-edge** | `/var/www/dply-edge/public` |

Typical layout on a server: clone or release artifact so the app’s code lives under e.g. `/var/www/dply-cloud` (containing `app/`, `bootstrap/`, `public/`, `vendor/`, …), then:

```nginx
root /var/www/dply-cloud/public;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;  # or your pool
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
    # … (standard Laravel PHP-FPM block)
}
```

Use a **separate `server { }` block** (or separate vhost file) per hostname/product so each app has its own `root`, `.env`, and FPM pool if you want isolation.

**Do not** point nginx at an app directory without the trailing **`/public`** — that would expose non-public files and break routing.

---

## Monorepo vs separate Git repositories

**You can deploy every app independently while keeping one repo.** Separate deploys are about **runtime** (different vhosts, `.env`, `DB_*`, `composer install` in each app directory), not about splitting Git. CI can use a matrix: one job per `working-directory` (`./`, `apps/dply-serverless`, `apps/dply-cloud`, `apps/dply-wordpress`, `apps/dply-edge`), each producing its own artifact.

**A “shared repo + apps outside” layout** usually means:

| Piece | Role |
| ----- | ---- |
| **`dply-core` (or similar)** | Small library repo; versioned tags; consumed via Composer **VCS or Packagist**. |
| **`dply` (BYO), `dply-serverless`, …** | Each in its **own** repo; `composer.json` requires `shaferllc/dply-core: ^0.x` from GitHub/Packagist instead of `path`. |

**When splitting apps out tends to make sense**

- Different **teams or access** (not everyone should clone Serverless).
- **Release cadence** or compliance boundaries that want isolated history.
- **Smaller clones** for contractors who only touch one product.

**Costs of splitting**

- Every **`dply-core`** change needs a **tag/release** (or `dev-main` aliases) before apps can pick it up — slower than today’s `path` symlink.
- Cross-cutting changes (shared contracts, renames) touch **multiple PRs/repos**.

**Pragmatic middle ground:** stay monorepo while BYO is the focus; extract **`packages/dply-core`** to its own repo first when you need reuse **outside** this tree; split `apps/*` only when operational pain (CI, access, or size) justifies it.

---

## CI and deployment

- **Build/test** each app from **its** directory (or matrix jobs with `working-directory`).
- **Deploy** each product with **its** `.env` and **its** `DB_*` — never point two apps at the same database name.

---

## Related documentation

| Doc | Purpose |
| --- | ------- |
| [BYO_LOCAL_SETUP.md](BYO_LOCAL_SETUP.md) | Canonical BYO-only onboarding |
| [MULTI_PRODUCT_PLATFORM_PLAN.md](MULTI_PRODUCT_PLATFORM_PLAN.md) | Product vision, rollout order, phase checklist |
| [runbooks/database-isolation.md](runbooks/database-isolation.md) | Enforcing separate `DB_DATABASE` per app |
| [adr/0001-dply-core-boundaries.md](adr/0001-dply-core-boundaries.md) | What belongs in `dply-core` |

---

## Document history

| Date | Change |
| ---- | ------ |
| 2026-03-23 | Initial monorepo + per-app install guide |
| 2026-03-23 | Nginx: `root` = each app’s `public/` directory |
| 2026-03-23 | Monorepo vs split repos: deploy independence vs Git layout |
| 2026-03-25 | **`dply-wordpress`**, **`dply-edge`**: install sections, 5-column comparison + nginx rows; **GitHub Actions** matrix for BYO + `apps/*` + `dply-core` |

