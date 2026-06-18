# Atomic-release deploys

> **The only deployer is `AtomicSiteDeployer`.** Routine deploys go through the
> dashboard Deploy button / `RunSiteDeploymentJob` / `dply:site:deploy`, which
> resolve to `AtomicSiteDeployer` for atomic-strategy sites. It clones into a
> fresh `releases/<ts>`, writes `.env` from the DB via the site's `env_file_path`
> (so `current/.env` is a symlink to the shared env, never a clobbered regular
> file), flips `current`, and health-checks over `https …/up`.
>
> **The old `deploy.sh` shell deployer has been removed.** It is now `commit.sh`,
> which only stages, AI-generates a commit message + CHANGELOG entry, commits, and
> pushes — it does **not** deploy. The `./deploy.sh "…"` commands in the bootstrap
> and day-to-day sections below are historical; the equivalent today is the engine
> (`dply:site:deploy`). Running a second deployer alongside the engine is what
> created prod's hybrid layout + the recurring `.env` reset — there is now only one.

`AtomicSiteDeployer` ships every host (web + workers) as an **immutable release**
and flips an atomic `current` symlink. This is what stops a deploy from breaking
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

`AtomicSiteDeployer` does, on each host: fetch → stage a release →
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

Trigger a deploy through the engine — it creates `repo/`, `releases/<ts>/`, and
the `current` symlink:

```bash
php artisan dply:site:deploy <site>     # or the dashboard Deploy button
```

(The point is that `current -> releases/<ts>` now exists; the supervisor/nginx
repoint in steps 3–4 then moves the running services onto it.)

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
./commit.sh "your message"     # commits + pushes only (AI message + CHANGELOG); no deploy
php artisan dply:site:deploy <site>   # build a release per host + atomic swap (the deploy)
```

Committing and deploying are now two separate steps: `commit.sh` gets the change
onto origin, and the engine (dashboard Deploy / `dply:site:deploy`) builds and
swaps the release. Release retention per host is `DEPLOY_KEEP_RELEASES` (default 5).

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

- **`shared/.env` is sacred.** `AtomicSiteDeployer` refuses to deploy if it's
  missing rather than ship an empty env that nulls `APP_KEY`. See the prod APP_KEY
  caveat in project memory.
- **php-fpm opcache:** the web deploy reloads php-fpm so it picks up the swapped
  `current` realpath. If your fpm service name isn't auto-detected
  (`php8.5-fpm`/`php8.4-fpm`/`php8.3-fpm`/`php-fpm`), add a reload for it.
- **Releases are immutable** — never edit code under `releases/`. Hotfix = deploy.
