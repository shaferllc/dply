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

The migration `migrate_local_dev_organization_to_workspace` renames the old seeded organization whose slug was `local-dev` (display name “Local Dev”) to a normal workspace name and an email-based slug, so it no longer appears as a throwaway dev org.

In **`APP_ENV=local`**, `php artisan db:seed` also ensures the usual local admin user (`tj@tjshafer.com` / `password` per `DatabaseSeeder`) has that workspace as **owner**, then runs **`LocalDemoServersSeeder`**: demo teams, **Custom**-provider servers (no cloud `provider_id`), sites with varying counts, and a **fake** DigitalOcean credential row for credentials UI testing. Re-seeding skips creating duplicate demo servers when rows tagged with `meta.local_demo` already exist. Demo servers are safe to remove from the **Servers** index; they do not call cloud teardown APIs.

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

**Laravel Horizon** (optional): set `QUEUE_CONNECTION=redis`, ensure **Redis** is running (`brew services start redis` on macOS), then run `php artisan horizon:listen` or `composer run dev:horizon` (same stack as `composer dev` but swaps `queue:listen` for Horizon). This uses Horizon's built-in file watcher from `config/horizon.php`, so local code and config changes restart Horizon automatically. Open `/horizon` while signed in (`APP_ENV=local` allows any authenticated user; production uses `HORIZON_ALLOWED_EMAILS`). The **Horizon** pane in `php artisan solo` runs the same command.

---

## 5b. Expose (public HTTPS for the control plane)

**Provisioning a cloud server (e.g. DigitalOcean droplet)** uses your app’s **outbound** API calls and **queued polling jobs** (`ProvisionDigitalOceanDropletJob`, `PollDropletIpJob`, etc.). DigitalOcean does **not** call back into dply when the droplet is ready, so you do **not** need a public URL on your laptop **just to create a server**, as long as you have a valid API token or OAuth flow that works.

Where a **public `APP_URL`** matters for **server workflows**:

- **DigitalOcean OAuth** (“Continue with DigitalOcean” under **Server providers**): the redirect URI registered in the DigitalOcean OAuth app must match how the browser reaches your app. On localhost, use a tunnel.
- **Git OAuth** (profile / source control): same idea.

