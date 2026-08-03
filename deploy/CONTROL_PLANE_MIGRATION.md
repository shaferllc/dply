# Control-plane migration map — Hetzner → AWS (lean EC2)

Moving the dply control plane (the boxes that run dply.io itself) off Hetzner.
Current target: **AWS EC2, `us-west-2`**, funded by Activate credits.

Deliberately provider-neutral. Phases 0 and 3–6 are the same whichever cloud
wins; only Phase 1 (code) and the sizing table are provider-specific. This has
now been scoped for AWS, Vultr, and DigitalOcean — keep it that way so the next
move is a table edit, not a new runbook.

Scope: **control plane only.** Managed customer servers stay on Hetzner
(`DPLY_MANAGED_PROVIDER=hetzner` + the isolated beta project). Nothing here
touches a customer VM. See "Scope — what is deliberately not moving" below.

Related: [SELF_MANAGE.md](SELF_MANAGE.md) (escrow + self-deploy),
[ENV_SYNC.md](ENV_SYNC.md), [ATOMIC_RELEASES.md](ATOMIC_RELEASES.md),
[../docs/dply-production-runtime.md](../docs/dply-production-runtime.md).

---

## Target topology

Region **`us-west-2`** (Oregon) — chosen deliberately; the existing AWS footprint
and account history are there.

**Known tradeoff:** managed customer servers live in Hetzner `fsn1`, so every
SSH round-trip now crosses the Atlantic — roughly 150ms each way versus a few ms
from Frankfurt. TaskRunner deploys are chatty (many sequential round-trips per
step), so remote tasks will be measurably slower than they are today. Capture
the before/after numbers in Phase 0 so the size of the effect is known rather
than argued about. If it turns out to hurt, the options are moving managed
capacity closer to `us-west-2`, or running a second worker in an EU region that
handles the SSH-heavy queues.

| Role | Instance | Why | On-demand $/mo |
|------|----------|-----|----------------|
| Web + Redis | `t4g.medium` (Graviton) + Elastic IP | nginx + php-fpm are bursty and low sustained CPU; Redis is tiny. Co-locating saves an instance without contention. | 24 |
| Worker 1 | `c6a.xlarge` (AMD, x86) + Elastic IP | Edge builds — see burstable warning | 111 |
| Postgres | `m7g.large` (Graviton) + gp3 | Self-hosted — see "no RDS" below | 59 |
| Network | VPC, public subnet, IGW, SGs | No NAT Gateway, no ALB | 0 |
| | | **subtotal** | **~194** |

