# Hetzner → DigitalOcean control-plane migration map

Moving the dply control plane (the boxes that run dply.io itself) from Hetzner
to DigitalOcean **Droplets**. Not DOKS — see "Why not Kubernetes" at the bottom.

Scope: the control plane only. **Managed customer servers stay on Hetzner**
(`DPLY_MANAGED_PROVIDER=hetzner`, plus the isolated beta project). Nothing in
this migration touches a customer's VM.

Related: [SELF_MANAGE.md](SELF_MANAGE.md) (escrow + self-deploy),
[ENV_SYNC.md](ENV_SYNC.md), [ATOMIC_RELEASES.md](ATOMIC_RELEASES.md),
[../docs/dply-production-runtime.md](../docs/dply-production-runtime.md).

---

## Target topology

| Role | Hetzner today | DigitalOcean | $/mo |
|------|---------------|--------------|------|
| Web (nginx + php-fpm) | app box | `s-2vcpu-4gb` + **Reserved IP** | 18 |
| Worker 1 (primary) | worker box | `s-4vcpu-8gb` | 48 |
| Worker 2 (replica) | *not deployed* | `s-4vcpu-8gb` | 48 |
| Postgres | `10.0.0.3` | `s-4vcpu-8gb` | 48 |
| Redis | `10.0.0.2` | `s-2vcpu-4gb` | 18 |
| Private network | Hetzner private net `10.0.0.0/24` | VPC `10.60.0.0/20` | free |
| | | **subtotal** | **~180** |

Add weekly backups (+20%, ~$36) and Spaces for escrow/dumps (~$5) → **~$220/mo**
with the replica, **~$170/mo** without it.

Unaffected by this migration: Cloudflare (Edge R2/Workers, realtime relay),
Stripe, GitHub/GitLab OAuth, the managed-server Hetzner projects.

### Region choice

Pick **`fra1`** unless there's a reason not to. The control plane's dominant
workload is SSH round-trips to managed customer servers, which live in Hetzner
`fsn1` — `fra1` keeps that hop at a few ms. A US region adds ~90ms to *every*
step of *every* remote task, and TaskRunner deploys are chatty. This is a real
throughput decision, not a preference.

---

## Phase 0 — pre-flight (do before touching DO)

- [ ] **Escrow is current and restorable.** `secrets:escrow` for `platform-env`,
      `db-dump`, and `critical-keys`, then actually run a `secrets:restore` drill
      on a machine holding the offline age identity. The entire secret vault is
      encrypted under `APP_KEY`, which lives in `shared/.env` on the old box — if
      the migration goes wrong and you've lost both, everything is locked shut.
      This is SELF_MANAGE W1 and it is the one non-negotiable step.
- [ ] `pg_dump` size + row counts. Under ~5 GB → dump/restore in the window.
      Larger → set up logical replication in Phase 4 instead.
