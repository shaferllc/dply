# dply Logs — app-log drain receiver

The **dply Logs** logging channel (channel type `dply_realtime`) makes a deployed
site stream its application logs to dply. The site's generated `config/logging.php`
configures a Monolog `SyslogUdpHandler` pointing at
`DPLY_LOG_DRAIN_HOST:DPLY_LOG_DRAIN_PORT`, stamped with the site's
`DPLY_LOG_DRAIN_TOKEN` as the syslog *ident*.

dply receives those datagrams with a long-lived listener and stores them in the
`app_logs` table, which the site's **Logs → App logs** panel reads.

> **This receiver must be deployed and supervised.** It is a UDP listener, not an
> HTTP route — nothing in the request/queue path starts it. Until it runs, the
> dply Logs channel still *sends* (and the per-channel "Test" reports "sent, not
> yet seen in App logs"), but no records are stored.

## What runs

```
php artisan dply:log-drain:listen --host=0.0.0.0 [--port=$DPLY_LOG_DRAIN_PORT]
```

- Binds a UDP socket on the drain port (the same port sites send to). `--port`
  defaults to `config('log_drains.dply_realtime.port')` when omitted.
- For each datagram: extracts the `dly_*` routing token, maps it to a site via
  `sites.log_drain_token` (cached in-process), derives the level from the syslog
  `<PRI>`, applies the per-site rate limit, and writes one `app_logs` row.
- Tolerant by design — a malformed datagram is dropped, never fatal.

## Where it runs

On the box that owns `DPLY_LOG_DRAIN_HOST` (the dply control plane, or a
dedicated drain host). It must be reachable on the drain UDP port from customer
site servers, so open that port in the host/cloud firewall (see the firewall
architecture notes).

## Supervisor unit

A ready unit is committed at **`deploy/supervisor/dply-log-drain.conf`** — install
it on the drain host only:

```
sudo cp deploy/supervisor/dply-log-drain.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
```

It runs the listener under a bash guard that backs off ~30s (rather than
crash-looping) when `DPLY_LOG_DRAIN_PORT` isn't configured yet.

## Config

| env | meaning | default |
| --- | --- | --- |
| `DPLY_LOG_DRAIN_HOST` | host sites send to (and the receiver advertises) | — |
| `DPLY_LOG_DRAIN_PORT` | UDP port the receiver binds and sites send to | — |
| `DPLY_LOG_DRAIN_RETENTION_DAYS` | days of `app_logs` kept (pruned daily) | 30 |
| `DPLY_LOG_DRAIN_RATE_MAX` | max records/window per site (0 = unlimited) | 600 |
| `DPLY_LOG_DRAIN_RATE_WINDOW` | rate-limit window, seconds | 60 |

`HOST`/`PORT` are the same values injected into each site's `.env` for the dply
Logs channel (`config/log_drains.php`). Run `php artisan config:cache` after edits.

## Retention

`app_logs` lives in the main DB, so `php artisan app-logs:prune` runs daily
(`DplySchedule`, 04:05) and deletes records older than `retention_days`.

## Security posture (read before enabling in prod)

- **Transport is plaintext UDP syslog.** There is no TLS — log contents and the
  routing token travel in the clear. Keep the drain host on a trusted/VPC path
  where possible and **firewall the UDP port to known site-server IPs**.
- **Routing is by per-site token only** (`dly_…`, stamped as the syslog ident).
  Anyone who can see/guess a token can inject log lines for that site, so treat
  the network path as the real control and rotate a token (re-save the binding)
  if it leaks.
- **UDP is lossy** — best-effort delivery, no backpressure. Don't rely on it for
  audit-grade records; it's for operational visibility.
- **Per-site rate limit** caps ingest so one chatty/abusive app can't flood
  `app_logs` (drops excess for the rest of the window).

## Diagnostics

- `php artisan dply:log-drain:listen --once` processes a single datagram then
  exits — useful to confirm binding/permissions.
- The per-channel **Test** button on the Logs tab emits a tokened record and
  polls `app_logs` for it: a green "Confirmed" means the full round-trip works.

## Not in scope (later product phase)

Live tailing and full-text search over `app_logs`. This phase ships ingestion +
storage + retention + a paged/filtered reader only.