Plus EBS gp3, snapshots, and egress → **~$230/mo all-in** (first 100 GB/mo of
egress is free, which covers most of the control plane's outbound).

Three cost levers applied, in order of size:

1. **Graviton everywhere except the build worker.** ~20% off web, Redis, and
   Postgres. The build worker must stay x86 (`c6a`/`c6i`) because Edge build
   images are amd64 — on Graviton they'd run under qemu emulation and get
   several times slower. `c6a` (AMD) is ~10% cheaper than `c6i` for the same
   x86 shape, so it's the default here.
2. **Co-locate web + Redis.** Redis on this workload is queues, cache, and the
   schedule mutex — a few hundred MB. It does not need its own instance.
3. **Drop worker-2 from the initial cut.** Add it later once real load is
   visible, and consider **Spot** for the replica specifically: Horizon
   replicas are interruption-tolerant (worker-1 picks the queue back up), which
   takes a second `c6a.xlarge` from ~$111 to ~$35.

Further down-shift if builds turn out not to need 4 cores: `c6a.large`
(2 vCPU) is ~$55 and takes the total to **~$175/mo**. Set
`HORIZON_BUILD_MAX_PROCESSES=2` to match, and watch build queue wait times.

**Do not buy Savings Plans yet** — see runway below.

### What we are deliberately NOT building

The previous AWS attempt died of **"NAT/RDS/ALB burn"**
([AwsControlPlaneTeardownCommand](../app/Console/Commands/AwsControlPlaneTeardownCommand.php)).
That trio is the AWS reference architecture, so it is the shape this will drift
back into unless it stays an explicit decision.

| Not using | Instead | Why |
|-----------|---------|-----|
| NAT Gateway ($32/mo + $0.045/GB) | Public subnet + security groups | Four boxes don't need private subnets. If private subnets become necessary, use a `t4g.nano` NAT instance (~$3/mo), not the managed gateway. |
| ALB (~$20/mo) | Web EC2 + Elastic IP, TLS at Cloudflare | Already fronted by Cloudflare. One box needs no load balancer. |
| RDS | Postgres on EC2 | **Dogfooding requirement**, not just cost — SELF_MANAGE W2/W3 has dply adopt its own Postgres as a `ServerDatabase` with admin credentials and back it up through its own engine. RDS has no SSH, so `dply:self:adopt` and `dply:db:restore` stop applying. |
| ElastiCache | Redis on EC2 | Same shape, ~$20/mo cheaper, and keeps `maxmemory-policy` under our control. |

### Two AWS-specific traps

- **Never put the Edge build worker on `t3`/`t4g`.** Burstable instances run on
  CPU credits; sustained npm/vite builds exhaust them and you are throttled, or
  silently billed unlimited-mode surcharges. Builds need `c6a`/`c6i`/`c7i`. (Graviton
  `c7g` is ~20% cheaper but every Edge build image would need to be arm64 —
  otherwise qemu emulation makes builds several times slower.)
- **Egress is $0.09/GB.** Hetzner, Vultr, and DO bundle terabytes. Deploy
  artifacts and streamed build logs make this a real line item here.

### Elastic IPs: two of them, for different reasons

- **Web EIP** — the inbound address `dply.io` resolves to.
- **Worker EIP(s)** — the *outbound* identity customer servers see when dply
  SSHes in. This is the IP that lands in customer firewall allowlists. It is not
  the web IP, and it is the one that must never change again.

---

## Credit runway (new — this is what makes AWS the right call)

- [ ] Record the **credit amount and expiry date** at the top of this file.
      ~$290/mo on-demand means $5k ≈ 17 months, $25k ≈ 7 years.
- [ ] **Do not buy Savings Plans or Reserved Instances while credits are
      burning.** Credits cover on-demand; a 1- or 3-year commitment locks in
      spend past the point where the credits would have covered it, and past the
      point where you may want to leave. Revisit at ~6 months of runway left.
- [ ] Set **AWS Budgets alarms** at 50 / 75 / 90% of credit consumption, to an
      address that is read. Burn without visibility is exactly how the last
      attempt ended.
- [ ] Confirm what the credits **exclude** — Activate credits typically don't
      cover Marketplace and some managed services. The lean design is EC2 + EBS +
      data transfer, which is normally covered, but verify before assuming.
- [ ] Diary a **decision point at 6 months remaining**: stay and commit, or
      execute the exit below.

### The exit stays cheap on purpose

Build this on the same `deploy/control-plane/` bash scripts and inventory-JSON
pattern as the Vultr path. The bootstrap scripts are provider-agnostic Ubuntu
bash — only the VPC CIDR and instance slugs differ. If credits expire and the
economics stop working, moving to Vultr (~$120/mo) or DO (~$132/mo) is then a
Phase 1 driver swap plus Phases 2–6 unchanged, not a fresh integration.

This is the single most important design constraint in the document. AWS with
credits is cheap; AWS without credits is ~2× the alternatives. Do not build
anything that only works on AWS.

---

## Phase 0 — pre-flight (before touching AWS)

- [ ] **Escrow is current and restorable.** `secrets:escrow` for `platform-env`,
      `db-dump`, and `critical-keys`, then actually run a `secrets:restore` drill
      on a machine holding the offline age identity. The whole secret vault is
      encrypted under `APP_KEY`, which lives in `shared/.env` on the box you're
      about to decommission. Lose both and everything is locked shut. This is
      SELF_MANAGE W1 and it is the one non-negotiable step.
- [ ] `pg_dump` size + row counts. Under ~5 GB → dump/restore inside the window.
      Larger → set up logical replication in Phase 4 instead.
- [ ] Inventory anything pinned to the control plane's **current IP**: customer
      cloud-firewall rules, third-party allowlists, monitoring. (Log drain is
      loopback today — `DPLY_LOG_DRAIN_HOST=127.0.0.1` — so it's *not* on this
      list. Re-confirm at cutover time.)
- [ ] Record baseline Horizon wait times and deploy success rate, so "is AWS
      slower?" is answerable afterwards.
- [ ] Drop the `dply.io` DNS TTL to 60s **at least 24h before** cutover.
- [ ] Tear down the leftover staging VPC from the last attempt:
      `dply:aws:control-plane:teardown --execute`. Start from zero, and don't
      pay for the old NAT/RDS while building the new thing.

## Phase 1 — code

- [ ] Rename `deploy/vultr-control-plane/` → `deploy/control-plane/`. The four
      bootstrap scripts are provider-agnostic Ubuntu bash; only `DPLY_VPC_CIDR`
      changes.
- [ ] Collapse `VultrControlPlane{Provision,Bootstrap,Cutover}Command` into
      `dply:control-plane:{provision,bootstrap,cutover} --provider=aws|vultr|do`
      with a driver behind it. Keep the inventory-JSON shape — Bootstrap and
      Cutover already consume it and are provider-neutral apart from the path.
- [ ] Extend `AwsEc2Service` with the control-plane surface it lacks: VPC +
      subnet + internet gateway, security-group creation with **SG-to-SG rules**
      (not CIDR) for 5432/6379, and Elastic IP allocate/associate. It already has
      `createKeyPair`, `resolveDefaultImageId`, `runInstances`,
      `describeInstances`, `terminateInstances`, `validateCredentials`.
- [ ] Ubuntu 24.04 AMI resolution for `us-west-2` — the bootstrap scripts
      assume `apt`.
- [ ] gp3 volume sizing in the launch spec. The worker needs real disk for
      Docker images plus the Edge build workspace — 100 GB minimum.
- [ ] Commit all of it. The Vultr control-plane work is currently untracked.

## Phase 2 — provision

- [ ] VPC `dply-control-plane` (`10.60.0.0/16`), one public subnet, IGW, route
      table. Single-AZ is an accepted risk for a control plane — note it, don't
      silently assume it.
- [ ] `dply:control-plane:provision --provider=aws --region=us-west-2`
      (dry-run first, then `--execute`).
- [ ] Security groups, SG-to-SG referenced:
      - `web`: 80/443 from anywhere (or Cloudflare ranges), 22 from admin only
      - `worker`: 22 from admin only
      - `postgres`: 5432 **from the web + worker SGs only**
      - `redis`: 6379 **from the web + worker SGs only**
- [ ] Allocate and associate Elastic IPs: web (inbound) **and** each worker
      (outbound SSH identity).
- [ ] EBS snapshot schedule via Data Lifecycle Manager, or dply's own backup
      engine once Phase 6 adoption is done.

## Phase 3 — bootstrap

- [ ] `dply:control-plane:bootstrap --provider=aws --execute` → runs
      `bootstrap-common` → `postgres` / `redis` / `app-layout` per role.
- [ ] On each worker: `sudo php artisan dply:edge:ensure-build-docker`, verify
      with `--check`. Edge builds run as `www-data`, not the SSH user — this is
      the step that silently breaks builds when skipped.
- [ ] Sync `shared/.env` from escrow with the **same `APP_KEY`**, per-host
      `DPLY_RUNTIME` / `DPLY_WORKER_ROLE` / `HORIZON_NAME`, and the new private
      IPs for `DB_HOST` / `REDIS_HOST`.
- [ ] Apply the Horizon pool sizing: `HORIZON_BUILD_MAX_PROCESSES` = the
      worker's `nproc` (4 on `c6a.xlarge`, 2 on `c6a.large`),
      `HORIZON_DEPLOY_MAX_PROCESSES=16`.
      Drop the dead `HORIZON_MAX_PROCESSES` / `HORIZON_QUEUES` / etc. — the
      control plane never read them.
- [ ] Add `REDIS_PERSISTENT=true`.
- [ ] Verify Redis `maxmemory-policy` is **not** `allkeys-lru` — queue (DB 0) and
      cache (DB 1) share the instance, so LRU can evict queued jobs.
- [ ] Deploy a release and confirm `/up`, `dply:about`, and `dply:runtime:check`
      pass **with Horizon still stopped**.

## Phase 4 — data

Postgres and Redis move differently. Redis holds **in-flight jobs** — it is
drained, never copied.

- [ ] Rehearse `pg_dump` → restore into the AWS Postgres, run the app read-only
      against it, and time the restore. That number sets the cutover window.
- [ ] If the dump is too large for the window, set up logical replication ahead
      of time and cut over at lag ≈ 0.
- [ ] Do **not** migrate Redis. Pause dispatch, drain Horizon, verify empty,
      start fresh.

## Phase 5 — cutover window

Order matters. The control plane manages live customer infrastructure, and a
half-migrated control plane can fire duplicate jobs at customer boxes.

1. [ ] **Freeze** — pause the scheduler, enable maintenance mode, stop accepting
       deploys. Announce if customers have scheduled deploys in the window.
2. [ ] **Drain** — `horizon:terminate` on Hetzner workers, wait for queues to
       reach zero. A long Edge build can hold a slot; that's what the wait is
       for. Confirm empty before continuing.
3. [ ] **Final sync** — last `pg_dump` → restore (or confirm replication lag 0),
       then **stop Postgres on Hetzner** so nothing can write to the old DB.
       *This is the point of no return.*
4. [ ] **Flip DNS** — `dply.io` → the web Elastic IP. TTL is already 60s.
5. [ ] **Start AWS** — Horizon on both workers, scheduler on the primary only,
       exit maintenance mode.
6. [ ] **Smoke test, in this order** — web + login → a remote task against one
       managed server (proves SSH egress from the new worker EIP) → a real Edge
       build (proves Docker + build cache + that `c6i` isn't throttling) → a BYO
       deploy → Stripe webhook → a scheduled job firing.
7. [ ] Watch Horizon wait times and failed jobs for the first hour.

## Phase 6 — after

- [ ] `php artisan dply:self:adopt` — re-adopt the new boxes as managed Servers.
      The old Hetzner rows are stale; record the new server IDs into
      `secret_vault.drift.targets` (web + worker).
- [ ] Re-point backup schedules and `secrets:escrow` at the new `ServerDatabase`,
      then confirm a fresh escrow round-trips.
- [ ] Re-onboard dply as a **Site** for self-deploy (SELF_MANAGE W4): atomic
      strategy, external `shared/.env`, `/up` health check with auto-rollback.
      Validate on the replica worker before pointing prod at it.
- [ ] `secrets:check-drift` passes against the new targets.
- [ ] **Keep the Hetzner boxes powered off but intact for 7 days.** Do not delete
      until a full backup + restore drill has run on AWS.
- [ ] Then decommission, and remove the old IP from any allowlists.
- [ ] Confirm AWS Budgets alarms are firing correctly against real spend.

---

## Rollback

Viable until step 5.3 (stopping the old Postgres) — before that, flip DNS back
and restart Horizon on Hetzner. After it, rollback means restoring the AWS
database *back* to Hetzner, because writes have landed on AWS.

Rehearse the DNS flip-back once so it's muscle memory, not a decision made under
pressure.

## Risk register

| Risk | Mitigation |
|------|------------|
| `APP_KEY` lost or mismatched → entire vault unreadable | Phase 0 escrow drill; carry the same key, never regenerate |
| Both control planes live at once → duplicate jobs to customer boxes | Hard freeze + drain in 5.1–5.2; stop old Postgres in 5.3 |
| SSH egress from a new IP blocked by a customer firewall | Phase 0 inventory; worker Elastic IPs so it never changes again |
| Credits expire → cost jumps ~2× overnight | Runway section: budget alarms, 6-month decision point, portable build |
| Drifting back to NAT/RDS/ALB | "What we are deliberately NOT building" is a standing decision, not a default |
| Burstable throttling stalls Edge builds | `c6a`/`c6i`, never `t3`; verify under real build load in 5.6 |
| In-flight Edge build killed mid-cutover | Drain, don't kill; builds are `tries => 1` and won't retry |
| Single-AZ control plane | Accepted; documented in Phase 2 |

## Scope — what is deliberately not moving

Recorded so this isn't re-litigated in six months.

| Layer | Stays | Why |
|-------|-------|-----|
| Managed customer servers | **Hetzner** | CX22 costs $4.50/mo raw vs ~$18–30 for equivalent specs elsewhere ([subscription.php](../config/subscription.php) `managed_server_cents`). At 60% markup that's a 4–6× price increase to customers, or the margin on the entire managed tier. Cheap VMs *are* that tier's economics. |
| Edge (R2 + Workers) | **Cloudflare** | No AWS equivalent to a global edge runtime that wouldn't be a rewrite. CloudFront + Lambda@Edge is a different product with different semantics. |
| Realtime relay | **Cloudflare** | Pusher-compatible Worker; same reasoning. |
| Cloud / Serverless backends | **DigitalOcean** | Already there (App Platform, DO Functions, DO managed DBs). Moving them is a customer-facing migration, not an infra one. |
| Secret escrow + independent DB dump | **A different provider from prod** | SELF_MANAGE W1 requires a separate cloud account by design. If escrow lives on AWS next to prod, one account action takes prod *and* the recovery path, and the `APP_KEY` loop that W1 exists to break closes again. The same blast-radius reasoning as the isolated Hetzner beta project ([managed_servers.php](../config/managed_servers.php)). |

The honest version of "move everything to one cloud" is: move the control plane,
and stop there.
