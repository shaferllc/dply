# ADR: Managed Services — Realtime and Queue as first-class products

Status: accepted (2026-08-10)

Amends `docs/adr/dply-queue.md` (decisions 2 and 9; see "Amendments" below).

## Context

dply sells two org-owned managed services that its own compute products lean
on. Neither is presented as a product.

**Realtime** is finished on the revenue side — three connection tiers at
$15/$49/$149 per active app (`config/realtime.php`), per-tier Stripe prices via
`StripeBillingProvisioner::ROLE_REALTIME_TIER_PREFIX`, `RealtimeAppBillingObserver`
resyncing on status/tier change, caps enforced in the data plane through the
Worker's KV `maxConnections`. Its only entry point is a link in
`organization-shell.blade.php`, filed between "Notification channels" and
"Server providers". It has no customer documentation (one file in `docs/`, an
internal go-live runbook) and no price on the pricing page.

**dply Queue** is the mirror image: the data plane is built to
`docs/adr/dply-queue.md` through M3, pricing dials are written, and there is no
UI at all — no route, no Livewire component, no nav entry, nothing wired into
`OrganizationBillingStateComputer`. `surface.queue` defaults off.

Meanwhile `compute-index-nav.blade.php` is the real product row — Sites,
Servers, Projects, Fleet, Backups, Edge, Cloud, Serverless — and both services
are absent from it.

## Decision

1. **A second nav row, "Services"** — Backups, Realtime, Queues. Backups
   **moves out** of the compute row into it. Compute keeps Sites, Servers,
   Projects, Fleet, Edge, Cloud, Serverless.

   Both rows render on **every** workspace index page, stacked, the Services
   row styled subordinate (smaller, no independent bottom border, muted until
   active). Swapping rows per-section was rejected: if the Services row only
   appears on Services pages, Realtime is unreachable from `/sites` and the
   promotion reproduces the discoverability failure it exists to fix.

   `compute-index-nav` already guards each item on `Route::has()` plus an
   optional `feature` key, so Queues can sit in the array before its pages
   exist and appear when `surface.queue` flips.

2. **"Services" is nav, not a type.** No `ManagedService` contract, no shared
   base model. The group is coherent as *"what your apps lean on"* but it is
   **not** a billing category — Backups does not bill at all (zero references
   in `app/Modules/Billing`), is Pennant-gated on `workspace.backups`, and is
   site/server-scoped rather than an org-owned provisioned resource.

   Extracting a contract at N=2 would also have to unify two unrelated revenue
   shapes. Rejected until a fourth service argues otherwise.

3. **Realtime's billing does not change.** Flat per-app per-tier stays. The
   `peak_connections` high-water mark that `CollectRealtimeUsageCommand`
   collects hourly stays observational.

   Moving to connection-metered billing was considered and declined: the tier
   cap is *enforced in the data plane*, so converting it to overage changes the
   product's failure mode from "connections rejected" to "surprise invoice" —
   a pricing change needing customer comms, not a refactor. It would also put
   `StripeSubscriptionSyncer` in the blast radius of a nav change. All Realtime
   work here is additive surface: shell, URL, docs, pricing page.

4. **Queue is free when it serves Serverless and paid otherwise.** Serverless
   is the product Queue exists to unblock — the ADR's whole thesis is that
   "every serverless Laravel app needs a paid Redis before its queue works at
   all". Charging for that namespace re-erects the barrier in smaller form.

   Cloud, BYO, and Edge customers have working alternatives (Redis on their own
   VM, managed Redis), so for them Queue is a convenience purchase and bills
   from namespace #1.

5. **Billability is derived live, not stamped.** A namespace is billable unless
   its `site_id` points at a Site with `serverless_backend = 'dply_serverless'`.
   `site_id IS NULL` — an external Laravel app holding a namespace with no dply
   site — is **billable**; that customer is not a Serverless customer.

   `QueueNamespace.site_id` already exists and is already populated by
   `ServerlessQueueProvisioner`, so this needs no migration. The predicate sits
   beside Lookout's exclusion in `OrganizationBillingStateComputer`.

   The `source`-stamp pattern (`LookoutProject::SOURCE_BUNDLE`) was rejected
   here: a stamp records how a row was *created*, but the pricing rule is about
   what the queue *currently serves*. When a site converts Serverless → Cloud
   the queue **should** start billing, so the stamp's staleness is not a bug to
   patch but the wrong model. The bundle's stamp-plus-reconcile exists because
   entitlement state spans dply, tracely, and Lookout behind a droppable
   webhook; both sides of this join are rows in one Postgres.