Recommended tunnel: **[Expose](https://expose.dev)** (Beyond Code). Typical flow:

1. The repo includes **Expose** as a Composer **dev** dependency (`beyondcode/expose`). After `composer install`, Solo runs `php vendor/bin/expose share …` so you do not need a global `expose` binary on `PATH`. Authenticate per [Expose’s docs](https://expose.dev); share your local app, e.g. the URL you use for Valet or `php artisan serve`. If you use **Solo** (`php artisan solo`), start the **Expose** pane (lazy) and set **`SOLO_EXPOSE_SHARE_URL`** in `.env`. To use a global CLI instead, set **`SOLO_EXPOSE_COMMAND=expose`**.
2. Set **`APP_URL`** (and **`ASSET_URL`** if you rely on it) to the **https** URL Expose prints (no trailing slash).
3. If you set **`DIGITALOCEAN_OAUTH_REDIRECT_URI`**, make it exactly  
   `{APP_URL}/credentials/oauth/digitalocean/callback`  
   and register that same URL in the DigitalOcean OAuth application. If you leave it unset, Laravel builds the callback from **`APP_URL`**.
4. Set **`TRUSTED_PROXIES=*`** in `.env` so Laravel trusts `X-Forwarded-*` from the tunnel and generates `https://` URLs correctly.

**Vite (CSS/JS) and a tunnel:** `@vite` in dev mode reads **`public/hot`**, which **`npm run dev`** fills with the dev server URL. If that URL is `http://127.0.0.1:5173`, browsers that opened your site via the **tunnel** will still request `127.0.0.1` on **their** machine and assets will fail (or look “wrong”). You have two practical options:

- **Simplest:** Stop `npm run dev`, remove **`public/hot`** if it exists, run **`npm run build`**, then use the tunnel. Built assets are served under **`/build`** on the same origin as **`APP_URL`**—no second tunnel.
- **Hot reload through the tunnel:** Share **port 5173** with a **second** Expose (or equivalent), set **`VITE_DEV_SERVER_URL`** in `.env` to that **https** origin (no trailing slash; paste from the address bar or plain text, not colored terminal output), restart **`npm run dev`**. The Laravel Vite plugin will write that origin into **`public/hot`**.

If asset URLs look like random digits and `m` characters, **`public/hot`** or an env value likely contains **ANSI escape sequences** from a copied terminal line—delete **`public/hot`**, fix the env value, and restart Vite.

**Site deploy webhooks** (`/hooks/sites/...`) are separate: they only need a public URL if **git hosts or CI** must reach your dply instance. You can use Expose for the **app** first to unblock **credentials + server create** without tunneling each site.

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

## 7. Optional SSH test target (Docker Compose)

For **real** SSH connectivity (health checks, deploy scripts, integration tests), database seeding alone is not enough—you need a reachable `sshd`. For localhost workflows, **Docker Compose** is the default choice (lighter than Vagrant or Kubernetes for a single box).

From the repository root:

```bash
docker compose -f docker-compose.ssh-dev.yml up -d
```

This exposes **`127.0.0.1:2222`** (linuxserver OpenSSH image). Default user/password in that compose file are for **local development only** (`dplytest` / `dplylocal`). In BYO, add an **existing server** (Custom) with that host, port, and credentials—or generate an SSH key pair, mount `authorized_keys` per the image’s docs, and use key-based auth instead.

To stop and remove the container:

```bash
docker compose -f docker-compose.ssh-dev.yml down
```

If you are working on the newer Docker or Kubernetes runtime targets, keep using this SSH target for **VM provisioning-script validation** only. Use [DOCKER_AND_KUBERNETES_LOCAL_SETUP.md](DOCKER_AND_KUBERNETES_LOCAL_SETUP.md) for Orbit or OrbStack and container-runtime workflows.

---

## 8. Optional configuration

| Concern | Notes |
| ------- | ----- |
| **DigitalOcean** | UI or env for API token; image/region defaults may live in `config` — see app settings. |
| **OAuth (GitHub, etc.)** | Uncomment and set `GITHUB_CLIENT_*` (and similar) in `.env` if you need social login. |
| **Mail** | `MAIL_MAILER=log` is fine locally; set real SMTP for password reset and notifications in staging/production. |
| **Stripe / Cashier** | Configure when testing billing; not required for core server/site flows. |

---

## 9. Verify the install

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

## 10. Monorepo: what to ignore for BYO-only work

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
- [DOCKER_AND_KUBERNETES_LOCAL_SETUP.md](DOCKER_AND_KUBERNETES_LOCAL_SETUP.md) — local Docker and Kubernetes runtime workflows.
- [API.md](API.md) — HTTP surface (BYO).
- [ORG_ROLES_AND_LIMITS.md](ORG_ROLES_AND_LIMITS.md) — org roles and plan limits.

---

## Document history

| Date | Change |
| ---- | ------ |
| 2026-03-23 | Initial BYO-first local setup guide; other product apps explicitly out of scope for default onboarding. |
| 2026-03-23 | Link to [MONOREPO_AND_APPS.md](MONOREPO_AND_APPS.md) from §10 and further reading. |
| 2026-03-30 | Local demo servers seeder; optional `docker-compose.ssh-dev.yml` and SSH test-target notes (§7). Renumbered later sections. |
| 2026-03-31 | §5b: Expose / public `APP_URL` for OAuth; clarify droplet provisioning uses polling, not inbound callbacks. `.env.example` `TRUSTED_PROXIES` note. |
| 2026-03-31 | Solo `Expose` command + `SOLO_EXPOSE_SHARE_URL` in `config/solo.php` and `.env.example`. |
