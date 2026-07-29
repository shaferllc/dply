---
title: dply Logs — Paired-Product Features
status: planned
audience: internal
updated: 2026-06-18
---

# dply Logs — Paired-Product Features

How to take the **server log shipping** add-on ([[SERVER_LOGS_ADDON]]) from a log bucket
to a product that *does things* with the rest of dply. The thesis: "paired" means each
feature reuses primitives dply already ships — Notifications delivery, the Errors stream +
`X-Dply-Ref`, the Deploy timeline, the Billing/entitlement stack, the Insights registry —
so logs stop being passive storage and start feeding the workspace.

The pipeline is live (edge Vector → mTLS → aggregator → ClickHouse → explorer). A
**recon pass over the codebase (2026-06-18) confirmed most connective tissue already
exists**: a `ServerLogCorrelator` that windows logs around an error/deploy/incident, an
entitlement + usage stack for quota, and a plug-in `InsightRunnerInterface` registry.
Most features below are **wire + UI**, not build-from-scratch. The two genuine new builds
are the `LogAlertRule` loop (#1) and Vector field-parsing + schema change (#4).

---

## Shared primitive — windowed explorer (build once, unblocks #2/#3/#6)

The explorer (`app/Livewire/Servers/Concerns/ManagesServerLogExplorer.php`) is server-scoped
and only does "recent N minutes." `LogExplorerQuery::window($server, $from, $to, $filters)`
already exists — it's just not reachable from the UI.

**Build:** a deep-linkable windowed mode — `?from=…&to=…&q=…&lvl=…&src=…` that drives
`window()` instead of `recent()`. Every "jump to logs" affordance below targets this.

- Add `#[Url]` `$logExplorerFrom` / `$logExplorerTo` to the explorer trait; when present,
  call `window()`; else `recent()`.
- A "viewing window X–Y · back to live" banner so the windowed state is escapable.

---

## Tier 1 — turns it from a log bucket into a product

### 1. Log-driven alerts → Notifications channels

A saved log search becomes an alert rule that fires through the **existing** channels.

**Reuses**
- `NotificationPublisher::publish(eventKey, subject, title, body, url, metadata, actor)`
  (`app/Modules/Notifications/Services/NotificationPublisher.php`) → `NotificationRoutingResolver`
  → channels via `NotificationSubscription`. Delivery is 100% reused.
- Pattern to copy: `SiteUptimeMonitor` + `app/Jobs/RunSiteUptimeMonitorCheckJob.php` — a
  state-machine check that fires events on transition.
- `LogExplorerQuery` runs the match-count query.

**Build**
- `LogAlertRule` model/table (mirror `SiteUptimeMonitor`): `server_id` (nullable = org-wide),
  `organization_id`, `label`, query (`q`/`level`/`source`), `condition` (count threshold +
  window minutes), `enabled`, `cooldown_seconds`, `last_state`, `last_fired_at`.
- `EvaluateLogAlertRulesJob` (scheduled): per rule run a `count()` over ClickHouse for the
  window, compare to `last_state`, fire on transition honoring cooldown/dedup.
- Event key `server.logs.alert_fired` in `config/notification_events.php` (registry assigns
  warning severity automatically).
- UI: "Create alert from this search" on the explorer + a rules CRUD panel.

**Pairs into:** Notifications. **Lift:** Medium — only the rule model + eval job are new;
the existing BulkNotificationAssignments UI routes the event the moment it's published.

### 2. Error-ref ↔ log correlation

From a 500's `X-Dply-Ref` straight to the exact log lines.

**Reuses — nearly done already**
- `ServerLogCorrelator::forErrorEvent(ErrorEvent)`
  (`app/Modules/Logs/Services/ServerLogCorrelator.php`) returns the log window for an error
  event (delegates to `LogExplorerQuery::window()`).
- `error_events` rows already carry `reference`, `server_id`, `site_id`, `occurred_at`,
  `detail` (method + URI), `link_url`.

**Build**
- "View logs around this error" action on `app/Livewire/Sites/Errors.php` and on the
  reference-lookup result banner → correlator → deep-link into the windowed explorer centered
  on `occurred_at ± Ns`, pre-filtered to the request when the `reference` made it into a line.
- Optional: render matched lines inline in the existing reference-lookup banner alongside the
  SSH-tail trace.

**Pairs into:** Errors stream + `X-Dply-Ref`. **Lift:** Low — a button + the shared primitive.

### 3. Deploy correlation

Overlay deploy events on a log-volume / error-rate graph so a post-deploy spike lines up
with the release that caused it.

**Reuses**
- `ServerLogCorrelator::inWindow(from, to)` for the deploy window.
- `SiteDeployment.started_at` / `finished_at`; cutover = `activate` phase's `swap` step.
- Server→deploys: `Site::where('server_id', …)->pluck('id')` →
  `SiteDeployment::whereIn('site_id', …)->orderByDesc('started_at')`.
- `resources/views/components/metrics-line-chart.blade.php` — SVG threshold-line + crosshair.

**Build**
- A log-volume / error-rate series on the Logs Overview tab: ClickHouse
  `GROUP BY toStartOfMinute(timestamp)` (+ split on `level` for error-rate). New small method
  on `LogExplorerQuery`.
- Extend the chart with **vertical deploy markers** (threshold-line pattern, colored by deploy
  status) at each recent cutover; hover → site/status/duration; click → windowed explorer.

**Pairs into:** Deploy engine. **Lift:** Medium — new aggregation query + chart-overlay
extension (no marker component exists yet).

---

## Tier 2 — completeness / stickiness

### 4. Structured fields + facets *(the real infra lift)*

Parse the sources already collected; facet instead of grep.

**Reuses:** ClickHouse `source`/`level` columns + the Vector remap step
(`app/Support/Servers/VectorLogAggregatorInstallScripts.php`) that maps edge fields → columns.

**Build**
- Aggregator parsing (Vector VRL): nginx/Caddy → `status`, `path`, `method`, `latency_ms`;
  PHP-FPM slow log → `script`, `duration_ms`; auth.log → `user`, `source_ip`.
- Schema migration via `SyncLogStoreSchemaCommand`: add nullable columns (or `fields
  Map(String,String)`) to `dply_logs.server_logs`. **Requires live re-validation on
  `dply-logs-1`** (aggregator there is rebuild-pending — see [[SERVER_LOGS_ADDON]]).
- Faceted filter UI + new `LogExplorerQuery` facet methods.

**Pairs into:** the Logs store (deepens the core). **Lift:** High — only feature touching
on-box Vector config + ClickHouse schema + live aggregator revalidation. Sequence after the
aggregator rebuild.

### 5. Retention & quota UX

Surface usage, retention, oldest-log, per-source volume, projected cost.

**Reuses**
- `ManageServerLogShipping::status()['usage']` already returns `month_bytes`,
  `included_bytes`, `over_included`, `retention_days` + the entitlement object.
- `ServerLogUsageCostCalculator::estimate()` for projected cost.

**Build**
- Two small ClickHouse queries: oldest-retained `SELECT min(timestamp)`; per-source volume
  `… GROUP BY source` (the meter currently groups by org only).
- UI: usage meter (GB used vs included/cap), oldest-retained date, per-source volume bars on
  the Sources collapsible (quantifies "fewer sources = less volume"), projected-cost line.

**Caveat:** billing is **code-complete but inert** (`SERVER_LOGS_BILLING_ENABLED=false`, all
`overage_per_gb_cents=0`, no Stripe price — see [[SERVER_LOGS_BILLING]]). Render usage +
retention always; show cost only when rates are live.

**Pairs into:** Billing metering. **Lift:** Low–Medium.

### 6. Fleet search

Org-wide search across all servers with saved views.

**Reuses:** ClickHouse is already org+server scoped, and `org_id` is the leading sort key, so
dropping the `server_id` filter is index-efficient. `LogExplorerQuery` patterns.

**Build**
- Org-scoped query variant (filter `org_id` only, optional `server_id IN (…)`), rows carry
  `server_id`/`host`.
- An org-level Logs page (the "Related" tab is the natural home) with a server column.
- Saved views: a small `log_saved_view` table (or reuse the `LogAlertRule` query shape).

**Pairs into:** the Logs store (org-wide surface). **Lift:** Medium — new query scope + page;
main new concept is org-level vs server-workspace placement.

### 7. Insights / Health integration

Promote a log anomaly into a finding on the overview cards.

**Reuses — cleanest plug-in of all**
- `InsightRunnerInterface::run(Server, ?Site, params): InsightCandidate[]`
  (`app/Modules/Insights/Services/Contracts/InsightRunnerInterface.php`) + one line in
  `config/insights.php`. `InsightRunCoordinator` → `InsightFindingRecorder::syncCandidates()`
  handles upsert/dedupe/auto-resolve. The overview Insights card auto-counts it.
  `notify_subscribers: true` fires it through feature 1's Notifications path.

**Build**
- `LogAnomalyInsightRunner`(s): auth.log brute-force (via #4's `source_ip`/`user`); error-rate
  regression (via #3's aggregation). Run a ClickHouse count, return `InsightCandidate` over
  threshold.
- One `config/insights.php` entry. Optionally a `ServerHealthCockpit` alert category for the
  Health card too.

**Pairs into:** Insights/Health. **Lift:** Low (richer once #4 exists).

---

## Build order (dependency-aware)

1. **Shared windowed-explorer primitive** — unblocks #2/#3/#6.
2. **#2 error-ref correlation** — lowest lift, correlator already exists; fast visible win.
3. **#5 quota/retention UX** — low lift, all-reuse; makes the free MVP feel complete.
4. **#1 log alerts** — highest-leverage "product" feature; delivery is free.
5. **#3 deploy correlation** — aggregation query + chart overlay.
6. **#4 structured fields** — gate behind the `dply-logs-1` aggregator rebuild; prerequisite
   that strengthens #6 facets and #7 detection.
7. **#6 fleet search** + **#7 insights** — land last; both better once #4 exists.

Genuinely-new infra: the **`LogAlertRule`** loop (#1) and **Vector parsing + schema** (#4).
Everything else is queries, UI, and the one shared deep-link primitive.

---

## First buildable slice — shared primitive + #2

1. Add `#[Url(as: 'from')]`/`#[Url(as: 'to')]` to `ManagesServerLogExplorer`; `loadLogExplorer()`
   branches to `LogExplorerQuery::window()` when both are set, else `recent()`. Add the
   "viewing window · back to live" banner to `_tab-shipping.blade.php`.
2. On `app/Livewire/Sites/Errors.php`, add `viewLogsForError($errorEventId)` → load the
   `ErrorEvent`, call `ServerLogCorrelator::forErrorEvent()` for the window bounds, redirect to
   the server Logs workspace (`shipping` tab) with `?from&to&q=<reference>`.
3. Add the "View logs" button to the error row + the reference-lookup result banner.
4. Tests: window-mode explorer query; the error→logs deep-link builds correct bounds; graceful
   when the store is unavailable (explorer already never throws).

No schema change, no new infra — proves the correlation surface end-to-end on top of services
that already exist.

## Source

Codebase recon 2026-06-18 (6 parallel passes: Logs store/explorer, Notifications dispatch,
Errors/`X-Dply-Ref`, Deploy timeline, Billing/quota, Insights/Health). Key services:
`ServerLogCorrelator`, `LogExplorerQuery`, `NotificationPublisher`, `ServerLogEntitlements`,
`ServerLogUsageCostCalculator`, `InsightRunCoordinator`. Related: [[SERVER_LOGS_ADDON]],
[[SERVER_LOGS_BILLING]].