6. **A billable namespace is priced Realtime-shaped: tiered per namespace on
   capacity.** `queue_service.tiers` maps queue depth + requests-per-minute to
   a monthly (and yearly) price, mirroring `config('realtime.tiers')`. Stripe
   prices generate from a `standard_queue_tier_` role prefix.

   Metered per-job billing was rejected for v1. It requires ADR decision 9 in
   full — Redis `INCRBY` at push, hourly flush, exactly-once-ish accounting
   because the number lands on an invoice — and `requests_per_minute` already
   caps COGS structurally (600 rpm is a ~26M request/month ceiling per
   namespace). A price that is "$0 until it isn't" is also not one a customer
   can plan around.

   Consequences: capacity moves from plan-scoped to **tier**-scoped, so
   `queue_service.entitlements.plans` shrinks to `available` and
   `max_namespaces`. `monthly_included_jobs`, `overage_per_million_jobs_cents`,
   `hard_cap_jobs`, and `billing.per_million_jobs_cents` become dead and are
   **deleted**, not left at zero — dormant pricing dials are how someone later
   ships a surprise charge.

   With per-resource pricing the price is the limiter, which is why
   `config/realtime.php` carries no per-plan app cap and needs none.

7. **A namespace changing billability notifies the customer.** Decision 5 makes
   the flip silent by construction; a site conversion moving a queue from $0 to
   its tier price is precisely the surprise-invoice failure decision 3 refuses
   to accept for Realtime.

   The transition is detected by diffing against the previous
   `OrganizationBillingSnapshotWriter` snapshot — no new state. A grace period
   was rejected: it needs a `billable_from` column and an expiry sweep for an
   event that fires when someone migrates a site.

   This makes the billing snapshot **load-bearing** rather than diagnostic. It
   is also the audit trail that live attribution otherwise lacks: the snapshot
   records why a line was priced as it was in a given cycle.

8. **Realtime moves to session-scoped URLs**: `/realtime` and
   `/realtime/{app}`. Queue is born at `/queues`.

   Every peer in both nav rows is session-scoped. Realtime's org-in-URL is not
   incidental — the URL shape and the old IA agreed with each other, and
   changing one without the other leaves a product page whose breadcrumbs say
   "Organization". Keeping org-scoping would also mean minting a *new*
   org-scoped URL for Queue to match an outlier already judged misfiled.

   The cost is real: session-scoped URLs cannot deep-link across orgs, so a
   member of several orgs loses cross-org bookmarks. dply already accepted that
   for its entire compute surface.

   The old routes must **not** use `Route::redirect` — that would send an
   org-B bookmark to org A's page and silently render the wrong org's data.
   They hit a controller that authorizes membership, sets
   `session('current_organization_id')` from the URL param, then redirects.

   Both Realtime pages swap `x-organization-shell` for the index-page pattern
   (nav → `x-breadcrumb-trail` → `x-profile-shell`) that Backups and Edge use.

9. **The Queue surface is a Horizon replacement, not a status page.** Index
   (list, create, tier change, delete), show (credentials, endpoint, wiring
   snippet, live depth), a jobs/day throughput sparkline, and a failed-jobs
   browser with retry over the existing `QueueFailedJobController`.

   This is required by decision 4, not decoration. Horizon is hard-wired to
   `RedisQueue`, so a Cloud or BYO customer adopting dply Queue **loses their
   dashboard** — and they are exactly who we bill. Depth-only would be a
   downgrade they notice in a week.

   Throughput counters return **observational**, not billing-grade: they feed
   a sparkline, may drop a few percent under failure, and nothing is invoiced
   from them. `dply_queue_usage_daily` is already specified in the Queue ADR's
   consequences, so this introduces no new storage decision.

   Failed jobs land in the *app's* DB by default (Queue ADR boundaries), so the
   page has data only where dply controls the env. Serverless-wired apps
   default to dply's failed-job provider; everywhere else the page needs an
   empty state that explains why it is empty rather than implying zero
   failures.

10. **Queue launches free, then prices.** All namespaces are free during beta;
    billing flips at GA with notice.

    The Queue ADR's own admission — "honest ceiling: low thousands of jobs/sec"
    — is the reason. `dply_queue_jobs` is a churn table with hand-tuned
    autovacuum settings that have never met production traffic, and under
    decision 4 the first person to find the ceiling would otherwise be a paying
    customer who gave up working Horizon to be there.

    Grandfathering the beta cohort permanently was rejected because it would
    make price depend on creation date, forcing back the `source` column
    decision 5 declined and leaving billability derived from two contradictory
    sources.

    Turning billing on later is a price increase and some fraction of the beta
    cohort will churn. The notification path from decision 7 is reused for it.

