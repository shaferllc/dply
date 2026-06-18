# Env sync & drift prevention (web ↔ workers)

## The problem this solves

dply's control plane runs on at least two roles — a **web** box and one or more
**worker** boxes — and each keeps its **own** hand-maintained `shared/.env`.
Those files drift. Because most platform work (provisioning, edge/Cloudflare
publishes, billing, DNS) runs in **queued jobs on the worker**, a secret that
exists only on the web box fails *silently* on the worker.

Real incidents this caused:

- **Testing-hostname provisioning** reported `disabled` — the gate runs in
  `ProvisionSiteJob` (default `dply` queue → worker), and the worker `.env` was
  missing `DPLY_AUTO_TESTING_HOSTNAME_ENABLED`, `DPLY_TESTING_DOMAINS*`, and
  `DIGITALOCEAN_TOKEN`.
- **Edge/Cloud publishes** (`PublishEdgeDeploymentJob`, `ProvisionCloudSiteJob`)
  had no Cloudflare/R2 creds on the worker.
- **Billing jobs** had no `STRIPE_SECRET` on the worker.
- **Broadcasting**: the web box was still on retired `reverb` while the worker
  was on the managed `pusher` relay.

## The model: SHARED base + per-role overlay

Every env key is exactly one of three things:

| Class | Lives on | Source of truth |
|-------|----------|-----------------|
| **shared** | web **and** every worker, identical values | the web box `shared/.env` |
| **app-only** | web only | `deploy/env/app-only.keys` |
| **worker-only** | workers only | `deploy/env/worker-only.keys` |

A key is **shared by default** — it is app-only or worker-only *only* if it is
listed in the matching allowlist. New secrets are therefore shared unless you
deliberately scope them, which is the safe default (a forgotten secret ends up
everywhere, not missing on the box that needs it).

## Drift detection (automated, value-free)

`deploy/check-env-drift.sh` reads **key names only** from each box (nothing
secret is read, transferred, or printed), classifies them against the
allowlists, and reports any **shared** key missing on web or on a worker.

```bash
./deploy/check-env-drift.sh                  # warn-only
DEPLOY_STRICT_ENV=1 ./deploy/check-env-drift.sh   # exit 1 on drift
```

Run it before a deploy — by hand or as a pre-deploy gate (warn-only unless
`DEPLOY_STRICT_ENV=1`). It was previously a `deploy.sh` preflight; that shell
deployer is retired (now `commit.sh`, commit-only), so this check is operator-run
until it's wired into the deploy engine.

## Reconciling drift (operator-run, secrets stay on the boxes)

When the checker reports a shared key missing on the worker, mirror it
**server-to-server** so the value never lands on your laptop. Always back up
first:

```bash
# back up the worker .env
ssh "$WORKER" 'cp -a /path/shared/.env /path/shared/.env.bak.$(date +%s)'

# append the missing keys, pulled straight from web → worker (no local copy)
KEYS='^(KEY_A|KEY_B|KEY_C)='
ssh "$WEB" "grep -E '$KEYS' /web/shared/.env" \
  | ssh "$WORKER" "{ echo; echo '# synced from web'; cat; } >> /worker/shared/.env"

# re-cache config + bounce the worker Horizon so jobs pick up the new values
ssh "$WORKER" 'cd /worker/current && php artisan config:cache'
ssh "$WORKER" 'sudo systemctl restart dply-site-*-horizon.service'
```

> **Why `config:cache` + Horizon restart is mandatory:** a config-cached box
> ignores `.env` edits until the cache is rebuilt, and long-running Horizon
> workers keep the old config in memory until restarted. Editing `.env` alone
> is a no-op.

## APP_KEY must be byte-identical on every role

`APP_KEY` is special: it's a SHARED key whose **value** must match exactly across
web + workers, not just its presence. Prod once ran **three different** `APP_KEY`s
(one per box), which:

- 500'd the web with `DecryptException: The MAC is invalid` (rows encrypted with
  one key can't be decrypted with another), and
- 404'd Livewire's `livewire.min.js` (the asset route hash is derived from
  `APP_KEY`; changing the key without rebuilding `route:cache` desyncs the
  rendered URL from the registered route).

`check-env-drift.sh` therefore hashes the `APP_KEY` **value** on each box (digest
only — the secret never leaves the box) and fails if they differ. Extend the set
with `DEPLOY_CRITICAL_VALUE_KEYS="APP_KEY OTHER_KEY"` if more keys need identical
values.

Rules:
- `APP_KEY` must come from **one source** (a deploy secret store), written
  identically to every box — never `key:generate`d per-box.
- After ANY `APP_KEY` change, rebuild **both** `config:cache` **and**
  `route:cache` on each box (and reload php-fpm on web / restart Horizon on
  workers). `AtomicSiteDeployer` runs `route:cache` on every release, so a
  normal deploy is safe; the hazard is out-of-band edits.

## Maintaining the allowlists

- Adding a **shared** secret: just add it to the web `shared/.env` and run the
  reconcile snippet (or the next deploy preflight will flag it).
- Adding an **app-only** or **worker-only** key: add the key **name** to the
  matching file in `deploy/env/` so the checker stops reporting it as drift.
- Removing a stale key (e.g. `REVERB_PORT`): remove it from both boxes and from
  the allowlist.
