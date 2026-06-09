# Atomic-release deploys

`deploy.sh` ships every host (web + workers) as an **immutable release** and flips
an atomic `current` symlink. This is what stops a deploy from breaking
queued-job deserialization: a long-running Horizon never half-sees new code, and
a restart relaunches it against a complete, self-consistent release.

## Layout (per host, under `$ROOT`)

```
$ROOT/                         # /home/dply/dply (web), /home/dply/worker-1.dply.io (worker)
  repo/                        # persistent bare git mirror (fetch target)
  shared/.env                  # the live env — NEVER inside a release
  shared/storage/              # persistent storage (logs, keys, uploads)
  releases/<timestamp>/        # immutable build artifact for one deploy
  current -> releases/<ts>     # the pointer nginx + supervisor read
```

`deploy.sh` (section 2/3) does, on each host: fetch → `git archive` a release →
symlink `shared/.env` + `shared/storage` in → `composer install` (+ `npm build`
on web) → cache config/route/event/view → `storage:link` + `migrate` (web) →
**atomic `mv -T` swap of `current`** → restart daemons → prune old releases.

---

## One-time bootstrap

> Until this is done, the hosts are flat `git pull` checkouts and `current`
> either doesn't exist or (on the worker) points at a stale leftover release.
> Do the **worker first** (lower blast radius), confirm jobs drain, then web.

Run as the `dply` user unless noted. Replace `<ROOT>` with the host's app dir.

### 1. Seed `shared/` from the existing flat checkout

The flat checkout already holds the correct `.env` (with the live `APP_KEY`) and
`storage/`. **Move** them into `shared/` so releases inherit them — do not
recreate `.env`, or every encrypted secret breaks.

```bash
cd <ROOT>
mkdir -p shared
# .env: copy (keep a flat copy as backup until cutover is confirmed)
cp -a .env shared/.env
# storage: move the live one so logs/keys/uploads are preserved
mv storage shared/storage
```

### 2. Build the first release

From your laptop, just run the new deploy once — it creates `repo/`,
`releases/<ts>/`, and the `current` symlink:

```bash
./deploy.sh "bootstrap atomic releases"
```

(The daemon restarts in this run still hit the *flat* paths — harmless, the flat
checkout is the same commit. The point is that `current -> releases/<ts>` now
exists.)

### 3. Repoint supervisor at `current/`

**Worker** — `/etc/supervisor/conf.d/dply.conf` and `dply-default-worker.conf`:
change every `command=` / `directory=` from
`/home/dply/worker-1.dply.io/artisan` → `/home/dply/worker-1.dply.io/current/artisan`
(and the matching `directory=.../current`).

**Web** — `dply-pulse.conf`, `dply-default-worker.conf`: same swap,
`/home/dply/dply/...` → `/home/dply/dply/current/...`. (The old
`dply-reverb.conf` is retired — realtime is now the Cloudflare relay; remove
that conf from the box if it's still present.)

Then on each host:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart all
sudo supervisorctl status        # confirm everything RUNNING on current/
```

### 4. Repoint nginx docroot at `current/public` (web only)

Find the dply.io server block (`grep -rl dply.io /etc/nginx/`), change its
`root` from `/home/dply/dply/public` → `/home/dply/dply/current/public`, then:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### 5. Verify, then retire the flat checkout

- Web: load https://dply.io and the failing setup URL.
- Worker: `php <ROOT>/current/artisan horizon:status`; watch a job process clean.

Once confirmed, the old flat files at `<ROOT>` (the `.git`, `app/`, `public/`,
etc. that predate `repo/`/`releases/`/`current/`) are inert and can be removed at
leisure. Keep `shared/`, `releases/`, `current`, and `repo/`.

---

## Day-to-day

```bash
./deploy.sh "your message"     # commits, pushes, builds a release per host, swaps
```

Knobs (`.deploy.env`):

- `DEPLOY_KEEP_RELEASES` — releases retained per host (default 5).
- `DEPLOY_HOST`, `DEPLOY_APP_DIR` — web host + `$ROOT`.
- `DEPLOY_WORKER_HOSTS`, `DEPLOY_WORKER_APP_DIR` — workers + `$ROOT`.

## Rollback

```bash
ssh <host>
cd <ROOT>
ls -1dt releases/*/                       # pick the previous good <ts>
ln -sfn releases/<ts> current.tmp && mv -Tf current.tmp current
sudo supervisorctl restart all            # worker
# web: also reload php-fpm + nginx
```

## Notes / gotchas

- **`shared/.env` is sacred.** `deploy.sh` refuses to deploy if it's missing
  rather than ship an empty env that nulls `APP_KEY`. See the prod APP_KEY
  caveat in project memory.
- **php-fpm opcache:** the web deploy reloads php-fpm so it picks up the swapped
  `current` realpath. If your fpm service name isn't auto-detected
  (`php8.5-fpm`/`php8.4-fpm`/`php8.3-fpm`/`php-fpm`), add a reload for it.
- **Releases are immutable** — never edit code under `releases/`. Hotfix = deploy.