11. **Flag order.** `queue_service.billing.enabled` is the master safety and
    stays false until the decision-5 predicate, the tier prices, and the
    decision-7 notification are all in place. Then `surface.queue` opens
    per-org. Billing flips only after the data plane has held.

    This ordering is not optional. `ServerlessQueueProvisioner` **already
    runs**, gated only on `queue_service.enabled` + `surface.queue` — the
    moment the surface opens, namespaces auto-create. Billing on before the
    predicate charges every Serverless customer for what they were told was
    included. This is the landmine `docs/adr/bundled-products-sso.md` records
    for Lookout, in a product where the free path provisions itself.

12. **Add-on pricing lives on the existing pricing page.** Plan ladder stays
    the hero; a "Managed services" block below carries Realtime and Queue
    tiers. The bill reads `plan + add-ons` on one invoice, so one page should
    teach that rather than fragment the highest-intent page in the funnel
    across per-product URLs.

    The Serverless carve-out copy is the part that matters: "$9/namespace" next
    to "included with Serverless" is where a prospect either understands
    decision 4 instantly or concludes the pricing is confusing.

    Both products need a customer docs page via the `config/docs.php` manifest.
    Realtime has none today. Queue's must exist before `surface.queue` opens —
    an external app has to add `'endpoint' => env('AWS_ENDPOINT')` by hand and
    that instruction needs a home.

## Boundaries

| Concern | Owner |
|---|---|
| Which nav row a product sits in | `compute-index-nav` / new services nav |
| Whether a namespace is billable | `OrganizationBillingStateComputer` (live predicate) |
| What a billable namespace costs | `config/queue_service.tiers` |
| Realtime's price | `config/realtime.tiers` — unchanged |
| Backups' price | none; Backups does not bill |
| Job transport, leases, fencing | `docs/adr/dply-queue.md` — unchanged |

## Amendments to `docs/adr/dply-queue.md`

- **Decision 2** ("Not scoped to a Site; any Laravel app can hold one") is
  superseded in part. `QueueNamespace.site_id` exists, is populated by
  `ServerlessQueueProvisioner`, and is now **load-bearing for pricing** per
  decision 5 here. A namespace may still be site-less; that case is billable.
- **Decision 9** ("Metering = jobs pushed... counted with Redis `INCRBY` at
  push and flushed hourly") is demoted. Counters remain, feed the UI, and are
  observational. Nothing is invoiced from them under decision 6.
- **Decision 10** (gating) is extended: `queue_service.tiers` is added and the
  metered entitlement keys are deleted.

## Consequences

**Realtime** — shell swap on both pages; route move plus the session-setting
redirect controller; remove the link from `organization-shell.blade.php`;
update the deep link in `sites/settings/partials/resource-map.blade.php`;
Livewire aliases in `RealtimeServiceProvider` repointed (guarded by
`tests/Feature/LivewireAliasGuardTest.php`); first customer docs page; pricing
page entry. No billing code changes.

**Queue** — `queue_service.tiers` with monthly and yearly amounts;
`standard_queue_tier_` roles in `StripeBillingProvisioner`;
`QueueNamespaceBillingObserver` mirroring Realtime's; the billable predicate and
tier quantities threaded through `OrganizationBillingStateComputer` and
`DesiredBillingState`; index and show pages; failed-jobs page; observational
counters and `dply_queue_usage_daily`; docs page; nav entry; dead entitlement
keys deleted.

**Shared** — a services nav component plus a wrapper rendering both rows,
applied across the nine index blades that currently include
`compute-index-nav`; Backups' nav entry moves rows.

`DesiredBillingState` gains another product triplet, taking its constructor
past 24 parameters. That shape is bad but stable, and collapsing it into a
product registry would put invoice generation in the blast radius of this work.
Deliberately deferred to its own change.

## Open

- **`STATUS_PAUSED` is dead code** on both `QueueNamespace:47` and
  `RealtimeApp:56` — declared, documented as "suspended by an operator or by a
  plan downgrade", referenced nowhere. Neither service has suspension or
  downgrade enforcement. Out of scope here; per-resource pricing means the
  price is the limiter, but non-payment enforcement remains unbuilt.
- **Queue's free-plan behaviour** (`entitlements.plans.free.available`) is not
  settled.
- Redis store / Horizon RESP compatibility — unchanged from the Queue ADR.
