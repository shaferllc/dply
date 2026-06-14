# Self-deploy: dply deploys dply

Status: design. Goal: dply ships its own control plane (web + workers) through the
**same managed-site deploy pipeline it gives customers**, so the control plane is
fully dogfooded.

> **Update:** the hand-maintained `deploy.sh` shell deployer has already been
> removed — it is now `commit.sh`, which only commits + pushes. That leaves
> `AtomicSiteDeployer` (the managed-site engine) as the **only** deployer, so the
> "retire deploy.sh" goal is met by deletion rather than migration. The three gaps
> below are still the work needed to make engine self-deploy safe end-to-end.

## Why

The old `deploy.sh` left three things to humans, and all three bit prod on
2026-06-09 (see the incident memories). They remain the gaps the engine path must
close:

1. **nginx cutover was manual.** The shell deployer built atomic releases and
   swapped `current` → `releases/<ts>`, but repointing the nginx docroot from the old
   flat `public/` to `current/public/` is a manual step in
   `deploy/ATOMIC_RELEASES.md`. It was never done → prod served 4-day-old code.
2. **No env source of truth.** web and workers each hand-edit their own
   `shared/.env`; they drifted (worker missing ~41 platform secrets; app on a
   retired Reverb config). Queued jobs run on the worker, so the gaps failed
   silently.
3. **APP_KEY per-box.** Three different `APP_KEY`s across boxes → `MAC is invalid`
   500s and Livewire asset 404s. No secret store; nothing enforced parity.

dply.io is **already a managed site** on the box
(`/etc/nginx/sites-available/dply-dply.io-…conf`, its own PHP-FPM pool). The
deploy *engine* for managed sites already exists (`RunSiteDeploymentJob`, atomic
releases, phase timeline). The work is to point that engine at the control plane
itself and to close the three gaps above so it's safe.

## Target architecture

The control plane becomes **two managed Sites** sharing one repo:

| Site | Role | Box | Runtime |
|------|------|-----|---------|
| `dply.io` (web) | HTTP | `dply-app` | nginx vhost → `current/public` + dedicated PHP-FPM pool |
| `dply-worker` (headless) | queue | `dply-worker-1` | systemd `dply-site-*-horizon.service` on `current/` |

Both deploy from the same git source via the existing immutable-release layout
(`repo/` bare mirror, `shared/.env`, `shared/storage`, `releases/<ts>`,
`current` symlink). A deploy is the existing pipeline plus the three fixes:

### Fix 1 — own the nginx cutover (no manual step)
The managed-site web deploy already writes the vhost. Make the vhost `root`
**always** `…/current/public` (never the flat checkout), and have the deploy
**reload nginx + the site's PHP-FPM pool** on every release swap. Because dply
generates this vhost, "the cutover" becomes a property of the generated config,
not a runbook step. (Today the generated root was correct; the *flat* checkout
was a pre-managed leftover — deleting `/home/dply/dply/public` as a real dir and
ensuring only `current/public` exists removes the foot-gun.)

### Fix 2 — env base/overlay, synced on deploy
Adopt the model already committed in `deploy/ENV_SYNC.md`:
- canonical SHARED env lives once (secret store, see Fix 3);
- `deploy/env/{app-only,worker-only}.keys` scope the per-role exceptions;
- the deploy **renders each box's `shared/.env`** = SHARED ⊕ role-overlay, instead
  of trusting whatever was hand-edited there.
- `check-env-drift.sh` runs as a **preflight** (was wired into the old `deploy.sh`;
  now operator-run until re-wired into the engine) and as a post-deploy assertion.
  Strict mode (`DEPLOY_STRICT_ENV=1`) fails the deploy on drift.

### Fix 3 — APP_KEY (and all secrets) from one store
- `APP_KEY` and shared secrets come from a **single secret source** (the deploy
  secret store / `.deploy.env` today, a real KMS/secrets manager later), written
  **identically** to every box. Never `key:generate` per-box.
- `check-env-drift.sh` already asserts `APP_KEY` **value** parity (hash only).
- Every deploy rebuilds **`config:cache` AND `route:cache`** after writing env
  (the existing pipeline does; the hazard is only out-of-band edits). Required
  because Livewire's asset URL hash is `APP_KEY`-derived.

## Cache discipline (the non-obvious part)
After any release swap OR env/key change, on the **web** box:
`config:cache` → `route:cache` → `view:cache` → reload php-fpm pool.
On the **worker**: `config:cache` → `route:cache` → restart
`dply-site-*-horizon.service`. The existing `deploy_release` already does the
caches; the additions are (a) always reload the *site's* FPM pool, and (b) treat
an env change as requiring the same cache rebuild, not just a `.env` write.

## Migration plan

1. **Phase 0 (done in this session):** env base/overlay + drift checker +
   APP_KEY value guard committed (`chore/env-drift-prevention`). APP_KEY aligned;
   nginx cut over by hand; broadcasting + platform secrets synced.
2. **Phase 1 — make the generated vhost authoritative.** Ensure the dply.io
   managed-site vhost always roots at `current/public`, reloads nginx + its FPM
   pool on swap, and remove the flat `/home/dply/dply/public` directory so it
   can't be served. Verify a managed-site re-apply doesn't regress the root.
3. **Phase 2 — register the worker as a headless Site** deploying the same repo;
   its Horizon already runs as the managed `dply-site-*-horizon.service`.
4. **Phase 3 — env rendering from the store.** Deploy writes `shared/.env` from
   SHARED⊕overlay instead of trusting the box; `DEPLOY_STRICT_ENV=1`.
5. **Phase 4 — trigger self-deploy from dply** (a "Deploy control plane" action /
   webhook on push to `main`) using `RunSiteDeploymentJob`, with the drift
   preflight as a gate.
6. **Phase 5 — done early: `deploy.sh` deploy machinery deleted** (it's now
   commit-only `commit.sh`). Phases 1–4 remain the work to prove engine self-deploy
   on a staging control-plane site; until then, deploys run through the engine
   manually and on-box recovery is the manual path in `deploy/SELF_MANAGE.md`.

## Open questions
- Secret store: `.deploy.env` now; do we move `APP_KEY` + provider tokens into a
  real manager (1Password/Vault/SSM) before Phase 4?
- Migrations: keep running once from the web release pre-swap (current behavior).
- Rollback: `current` → previous `releases/<ts>` + cache rebuild; needs a
  one-command path in the self-deploy action.

## Related
- `deploy/ENV_SYNC.md`, `deploy/check-env-drift.sh`, `deploy/ATOMIC_RELEASES.md`
- Memories: prod APP_KEY trifurcation, deploy nginx-never-cutover, worker env drift.