- [ ] Inventory what points at the control plane's **current IP**: any customer
      cloud-firewall rules, third-party allowlists, monitoring. (Log drain is
      loopback today — `DPLY_LOG_DRAIN_HOST=127.0.0.1` — so it is *not* on this
      list. Confirm that's still true at cutover time.)
- [ ] Record current Horizon wait times + a deploy success-rate baseline so
      "is DO slower?" is answerable afterwards.
- [ ] Note the current DNS TTL for `dply.io` and **drop it to 60s at least 24h
      before cutover**.

## Phase 1 — code (the only real engineering)

The Vultr control-plane work is uncommitted and half-built. **Do not make a
third provider-specific copy** — this is the third hosting target (AWS →
Vultr → DO). Generalize once:

- [ ] Rename `deploy/vultr-control-plane/` → `deploy/control-plane/`. The four
      bootstrap scripts are provider-agnostic Ubuntu bash already; only
      `DPLY_VPC_CIDR` changes (`10.50.0.0/24` → `10.60.0.0/20`).
- [ ] Collapse `VultrControlPlane{Provision,Bootstrap,Cutover}Command` into
      `dply:control-plane:{provision,bootstrap,cutover} --provider=do|vultr`,
      with a small provider driver behind it. Keep the inventory JSON shape.
- [ ] Extend `app/Modules/Cloud/Services/DigitalOceanService.php` — it is
      DNS-only today (`domainExists()`). It needs the surface `VultrService`
      already has: `createSshKey`, `createDroplet`, `getDroplet`, `createVpc`,
      `listVpcs`, `createFirewall` + rules + assign, and the
      `getPublicIp`/`getPrivateIp` static helpers.
- [ ] Reserved IP: create + assign to the web droplet, and surface it in the
      inventory JSON. This is what makes the control-plane address survive a
      droplet rebuild.
- [ ] Delete `AwsControlPlaneTeardownCommand` once the AWS remnants are gone —
      it exists only to clean up the abandoned experiment.

Reusable as-is: `ProvisionDigitalOceanDropletJob` and `PollDropletIpJob` already
provision DO droplets for customers, so the API client patterns are proven.

## Phase 2 — provision DO

- [ ] Create VPC `dply-control-plane` (`10.60.0.0/20`) in `fra1`.
- [ ] `dply:control-plane:provision --provider=do --region=fra1` (dry-run first,
      then `--execute`). Creates the 4–5 droplets, cloud firewall, SSH key.
- [ ] Cloud firewall: 22/80/443 inbound from anywhere on web; **5432 and 6379
      inbound from the VPC CIDR only**; all outbound allowed (SSH to customer
      boxes, provider APIs).
- [ ] Assign the Reserved IP to the web droplet.
- [ ] Enable weekly backups on all droplets.

## Phase 3 — bootstrap

- [ ] `dply:control-plane:bootstrap --provider=do --execute` → runs
      `bootstrap-common` → `postgres` / `redis` / `app-layout` per role.
- [ ] On each worker: `sudo php artisan dply:edge:ensure-build-docker`, then
      verify with `--check`. Edge builds run as `www-data`, not the SSH user —
      this is the step that silently breaks builds if skipped.
- [ ] Sync `shared/.env` from escrow, **same `APP_KEY`**, with per-host
      `DPLY_RUNTIME` / `DPLY_WORKER_ROLE` / `HORIZON_NAME` overrides and the new
      private IPs for `DB_HOST` / `REDIS_HOST`.
- [ ] Apply the Horizon pool sizing from the perf pass: set
      `HORIZON_BUILD_MAX_PROCESSES` to the worker's `nproc`, and
      `HORIZON_DEPLOY_MAX_PROCESSES=16`. Drop the dead `HORIZON_MAX_PROCESSES`
      /`HORIZON_QUEUES`/etc. — the control plane never read them.
- [ ] Add `REDIS_PERSISTENT=true`.
- [ ] Verify Redis `maxmemory-policy` is **not** `allkeys-lru` — queue (DB 0) and
      cache (DB 1) share the instance, so LRU can evict queued jobs.
- [ ] Deploy a release to the new boxes and confirm `/up`, `dply:about`, and
      `dply:runtime:check` pass **with Horizon still stopped**.

## Phase 4 — data

Postgres and Redis move differently. Redis holds **in-flight jobs** — you drain
it, you never copy it.

- [ ] Rehearse a full `pg_dump` → restore into the DO Postgres and run the app
      read-only against it. Time the restore; that number sets the window.
- [ ] If the dump is too big for the window, set up logical replication from
      Hetzner → DO ahead of time and cut over on replica lag ≈ 0.
- [ ] Do **not** migrate Redis. Plan is: pause dispatch, let Horizon drain,
      verify empty, then start fresh on DO.

## Phase 5 — cutover window

Order matters — the control plane manages live customer infrastructure, and a
half-migrated control plane can fire duplicate jobs at customer boxes.

1. [ ] **Freeze**: pause the scheduler, put the app in maintenance mode, and stop
       accepting deploys. Announce the window if customers have scheduled deploys.
2. [ ] **Drain**: `horizon:terminate` on Hetzner workers, wait for queues to hit
       zero. A long Edge build can hold a slot for a while — that's what the wait
       is for. Confirm empty before proceeding.
3. [ ] **Final sync**: last `pg_dump` → restore (or confirm replication lag 0),
       then **stop Postgres on Hetzner** so nothing can write to the old DB.
4. [ ] **Flip DNS**: `dply.io` → the DO Reserved IP. TTL is already 60s.
5. [ ] **Start DO**: Horizon on both workers, scheduler on primary only, then
       exit maintenance mode.
6. [ ] **Smoke test, in this order**: web + login → a remote task against one
       managed server (proves SSH egress works from the new IP) → a real Edge
       build (proves Docker + the build cache) → a BYO deploy → Stripe webhook →
       a scheduled job firing.
7. [ ] Watch Horizon wait times and failed jobs for the first hour.

## Phase 6 — after

- [ ] `php artisan dply:self:adopt` — re-adopt the *new* boxes as managed
      Servers. The old Hetzner server rows are now stale; record the new server
      IDs into `secret_vault.drift.targets` (web + worker).
- [ ] Re-point backup schedules + `secrets:escrow` at the new `ServerDatabase`,
      then confirm a fresh escrow round-trips.
- [ ] Re-onboard dply as a **Site** for self-deploy (SELF_MANAGE W4): atomic
      strategy, external `shared/.env`, `/up` health check with auto-rollback.
      Validate on the replica worker before pointing prod at it.
- [ ] `secrets:check-drift` passes against the new targets.
- [ ] **Keep the Hetzner boxes powered off but intact for 7 days.** Do not delete
      them until a full backup + restore drill has run on DO.
- [ ] Then decommission, and remove the old IP from any allowlists.

---

## Rollback

Viable up until step 5.3 (stopping the old Postgres) — before that, flip DNS
back and restart Horizon on Hetzner. After that point, rolling back means
restoring the DO database *back* to Hetzner, because writes have landed on DO.

Rehearse the DNS flip-back once, so it's muscle memory rather than a decision
made under pressure.

## Risk register

| Risk | Mitigation |
|------|------------|
| `APP_KEY` lost or mismatched → entire vault unreadable | Phase 0 escrow drill; carry the same key, never regenerate |
| Both control planes live at once → duplicate jobs to customer boxes | Hard freeze + drain in 5.1–5.2; stop old Postgres in 5.3 |
| SSH egress from new IP blocked by a customer firewall | Phase 0 inventory; Reserved IP so it never changes again |
| In-flight Edge build killed mid-cutover | Drain, don't kill; builds are `tries => 1` and won't retry |
| Region latency to Hetzner-hosted customer servers | Choose `fra1` |
| Log drain exposed later, still pointing at old host | It's loopback today; re-check before cutover |

## Why not Kubernetes

DOKS was considered and deferred. dply's deploy engine for k8s
(`KubernetesDeployEngine` + `KubernetesManifestBuilder`) emits a single
`replicas: 1` Deployment from a pre-built image — it can't yet build images,
express five workloads from one repo, run a migration job, or attach the
privileged DinD sidecar Edge builds need. Hosting dply on DOKS therefore means
*building that engine first*, and it means dply stops deploying dply in the
meantime.

The sequencing instead: move to Droplets now (mature path, dogfooding intact),
then grow the k8s engine to v1 against a ~$48/mo staging DOKS cluster that dply
itself deploys to. When dply can deploy dply onto k8s, that's the moment to
reconsider prod — and by then it's a shipped feature, not just a hosting bill.
