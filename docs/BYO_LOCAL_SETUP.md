# Local development (BYO)

Use this guide to run Dply on your machine against a real database, process provisioning queues, and (optionally) create servers on DigitalOcean or receive inbound webhooks via a tunnel.

## Prerequisites

- PHP 8.3+, Composer, Node.js/npm
- PostgreSQL (matches `DB_*` in `.env`; SQLite is possible if you align `config/database.php`)
- `APP_KEY` after `php artisan key:generate`

## Install

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create an empty database matching `DB_DATABASE`, then:

```bash
php artisan migrate
npm install && npm run build   # or npm run dev during UI work
```

## Run the HTTP app

Pick one:

- **`php artisan serve`** — default `http://127.0.0.1:8000` (set `APP_URL` accordingly).
- **Laravel Herd / Valet** — use your `.test` hostname and set `APP_URL` to that URL.

## Run queue workers (required for provisioning)

Provisioning, deploys, and many SSH-backed jobs run on the **queue**. If no worker is running, UI flows will stall.

**Option A — separate terminal (minimal)**

```bash
php artisan queue:work
```

Use **`php artisan horizon:listen`** (or Horizon normally) if `QUEUE_CONNECTION=redis` and Horizon is configured.

**Option B — `composer dev`**

Runs `php artisan serve`, `queue:listen`, Vite, Reverb, and Pail together via `concurrently` ([composer.json](../composer.json) `scripts.dev`).

**Option C — Laravel Solo**

```bash
php artisan solo
```

Start the **Queue** pane (lazy by default; press the keybinding to open it). Optionally start **Horizon** if you use Redis queues.

See [config/solo.php](../config/solo.php).

## DigitalOcean: real servers from local dev

1. **Credentials** — In the app: **Credentials** (server providers) / organization credentials. Add DigitalOcean with a **personal access token** or **OAuth**, per the UI.
2. **OAuth redirect** — If you use OAuth, DigitalOcean’s app settings must allow the redirect URL:

   `{APP_URL}/credentials/oauth/digitalocean/callback`

   `APP_URL` must match exactly what you use in the browser (scheme, host, port).

3. **Outbound API** — Creating droplets uses DigitalOcean’s API from your machine; [`DPLY_PUBLIC_APP_URL`](../.env.example) is **not** required for basic create/list polling ([`.env.example`](../.env.example) notes).

4. **Safety** — Prefer a non-production DO team or small droplet sizes while testing; revoke tokens when done.

Optional CLI smoke tests are documented in [`config/dply.php`](../config/dply.php) (`demo_*`) and `.env.example` (`DIGITALOCEAN_TOKEN`, `dply:demo-do-server`).

## Inbound callbacks: tunnel, Jetty, and `DPLY_PUBLIC_APP_URL`

Some flows (e.g. **TaskRunner signed webhooks**) need VMs or workers to **POST HTTPS** to your control plane. They cannot reach `http://127.0.0.1`.

1. **`DPLY_PUBLIC_APP_URL`** — Set in `.env` to the **public HTTPS origin** those callers use (no trailing slash mismatch vs generated URLs). Laravel reads this from [`config/dply.php`](../config/dply.php) (`public_app_url`). If unset, signed URLs fall back to `APP_URL`.

2. **Tunnel → Jetty (optional pattern)** — Point your tunnel (Cloudflare Tunnel, ngrok, Expose, etc.) at a **local Jetty** (or similar) listener that **reverse-proxies** to Laravel on `127.0.0.1:<port>`. Then set `DPLY_PUBLIC_APP_URL` to the tunnel URL. Jetty terminates public TLS; Laravel sees proxied HTTP.

3. **Tunnel → Laravel directly** — Alternatively, tunnel straight to `php artisan serve` / Herd; then Jetty is unnecessary and `DPLY_PUBLIC_APP_URL` is still the tunnel origin.

4. **`APP_URL`** — Used for OAuth redirects and the browser. You may keep `APP_URL` as `http://localhost` while only `DPLY_PUBLIC_APP_URL` is the tunnel for server-side webhooks; align OAuth redirect URIs with whichever URL you actually use to sign in to provider OAuth.

## Jetty in Solo

If you run a local Jetty (or any shell command) for callbacks, set **`JETTY_START_COMMAND`** in `.env` to the full command (e.g. path to `java -jar …` or a wrapper script). The **Jetty** pane appears in `php artisan solo` (lazy; starts when you open that pane). See `.env.example`.

## Related env vars

| Variable | Purpose |
|----------|---------|
| `APP_URL` | Canonical app URL (OAuth, sessions, signed URLs fallback) |
| `DPLY_PUBLIC_APP_URL` | Public HTTPS origin for TaskRunner / inbound hooks when localhost is unreachable |
| `JETTY_START_COMMAND` | Optional; enables Solo **Jetty** pane ([config/solo.php](../config/solo.php)) |
| `DIGITALOCEAN_*` / UI credentials | DigitalOcean API access |

## Troubleshooting

- **Provision stuck** — Confirm `php artisan queue:work` (or Horizon) is running and failed jobs in `failed_jobs` / Horizon dashboard.
- **OAuth mismatch** — Redirect URI must match `APP_URL` + `/credentials/oauth/digitalocean/callback`.
- **Webhook 403 / unreachable** — Confirm tunnel is up, `DPLY_PUBLIC_APP_URL` matches the tunnel origin, and proxy forwards to Laravel’s webhook routes.
