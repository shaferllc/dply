# ADR: Backups as a product — snapshots, tiers, and a scheduler that runs

Status: accepted (2026-08-10)

Amends `docs/adr/managed-services-tier.md` (decision 2; see "Amendments" below).

## Context

Backups is GA and free. `config/features.php:163` defaults `workspace.backups`
to `true`, the surface spans `/backups` (org) and `/servers/{server}/backups`
(per-server), and `app/Modules/Billing` contains zero references to it.
`docs/adr/managed-services-tier.md` recorded that as a decision: Backups is nav,
not revenue.

Three things make that the wrong resting place.

**The engine does not run.** `ManagesServerBackupSchedules:73` creates a
`ServerCronJob` whose command is
`php {control-plane base_path}/artisan dply:run-backup-schedule {id}`, flagged
`system_managed`. `ServerCronSynchronizer` **rejects** `system_managed` jobs
when it builds a server's crontab (lines 61 and 94), so that line is never
written to any machine. `DplySchedule` has no entry that iterates
`ServerBackupSchedule` or `RedisSnapshotSchedule`. `RunBackupScheduleCommand`
and `RunRedisSnapshotScheduleCommand` therefore only ever execute when a human
types them. **No scheduled backup has ever fired automatically**, and
`PruneBackupsCommand` — the only thing enforcing the 90-day retention the code
advertises — is not scheduled either, so nothing has ever been pruned.

The UI has been reporting this in plain sight: a schedule row reading *Active ·
Every 15 minutes · Last run: Never*.

**The snapshot product already exists, in the wrong place.**
`app/Livewire/Servers/WorkspaceSnapshots` is a four-tab hub — images (full-disk
provider images), cache (Redis RDB), databases (per-site `Snapshot`), volumes
(stubbed, no backend) — scoped to one server. `ServerImageProvider` wraps
DigitalOcean, Hetzner, Vultr and Linode behind
`ServerProvider::supportsImageSnapshots()`, `ServerImage` records
`provider_image_id`/`region`/`bytes`, and `CreateServerImageJob` already runs
the create-and-poll. What is missing is everything that makes it a product:
recurring policies, retention, a fleet view, and a reason for a competitor's
customer to move. SimpleBackups and SnapShooter sell precisely this.

**Schedules are already forking.** `RedisSnapshotSchedule` is a near-copy of
`ServerBackupSchedule` — same `cron_expression`, `is_active`, `last_run_at`,
`notify_on_failure`, `backup_configuration_id`, `server_cron_job_id` — because
when the second kind of scheduled capture arrived it was cloned rather than
generalised. Server images would be the third clone and volumes the fourth.

## Decision

1. **Backups becomes a paid product with five tabs**: Overview, Databases,
   Files, Snapshots, Storage.

   Overview is posture (coverage, 14-day run history, gaps, cross-type
   activity, destination summary). Each type tab owns its type end-to-end —
   targets, schedules, retention, run history, type-specific actions — so
   Overview stops duplicating the schedules table. Storage owns destinations.

2. **`/backups/snapshots` is a fleet roll-up; the server workspace stays.**
   The org page owns what does not exist per-server: every `ServerImage` across
   the fleet, recurring image policies, retention, and coverage.
   `/servers/{server}/snapshots` remains the per-server detail and action
   surface, unchanged, including the cache/databases/volumes tabs — those are
   server-local concepts and moving them to an org page would strand them.

   This is the relationship `/backups` already has to
   `/servers/{server}/backups`. Deleting the workspace tab instead was
   rejected: the workspace's contract is "everything about this server".

