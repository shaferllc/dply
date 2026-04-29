# Repository layout: one Laravel app + `packages/*`

> **Note (2026-04-28):** The earlier monorepo-of-five-apps direction was retired. All product lines (BYO, Serverless, Cloud, WordPress, Edge) now ship from a **single Laravel application at the repository root**. The shared **`packages/dply-core`** library remains.

This repository contains:

- **One deployable Laravel application** at the repository root (BYO + serverless + Docker + Kubernetes today; Cloud/WordPress/Edge re-enter as modules in the same app when they have real behavior).
- **`packages/dply-core`** — small, stable PHP library (webhook signing, OAuth helpers, etc.) consumed by the root app via a Composer **path** repository.

There are **no** `apps/*` Laravel applications anymore. There is **no** separate identity service; auth lives in the root app via Fortify and OAuth providers.

---

## How it fits together

```text
dply/   (clone root - single git repo)
├── composer.json          ← root app (Laravel)
├── app/, routes/, ...
└── packages/
    └── dply-core/           ← shared PHP package (path repo)
```

| Piece | Role |
| ----- | ---- |
| **Repository root** | The product. Servers, sites, SSH deploys, orgs, billing hooks, deploy engines (BYO + serverless + Docker + Kubernetes). |
| **`packages/dply-core`** | Library, not a runnable app. Required by the root via a Composer `path` repository (`./packages/dply-core`). |

---

## Shared package: `dply-core`

- **Location:** `packages/dply-core/`
- **Consumed by:** the root [`composer.json`](../composer.json) via a `repositories` → `path` entry pointing at `./packages/dply-core`.
- **Workflow:** Change code under `packages/dply-core/`, then run `composer update shaferllc/dply-core` (or `composer install`) at the root. The path repo symlinks or copies from the working tree; no separate publish step for local dev.

See [adr/0001-dply-core-boundaries.md](adr/0001-dply-core-boundaries.md) for what belongs inside `dply-core` versus the root app.

---

## Install (root)

```bash
cd /path/to/dply
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
```

Run the queue worker and web server as in [BYO_LOCAL_SETUP.md](BYO_LOCAL_SETUP.md).

---

## Nginx (and PHP-FPM): where to point `root`

The Laravel app is served from its **`public/`** directory only. Nginx **`root`** must be the **absolute path to that `public` folder**, not the repository root and not the app folder above `public`.

| Product | Example `root` (adjust to your deploy path) |
| ------- | --------------------------------------------- |
| **dply** | `/var/www/dply/public` |

Typical layout on a server: clone or release artifact so the app's code lives under e.g. `/var/www/dply` (containing `app/`, `bootstrap/`, `public/`, `vendor/`, ...), then:

```nginx
root /var/www/dply/public;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;  # or your pool
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
    # ... (standard Laravel PHP-FPM block)
}
```

**Do not** point nginx at the app directory without the trailing **`/public`** — that would expose non-public files and break routing.

---

## CI and deployment

- One Laravel test job at the repository root. A second job runs the standalone `packages/dply-core` test suite. See [`.github/workflows/tests.yml`](../.github/workflows/tests.yml).
- Deploy with one `.env` and one `DB_*`. Multi-product data isolation, if reintroduced later, would use separate Laravel database connections within the same app rather than separate Laravel apps.

---

## Related documentation

| Doc | Purpose |
| --- | ------- |
| [BYO_LOCAL_SETUP.md](BYO_LOCAL_SETUP.md) | Canonical local onboarding |
| [MULTI_PRODUCT_PLATFORM_PLAN.md](MULTI_PRODUCT_PLATFORM_PLAN.md) | Product vision, rollout order, phase checklist |
| [adr/0001-dply-core-boundaries.md](adr/0001-dply-core-boundaries.md) | What belongs in `dply-core` |

---

## Document history

| Date | Change |
| ---- | ------ |
| 2026-03-23 | Initial monorepo + per-app install guide |
| 2026-03-25 | `dply-wordpress`, `dply-edge` install sections, 5-column comparison + nginx rows; GitHub Actions matrix for BYO + `apps/*` + `dply-core` |
| 2026-04-28 | **Retired the multi-app layout.** All product lines collapsed into a single root Laravel app; `apps/*` removed; central auth folded into root. |
