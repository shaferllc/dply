---
title: dply Logs — Billing & Paid Tiers Plan
status: planned
audience: internal
updated: 2026-06-17
---

# dply Logs — Billing & Paid Tiers Plan

How to turn the **server log shipping** add-on ([[SERVER_LOGS_ADDON]]) from a free MVP
into a billable product. The pipeline is live in prod (edge Vector → mTLS → aggregator →
ClickHouse → explorer), but it is **metering-blind** and **entitlement-flat**: one global
`server_logs.enabled`, a fixed 7-day TTL, no per-org volume tracking, no plan gates.

This plan is two layers: **the gate** (what lets us charge at all) and **the value ladder**
(what makes a tier worth paying for). Build the gate first — until it exists, every feature
below is just a free feature.

---

## Guiding decisions

- **Meter on ingest volume** (bytes accepted at the aggregator), not stored bytes — it's
  the cost driver (ClickHouse insert + retention) and the number customers can predict by
  toggling sources. Stored bytes follow from volume × retention.
- **Bill per org**, surfaced per server. Rows already carry `org_id`/`server_id`/`site_id`.
- **Reuse existing primitives** — Pennant entitlements, the usage-based Stripe path and
  soft/hard pause from [[project_pricing_model]], the `SiteBinding`/`LogDrainCredential`
  drain model, and notification channels for alerting. Mirror the **broadcasting binding**
  tier pattern (volume-banded prices) rather than inventing a new shape.
- **Fail open on trust, fail closed on cost.** Over-quota drops/samples at the aggregator
  (never fills the customer's disk, never silently double-bills); entitlement loss disables
  *new* shipping but never deletes stored logs before their paid retention expires.

---

## Phase 1 — The Gate (prerequisite to charging anything)

Ship these three together; none is useful alone.

### 1.1 Per-org ingest metering — **BUILT (PR A)**
- **Source of truth:** a daily rollup `SELECT org_id, count() events,
  sum(length(message)) bytes FROM dply_logs.server_logs WHERE ingested_at IN [day]
  GROUP BY org_id`, written to the Postgres table `server_log_usage_daily
  (organization_id, day, events, bytes, source, meta)`. Idempotent upsert keyed on
  `(organization_id, day, source)` so re-runs are safe.
  - *As built:* the column is `organization_id` (FK to `organizations`, cascade-delete)
    to match the `*_usage_snapshots` convention and the billing scanner; ClickHouse's
    `org_id` is mapped onto it. A `source` column (`clickhouse`/`manual`) leaves room for
    adjustment rows. Org_ids present in ClickHouse but absent from Postgres are skipped
    (logged) rather than violating the FK. Metered by `ingested_at` (the billable
    "accepted at the aggregator" moment), not the log's own `timestamp`.
- **Job:** `MeterServerLogUsageCommand` (`dply:logs:meter`), service
  `App\Services\Logs\ServerLogUsageMeter`. Scheduled hourly for the current day +
  a nightly `--yesterday` finalize at 02:05 (before the 02:10 billing snapshot).
  No cursor table needed — the day-keyed idempotent upsert is the cursor; `--date` +
  `--days=N` backfill any window. Skips quietly (`ping()`) where ClickHouse isn't wired.
- **Why ClickHouse-side, not Vector-side:** survives aggregator restarts, no double-count,
  and ClickHouse aggregates this in milliseconds. (Vector internal metrics can feed a live
  gauge later for the dashboard, but the billable number comes from the store.)
- **Edge case:** redaction/drops happen before insert, so we meter exactly what we stored —
  defensible to the customer.

### 1.2 Per-org entitlement + plan attributes — **BUILT (PR B1); store-side enforcement = PR B2**
- New plan attributes live in `config('server_logs.entitlements')`: a `defaults` block
  (the free MVP) overlaid with per-plan overrides keyed by subscription-plan key
  (`free`/`starter`/`pro`/`business`): `available`, `retention_days`,
  `monthly_included_gb`, `overage_per_gb_cents`, `max_servers`, `alerting_enabled`,
  `drains_enabled`. Resolved by `App\Services\Logs\ServerLogEntitlements::forOrganization()`
  → readonly `ServerLogEntitlement` value object (with `includedBytes()` / `isOverIncluded()`).
  Config-driven (like the realtime tiers), not a Pennant value — simpler, and the gate +
  billing + UI all read one place. Volume/retention numbers are uncalibrated placeholders;
  `overage_per_gb_cents` stays 0 until PR C.
- **Gate the enable action:** ✅ `ManageServerLogShipping::assertAddonAvailable(Server)` is
  now per-org — the env kill-switch (`server_logs.enabled`) first, then the org's
  entitlement `available`. `status()` now also returns the resolved `entitlement` + an
  org-wide month-to-date `usage` block (bytes vs included), reading PR A's
  `server_log_usage_daily`. Free defaults unchanged → no behaviour change for current users.
- **Retention becomes per-org:** the *value* resolves now (`entitlement->retentionDays`);
  **enforcement at the store (ClickHouse `retention_days` column + `MODIFY TTL` + stamping
  the per-org value at the aggregator) is PR B2** — see §3.1. Deferred because it mutates
  the live store DDL + aggregator install scripts and only bites once paid tiers differ.

### 1.3 Billing line item + quota enforcement
- **Meter → Stripe:** push `server_log_usage_daily` into a Stripe usage record (or the
  existing monthly-estimate path) as `included_gb` + `overage_gb × overage_per_gb`.
- **Quota enforcement:** when an org crosses its hard cap, the aggregator stops inserting
  that org's events (a `transforms.quota` route keyed on `dply_org_id` against a small
  generated allow/deny list, refreshed by the meter job) — drop, don't buffer, don't bill.
  Soft cap = notify + show in UI; hard cap = drop. Reuses soft/hard pause semantics.

