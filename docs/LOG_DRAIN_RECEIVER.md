# dply Realtime — log drain receiver

The **dply Realtime** logging channel makes a deployed site stream its
application logs to dply. The site's generated `config/logging.php` configures a
Monolog `SyslogUdpHandler` pointing at `DPLY_LOG_DRAIN_HOST:DPLY_LOG_DRAIN_PORT`,
stamped with the site's `DPLY_LOG_DRAIN_TOKEN` as the syslog *ident*.

dply receives those datagrams with a long-lived listener and stores them in the
`app_logs` table, which the site's **Logs → App logs** panel reads.

> **This receiver must be deployed and supervised.** It is a UDP listener, not an
> HTTP route — nothing in the request/queue path starts it. Until it runs, the
> dply Realtime channel still *sends* (and the per-channel "Test" reports "sent,
> not yet seen in App logs"), but no records are stored.

## What runs

```
php artisan dply:log-drain:listen --host=0.0.0.0 --port=$DPLY_LOG_DRAIN_PORT
```

- Binds a UDP socket on the drain port (the same port sites send to).
- For each datagram: extracts the `dly_*` routing token, maps it to a site via
  `sites.log_drain_token` (cached in-process), derives the level from the syslog
  `<PRI>`, and writes one `app_logs` row.
- Tolerant by design — a malformed datagram is dropped, never fatal.

## Where it runs

On the box that owns `DPLY_LOG_DRAIN_HOST` (the dply control plane, or a
dedicated drain host). It must be reachable on the drain UDP port from customer
site servers, so open that port in the host/cloud firewall (see the firewall
architecture notes).

## Supervisor unit (example)

```ini
[program:dply-log-drain]
command=php /path/to/dply/artisan dply:log-drain:listen --port=%(ENV_DPLY_LOG_DRAIN_PORT)s
autostart=true
autorestart=true
stopwaitsecs=10
user=dply
redirect_stderr=true
stdout_logfile=/var/log/dply/log-drain.log
```

## Config

| env | meaning |
| --- | --- |
| `DPLY_LOG_DRAIN_HOST` | host sites send to (and the receiver advertises) |
| `DPLY_LOG_DRAIN_PORT` | UDP port the receiver binds and sites send to |

These are the same values injected into each site's `.env` for the dply Realtime
channel (`config/log_drains.php`).

## Diagnostics

- `php artisan dply:log-drain:listen --once` processes a single datagram then
  exits — useful to confirm binding/permissions.
- The per-channel **Test** button on the Logs tab emits a tokened record and
  polls `app_logs` for it: a green "Confirmed" means the full round-trip works.

## Not in scope (later product phase)

Live tailing, retention/rotation policy, and full-text search over `app_logs`.
Phase 5 ships ingestion + storage + a paged/filtered reader only.