3. **Priced as one org-level add-on, tiered on protected resources.** Same
   shape as `config/realtime.tiers` and `queue_service.tiers`, a new
   `standard_backups_tier_` role prefix in `StripeBillingProvisioner`, one
   subscription item per org.

   Per-resource line-item pricing (SnapShooter's model) was rejected because
   `config/subscription.php:49` already meters the base plan by **BYO server
   count** — a per-server backup charge bills a second time on a unit the
   customer is already paying for, taking a 10-server Pro org from $19 to $49
   with no new nav. Metered pricing was rejected for the reason
   `docs/adr/managed-services-tier.md` decision 6 gives: capacity as the
   limiter is a price a customer can plan around; "$0 until it isn't" is not.

   Provisional table, pending the Open item on free-tier sizing:

   | Tier | Price | Resources | Retention | Includes |
   |---|---|---|---|---|
   | free | $0 | 1 | 7d | manual + 1 schedule |
   | pro | $19/mo | 10 | 30d | provider snapshots |
   | business | $49/mo | unlimited | 90d | cross-region, restore drills |

4. **One protected resource = one distinct target under protection.** A server,
   a site, or a database counts once when at least one active schedule or image
   policy points at it. Two schedules on one database is one resource — a
   customer splitting nightly and weekly retention is practising good hygiene,
   not consuming double.

   Counting schedules was rejected for that reason. Counting *servers* was
   rejected because `Site.server_id` is nullable: serverless, Edge and Cloud
   sites have no server and would be uncountable.

5. **The cap blocks new protection; it never stops existing protection.** At
   the cap, "Protect this" is disabled with an upgrade path. On downgrade,
   trial end, or cancellation, every existing schedule keeps running and the
   org sees "15 protected, tier allows 10 — upgrade or release 5". Nothing is
   auto-paused, ever, on any timer.

   This extends `QueueEntitlement`'s stated fail-open convention ("a queue that
   silently starts rejecting pushes is worse than one that costs us a little")
   to the case where it matters most: a customer who believes they are covered
   and is not. dply's marginal cost for an over-cap resource is near zero —
   dumps land in the customer's own bucket and provider images bill to the
   customer's own cloud account.

6. **Restore and download are never gated.** Not at the cap, not after
   downgrade, not after cancellation, not on the free tier. The tier sells
   automation — schedules, provider snapshots, retention depth, destination
   count, restore drills. Holding a customer's own data behind a payment wall
   is disqualifying for a product whose entire value is trust.

7. **Enforcement starts on day one; no grandfathered allowance.** Existing orgs
   over the free tier keep everything running (decision 5) and see the upgrade
   banner immediately. A `legacy_allowance` column was considered and declined:
   usage is still trial-era, and two permanent classes of customer is a tax on
   every future pricing change.

8. **One target-typed `backup_schedules` table.** `target_type` ∈ `database` |
   `site_files` | `cache` | `server_image`, with `volume` reserved.
   `redis_snapshot_schedules` migrates in and `RunRedisSnapshotScheduleCommand`
   folds into the single runner.

   Decision 4 makes the resource count a **billing input**. A number that
   determines an invoice must not be a `UNION` across three tables that have
   already demonstrated they drift.

9. **Retention is per-schedule (`keep_last`, `keep_days`), clamped by tier.**
   The UI and the writer clamp `keep_days` to the tier ceiling; the ceiling is
   what the customer buys. Uniform tier-wide retention was rejected — 7 days on
   a chatty staging database and 90 on production is the normal case. GFS
   (daily/weekly/monthly buckets) is a later Business-tier addition, not v1: it
   needs a pruner that classifies every run and never deletes the last of a
   bucket, and it is destructive against customer infrastructure when it is
   wrong.

10. **Pruning deletes only dply-created images, and never the newest successful
    one.** An image is eligible only when a `ServerImage` row owns it (labelled
    `dply=true` where the provider supports labels — Hetzner's
    `createImageFromServer` takes them). Images made by hand in a provider
    console are listed read-only and never touched. The newest successful image
    for a server survives its own retention policy: an expired-but-only image
    beats zero images. Every deletion is audit-logged with the policy that
    caused it.

    This is dply issuing `DELETE` against a customer's cloud account. The
    permissive variant — adopt and prune everything on the server — was
    rejected: the hand-made `pre-migration` image is exactly the one they will
    want back.

11. **Provider images are taken live and labelled crash-consistent.** No timed
    power-offs, no `fsfreeze`, no `FLUSH TABLES WITH READ LOCK` held across a
    3–8 minute provider API call. The paired database dump is the
    application-consistent artifact; the image is the fast-recovery one, and
    the Overview's gaps band treats "imaged, no dump" as a real gap.

    Quiescing over SSH was rejected for v1 on blast radius: `fsfreeze` on a root
    filesystem can wedge a machine, and the lock is held for the length of a
    third-party API call we do not control. `powerOffServer()` exists and stays
    available to the clone flow, not to a schedule.

12. **Coverage means "any active protection", with gaps listed separately.** A
    bare-metal `Custom` server with a nightly dump is protected, so 100% stays
    reachable. Capability-aware gaps ("imaged, no DB dump"; "images n/a on
    Custom") carry the upsell without the hero turning into a scold. Per-type
    coverage bars were rejected: the hero's job is to answer "am I safe?" in one
    glance.

13. **Storage moves to `/backups/storage`.** `profile.backup-configurations`
    (`routes/web.php:523`) 301s there; the settings sidebar links out. The URL
    also stops lying — `BackupConfiguration` is org-scoped
    (`$org->backupConfigurations()`) behind a `/profile/` path.

14. **The control plane runs every schedule.** One minute-ticking dispatcher
    evaluates due schedules and invokes the existing per-schedule runner;
    `PruneBackupsCommand` gets a daily slot. The `system_managed`
    `ServerCronJob` bookkeeping rows stop being created.

    On-server crontab entries were rejected: a provider image must be capturable
    when the box is down or wedged — which is when you want it most — and an
    on-server cron dies with the server it is meant to protect. It also
    reintroduces per-box drift on rebuild.

15. **Shipping order.** M0 engine → M1 unified schedules + five tabs (free) →
    M2 image policies, retention, restore paths → M3 tiers, Stripe,
    enforcement, customer docs and a pricing-page row **in one release**.
    Nothing is sold before it works, and M3's four parts ship together
    specifically so Backups does not repeat Realtime's state: priced, and
    undocumented.

## Boundaries

| Concern | Owner |
|---|---|
| Whether a schedule is due | `DispatchDueBackupSchedulesCommand` (control plane) |
| What one schedule does when it fires | `RunBackupScheduleCommand` |
| What counts as a protected resource | one `DISTINCT (target_type, target_id)` over `backup_schedules` |
| What a tier costs and caps | `config/backups.tiers` |
| Whether an image may be deleted | `ServerImage` ownership + the newest-successful invariant |
| Which providers can be imaged | `ServerProvider::supportsImageSnapshots()` |
| Where a dump lands | `BackupConfiguration` (customer's bucket) |
| Where an image lands | the customer's provider account — dply stores no image bytes |
| Restore availability | never gated; not a billing concern |

## Amendments to `docs/adr/managed-services-tier.md`

- **Decision 2** ("Backups does not bill at all… is site/server-scoped rather
  than an org-owned provisioned resource") is superseded. Backups bills, per
  decision 3 here. The reasoning that produced it still holds for its own
  question — "Services" remains nav rather than a type, and no `ManagedService`
  contract is introduced. The Boundaries row "Backups' price | none; Backups
  does not bill" is replaced by `config/backups.tiers`.
- **Decision 1** is unaffected: Backups stays in the Services nav row.

## Consequences

**M0** — `DispatchDueBackupSchedulesCommand` plus two `DplySchedule` entries;
`PruneBackupsCommand` starts deleting artifacts that are years past a retention
window nobody has ever enforced, so it lands behind `--dry-run` verification
first. Schedules that have been "Active · Never" for months begin firing on
their stated cadence, which for an every-15-minutes schedule is a visible change
in load. `system_managed` `ServerCronJob` rows stop being created; existing rows
are inert and are swept by M1's migration.

**M1** — the `backup_schedules` migration absorbs `redis_snapshot_schedules`;
`ManagesRedisSnapshots`, `WorkspaceBackups`, `WorkspaceSnapshots`,
`ManagesServerBackupSchedules` and both index components repoint;
`server_cron_job_id` drops. Five tabs, still free.

**M2** — image policies as a `server_image` target type; per-schedule retention
columns; the pruner with its ownership and newest-successful invariants;
restore-from-image reusing the `CloneServerOnDigitalOcean` path.

**M3** — `config/backups.php` with tiers; `standard_backups_tier_` roles;
`organizations.backups_tier` plus an observer mirroring
`RealtimeAppBillingObserver`; the resource count threaded through
`OrganizationBillingStateComputer` and `DesiredBillingState` (another product
triplet on a constructor already past 24 parameters — same deliberate deferral
the Queue ADR recorded); over-cap and prune notification events in
`config/notification_events.php`; `docs/BACKUPS.md`; a pricing-page row.

## Open

- **Free-tier sizing.** One resource, with day-one enforcement (decision 7),
  is the number that decides how the launch feels. Not settled.
- **Volumes.** The workspace tab is stubbed with no backend. In the product or
  cut from the strip before it is sold.
- **Provider coverage.** AWS, UpCloud, OVH and the rest have no image support;
  `Custom` never will. Which is next, and whether image-incapable servers
  should be excluded from snapshot upsell prompts entirely.
- **Naming collision.** `Snapshot` (per-site database snapshots), `ServerImage`
  (provider images) and the "Snapshots" tab all appear in one UI meaning three
  different things.
- **Restore drills** are listed as a Business-tier feature in decision 3 but
  have no design. They are the "verified backup" story competitors sell, and
  nothing about them is built.
