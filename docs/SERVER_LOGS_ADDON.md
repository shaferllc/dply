# Server Logs Add-on ("dply Logs")

A per-server, host-level log-shipping add-on — dply's own Papertrail. An opt-in
agent on each managed box ships **all server logs** (journald + web + php-fpm +
per-site app logs + auth.log) back to dply, where they're stored in ClickHouse
and browsed in a native dply log explorer.

This is a **server resource**, not a site binding. It is distinct from the
existing per-site `logging` binding (`LogDrainCredential` / `app_logs` /
`dply:log-drain:listen`), which drains a single site's application log channel
out to third parties or into dply Realtime. That feature stays as-is. See
`docs/LOG_DRAIN_RECEIVER.md`.

Status: **Phase 1 (free MVP) built + verified end-to-end** (edge agent, ClickHouse
store on `dply-logs-1`, aggregator data plane, mTLS cert distribution, Logs workspace
UI + ClickHouse-backed explorer). Remaining to go live: set prod `.env` (ClickHouse +
mTLS material), `migrate`, `config:cache`, `dply:logs:schema-sync`. Phase 2 = billing.

---

## Why a separate, paid product

Host-level log volume is a firehose (journald + nginx + php + auth across many
boxes is easily millions of lines/day). It does not fit the site-scoped,
relational `app_logs` model, and the data volume is the reason it's a paid
add-on. The hot path must stay out of Laravel/PHP-FPM entirely.

---

## Architecture

```
each managed box (opt-in per server)
  systemd: dply-logship.service  →  Vector (static binary, dply-rendered vector.toml)
     sources: journald · web access+error (nginx/Caddy) · php-fpm
              · per-site app logs · auth.log        (per-source toggles in UI)
     edge VRL redaction: bearer/JWT, AWS keys, password=, conn-strings, (opt) IPs
     systemd CPUQuota=15% / MemoryMax=128M
     disk buffer ≤ 512MB, when_full = drop_oldest   (app health > log completeness)
        │ mTLS (per-server client cert, issued at provision/enable)
        ▼
  Caddy  →  verify client cert → map fingerprint/CN → server_id → org_id
            inject trusted headers X-Server-Id / X-Org-Id
        ▼
  Vector aggregator(s)  →  authenticate, STAMP tenant server-side (never trust payload),
                           batch, meter bytes per org/server
        │ bulk INSERT
        ▼
  Managed ClickHouse (ClickHouse Cloud / Altinity / Aiven)
     1 shared MergeTree
       PARTITION BY toDate(timestamp)
       ORDER BY (org_id, server_id, timestamp)
       materialized columns: level, unit, site_id
       TTL = retention window (per quota)
        ▲                                      │ aggregator fans recent lines
        │                                      ▼
  Laravel control plane                  Reverb / CF realtime relay (live tail)
  (config + billing + reads only —
   NEVER in the ingest hot path)
        │
        ▼
  Native dply log explorer (Livewire)
     filter: server / site / unit / level / full-text · time range
     historical → ClickHouse (org-scoped, WHERE org_id = ? always)
     live tail  → Reverb private-server.{id}
```

### Key decisions (and why)

| Decision | Choice | Rationale |
|---|---|---|
| Layer | Per-server host agent | Host logs (auth.log, kernel) have no owning site; mirrors metrics guest-agent, not the site `logging` binding |
| Shipper | **Vector** (dply-configured) | Single static binary; owns rotation, journald cursors, disk buffer, backpressure, retry. We own config + ingest, not transport internals |
| Backend | **ClickHouse** (self-hosted, dedicated box) | Columnar, heavy compression, full-text + SQL analytics. MVP runs on a dedicated DO droplet (`dply-logs-1`), isolated from the prod DB; revisit managed if ops cost grows. See "Infrastructure" below |
| Ingest path | dply **Vector aggregator** in front of ClickHouse | Vector-to-vector is first-class; keeps Laravel out of the firehose; no custom gateway code |
| Auth | **mTLS per-server client cert**, Caddy terminates | Cryptographic identity, no per-batch DB lookup, stateless at scale. Caddy already core to the stack |
| Sources | Curated defaults + per-source toggles | "Just works" + cost control; custom paths deferred (unbounded-volume footgun) |
| Agent safety | Hard-capped (CPUQuota/MemoryMax) + capped disk buffer w/ drop_oldest | Runs on the customer's box — app health must always beat log completeness |
| Search UI | Native dply Livewire explorer | On-brand "seamless surface"; reuses Reverb relay for live tail |
| PII | Edge redaction + encryption at rest + org-scoped reads + per-server purge | Strongest scrub is before data leaves the box; honest disclosure, no compliance over-claims |
| Tenant identity | Stamped server-side from the authenticated cert | A compromised box must never be able to spoof another tenant's logs |

---

## Infrastructure (live)

**Log store — `dply-logs-1`** (provisioned 2026-06-07):
- DigitalOcean droplet, region `sfo2`, size `s-2vcpu-4gb` ($24/mo), Ubuntu 24.04, in
  the **same VPC as the `dply` control plane** (so reads go over private networking).
- Deliberately a **dedicated box, NOT the prod DB host** (`dply-database-1` @ Hetzner) —
  a log-volume/disk spike must never be able to take down the production database.
