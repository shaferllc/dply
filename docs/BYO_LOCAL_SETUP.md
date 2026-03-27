# BYO (bring-your-own-server) — local setup

This guide is the **canonical way to run the main dply product** in the monorepo: the **BYO** Laravel app at the **repository root** (`composer.json` next to `app/`, `routes/`, `resources/`).

**You do not need** `apps/dply-serverless`, `apps/dply-cloud`, or any other product app to develop or use BYO. Those live in `apps/` with **their own** installs, `.env` files, and databases; treat them as **on hold** until you deliberately work on them.

---

## Prerequisites

| Requirement | Notes |
| ----------- | ----- |
| PHP **8.3+** | Extensions typical for Laravel (openssl, pdo, mbstring, tokenizer, xml, ctype, json, bcmath; `pdo_sqlite` or `pdo_mysql` / `pdo_pgsql`). |
| **Composer** | v2. |
| **Node.js + npm** | For Vite / front-end assets. |
| **Database** | **SQLite** (simplest) or MySQL/PostgreSQL. |

---

## 1. Install PHP dependencies

From the **repository root** (not `apps/*`):

```bash
composer install
```

---

## 2. Environment file

```bash
cp .env.example .env
php artisan key:generate
```

`APP_KEY` must stay stable in each environment; without it, encrypted fields (SSH keys, provider tokens) cannot be read after deploy.

---

## 3. Database

### SQLite (default in `.env.example`)

```bash
touch database/database.sqlite
```

Ensure `.env` has:

```env
DB_CONNECTION=sqlite
# DB_DATABASE is relative to the project base path; leave empty to use database/database.sqlite
```

Run migrations:

```bash
php artisan migrate
```

### MySQL or PostgreSQL

Set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` in `.env`, create an empty database, then:

```bash
php artisan migrate
```

**Isolation:** Use a **dedicated** database name for BYO (e.g. `dply_byo`). Do not point BYO at a database used by `apps/dply-serverless` or `apps/dply-cloud`. See [database isolation runbook](runbooks/database-isolation.md).

---

## 4. Front-end assets

```bash
npm install
npm run build
```

For active UI work:

```bash
npm run dev
```

---

## 5. Queue worker (recommended)

`.env.example` uses `QUEUE_CONNECTION=database`. Provisioning and deploy jobs are queued; without a worker, long-running work will sit in the `jobs` table.

In a **second terminal**:

```bash
php artisan queue:work
```

Use `redis` or another driver in production if you prefer; the important part is that **some** worker consumes the queue you configure.

---

## 6. Run the application

```bash
php artisan serve
```

Open the URL shown (default `http://127.0.0.1:8000`).

1. **Register** a user (or use your usual auth flow).
2. **Credentials** — add a **DigitalOcean** API token if you use DO provisioning (optional for “existing server only” flows).
3. **Servers** — create a droplet via DigitalOcean or **add an existing server** (IP, SSH user, private key).

---

## 7. Optional configuration

| Concern | Notes |
| ------- | ----- |
| **DigitalOcean** | UI or env for API token; image/region defaults may live in `config` — see app settings. |
| **OAuth (GitHub, etc.)** | Uncomment and set `GITHUB_CLIENT_*` (and similar) in `.env` if you need social login. |
| **Mail** | `MAIL_MAILER=log` is fine locally; set real SMTP for password reset and notifications in staging/production. |
| **Stripe / Cashier** | Configure when testing billing; not required for core server/site flows. |

---

## 8. Verify the install

- [ ] `php artisan migrate` completes with no errors.
- [ ] `npm run build` completes.
- [ ] Home/login loads in the browser.
- [ ] With `queue:work` running, a test provision or deploy job leaves the queue (if you use those features).

Run automated checks:

```bash
php artisan test
./vendor/bin/pint --test
```

---

## 9. Monorepo: what to ignore for BYO-only work

For a **full map** of the repo (all apps, `dply-core`, install commands per app), read **[MONOREPO_AND_APPS.md](MONOREPO_AND_APPS.md)**.

| Path | Role |
| ---- | ---- |
| **Repository root** | **BYO app** — this guide. |
| `packages/dply-core/` | Shared library; pulled in via Composer path from the root `composer.json`. |
| `apps/dply-serverless/` | **Separate product** — own `composer install`, `.env`, DB. **On hold** for BYO-focused work. |
| `apps/dply-cloud/` | **Separate product** — same as above. **On hold** for BYO-focused work. |
| `docs/MULTI_PRODUCT_PLATFORM_PLAN.md` | Long-term multi-product blueprint; rollout beyond BYO is paused for **documentation and default local setup** per team focus. |

---

## Further reading (BYO)

- [MONOREPO_AND_APPS.md](MONOREPO_AND_APPS.md) — all apps in this repo, install steps, and `dply-core`.
- [DEPLOYMENT_FLOW.md](DEPLOYMENT_FLOW.md) — how deploys behave today.
- [API.md](API.md) — HTTP surface (BYO).
- [ORG_ROLES_AND_LIMITS.md](ORG_ROLES_AND_LIMITS.md) — org roles and plan limits.

---

## Document history

| Date | Change |
| ---- | ------ |
| 2026-03-23 | Initial BYO-first local setup guide; other product apps explicitly out of scope for default onboarding. |
| 2026-03-23 | Link to [MONOREPO_AND_APPS.md](MONOREPO_AND_APPS.md) from §9 and further reading. |