**Exit criteria for Phase 1:** an org on a paid plan ships logs, we can see their GB/day,
it appears on their estimate, and an over-quota org is dropped (not charged) — all verifiable
from the existing billing UI.

---

## Phase 2 — The Value Ladder (what justifies the price)

Ordered by willingness-to-pay. Each is independently shippable once the gate exists.

| Feature | Hook into stack | Notes |
|---|---|---|
| **Retention tiers** (30/90/365d) | §3.1 per-row TTL | The #1 lever. |
| **Volume / server count** | metering + `max_servers` gate | Falls out of Phase 1. |
| **Log alerting** | scheduled rule queries → notification channels | "5xx rate > X", pattern match. Biggest upsell. |
| **Drains / export** | existing `logging` SiteBinding + `LogDrainCredential` | Forward to customer S3/Datadog/Papertrail; meter egress. |
| **Search power** | extend `LogExplorerQuery` | Saved searches, live tail, longer ranges, aggregations. |
| **Advanced redaction / compliance** | existing edge VRL redaction | Custom PII patterns, audit, residency — enterprise toggle. |

---

## Phase 3 — Trust features (table stakes once money is attached)

- **HA ingest + replicated store.** Today the aggregator is a single box (`dply-logs-1`) =
  SPOF. A paid "we keep your logs" promise needs ≥2 aggregators behind the edge sink list
  (Vector supports multiple addresses) and a ClickHouse replica. Sequence this before
  marketing durability.
- **No-loss guarantees.** Edge disk buffer is bounded `drop_newest` (app-health-first, by
  design). Paid tiers may warrant a larger guaranteed buffer + backpressure SLO.
- **Per-org access control + audit** on reads (already org-scoped; add audit rows).

---

## Architecture changes the gate forces

### 3.1 Per-org retention on a shared table
The store is one multi-tenant `MergeTree` with a **fixed** `TTL timestamp + INTERVAL 7 DAY`.
Per-org retention needs one of:
- **(preferred) Column-expression TTL:** add a `retention_days UInt16` column, stamped at
  the aggregator from the org's plan (a generated org→days map in the `normalize` transform,
  like the quota map), and change the table to
  `TTL toDateTime(timestamp) + INTERVAL retention_days DAY`. One table, per-row retention,
  no query changes.
- (fallback) Per-tier tables + a routing layer — more moving parts, avoid unless the
  column-TTL approach underperforms at scale.

Migration: `ALTER TABLE … ADD COLUMN retention_days` + `MODIFY TTL` (online in ClickHouse).
Backfill default 7 for existing rows.

### 3.2 Metering & quota maps at the aggregator
The `normalize`/new `quota` transforms need a small, frequently-refreshed lookup of
`org_id → {retention_days, allowed}`. Generate it from Postgres into a file the aggregator
reads (Vector `enrichment_tables`/file source), refreshed by the meter job. Keep it tiny
(only paying/over-quota orgs differ from defaults).

---

## Suggested packaging (mirrors the broadcasting binding)

- **Free** — 7-day retention, ~1 GB/day, all sources, basic explorer. The funnel; keep it.
- **Pro — banded by retention × volume** (e.g. 30d/10GB · 90d/50GB · 365d/250GB), alerting +
  drains included, overage per GB. Prices in the broadcasting-binding range as a starting point.
- **Enterprise** — dedicated/HA store, residency, custom redaction, audit, SLA.

---

## Build order (smallest shippable slices)

1. **PR A — metering:** ✅ **DONE.** `server_log_usage_daily` table + `dply:logs:meter`
   rollup (`ServerLogUsageMeter`) + hourly/nightly schedule + tests. Read-only; no
   customer impact. Lets us *see* volume before pricing anything.
2. **PR B — entitlement:**
   - **B1 ✅ DONE.** `config('server_logs.entitlements')` + `ServerLogEntitlements`
     resolver + `ServerLogEntitlement` value object + per-org `assertAddonAvailable(Server)`
     gate + entitlement/usage in `status()` + tests. Free defaults unchanged.
   - **B2 (next, infra):** ClickHouse `retention_days` column + `MODIFY TTL` (via
     `dply:logs:schema-sync`, online/idempotent, backfill 7) + stamp the org's
     `retention_days` at the aggregator from a generated org→days enrichment map
     (refreshed by the meter job). Touches the live store + install scripts.
3. **PR C — billing:** usage → estimate/Stripe line item + soft/hard quota drop at aggregator.
4. **PR D+ — value features:** alerting, then drains, then search, each on its own.
5. **Phase 3 — HA** before any durability marketing.

Milestone after PR A–C: **we can charge.** Everything after is margin and differentiation.