- ClickHouse (LTS channel, currently 26.3) hardened:
  - Binds to `127.0.0.1` + the VPC private IP **only** — never the public interface.
  - `default` user password-protected (SHA256), `networks` restricted to `127.0.0.1` + `10.0.0.0/8`.
  - On-box `ufw`: 8123/9000 reachable only from `10.0.0.0/8` (the VPC); SSH open.
  - Memory capped via `max_server_memory_usage_to_ram_ratio = 0.6`.
- Connection (private): `CLICKHOUSE_HOST=10.138.230.146`, `CLICKHOUSE_HTTP_PORT=8123`,
  `CLICKHOUSE_DATABASE=dply_logs`, `CLICKHOUSE_USERNAME=default`. The password lives at
  `/root/.dply-clickhouse-password` on the box → copy into the prod secret store.
- Schema applied via `dply:logs:schema-sync` (idempotent; safe to run on deploy).

**Aggregator — co-located on `dply-logs-1`** (verified end-to-end 2026-06-07):
- Vector 0.48.0, systemd `dply-aggregator.service`, listens `0.0.0.0:6000` (ufw-opened
  publicly; edges connect from their public IPs, gated by mTLS).
- `vector` source with mTLS (`verify_certificate`) → remap (stamps tenant from the
  edge-sent `.dply_org_id`/`.dply_server_id`) → `clickhouse` sink (localhost:8123).
- PKI at `/etc/dply-aggregator/tls/` (`ca`/`server`/`client`). Proven: simulated edge
  with the client cert → aggregator → ClickHouse (503 rows).
- **To flow a real managed server (remaining wiring):** set
  `SERVER_LOGS_AGGREGATOR_ENDPOINT=134.209.13.103:6000` in prod `.env`, AND deploy
  `ca.crt` + `client.crt` + `client.key` to each edge at `/etc/dply-logship/` during
  `InstallLogAgentJob` (mTLS cert distribution — the edge sink already references those
  paths). Until then edges ship to a blackhole sink.
- Hard-won gotchas: Vector does `$VAR` interpolation on configs, so VRL capture refs
  render as `$$1`/`$$2`; the edge sink needs `ca_file` to trust the private CA.

**Local dev** — `docker compose -f docker-compose.clickhouse.yml up -d`. Note port 8123 may
collide with another local ClickHouse; this repo's machine runs the dev instance on **8124**
(`CLICKHOUSE_HTTP_PORT=8124` in `.env`).

---

## Billing (Phase 2)

Shape: **per-server flat fee** including a monthly GB quota + N-day retention,
with **metered per-GB overage**. Reuses the existing flat + metered-overage
Stripe plumbing (Serverless/Cloud pattern), and fits dply's per-server pricing
DNA.

Quota guard:
- 80% / 100% of quota → soft alerts (email + in-app).
- 100% → metered overage billing begins.
- Customer-settable **hard cap** (default ~2× quota) → aggregator sheds new
  events for that server and shows a visible "ingest capped, N dropped"
  breadcrumb in the UI. Protects the customer's wallet and dply's support queue.

Metering: the aggregator tallies bytes per org/server and reports to Laravel,
which drives the Stripe metered line item. **Metering runs from Phase 1** (data
recorded) even though billing is off, so numbers exist when billing flips on.

---

## Lifecycle

Enabling the add-on per-server flips a toggle that dispatches a **queued SSH
sync/install job** (the "supervisor sync/install with preflight path checks"
pattern) which installs: the pinned Vector binary, the per-server mTLS cert, the
rendered `vector.toml`, and the systemd unit. A pinned-version bump re-runs the
sync job. Disabling the add-on uninstalls cleanly. PKI (issue/rotate/revoke
per-server certs) is a new sub-system to spec, but fits the existing per-server
secret provisioning.

---

## Phasing

### Phase 1 — MVP (free private beta) ← current target
- Edge Vector agent: curated sources + toggles, hard-capped, edge redaction.
- mTLS + Caddy tenant mapping.
- **One** aggregator (edge 512MB buffers ride short outages).
- Managed ClickHouse.
- Native log explorer: filter + live tail.
- Per-server enable/disable via queued sync job.
- **Meter bytes, but do not charge.**
- Conservative aggregator-side global drop threshold to protect dply's own
  ClickHouse/egress costs from a runaway box (even while free).

### Phase 2 — billing
- Flat per-server + quota + metered overage (Stripe).
- Soft alerts (80/100%) + customer hard cap.
- 2× aggregator HA.
- **Custom log paths / units** (fast-follow).

---

## Open quantities (need real data, not architecture)

These are calibration values, intentionally unset until Phase 1 dogfooding
produces real bytes/day and real managed-ClickHouse cost:

- quota GB per server · retention days included
- $/server · $/GB overage · hard-cap default multiple
- pinned Vector version
- confirm 512MB disk buffer / 15% CPUQuota / 128MB MemoryMax defaults

**Recommendation carried forward:** even though Phase 1 ships free, dogfood the
pipeline on dply's own prod servers for ~2 weeks to calibrate the Phase 2
pricing numbers against real cost before charging.
