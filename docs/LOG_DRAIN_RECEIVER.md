# dply Logs — app-log drain receiver

The **dply Logs** logging channel (channel type `dply_realtime`) makes a deployed
site stream its application logs to dply. The site's generated `config/logging.php`
configures a Monolog `SocketHandler` + `LineFormatter` that opens a **TLS-by-default
TCP** connection to `DPLY_LOG_DRAIN_HOST:DPLY_LOG_DRAIN_PORT` and writes one
newline-framed line per record:

```
<dly_token> <LEVEL> <message> <context>
```

dply receives those lines with a long-lived listener and stores them in the
`app_logs` table, which the site's **Logs → App logs** panel reads.

> **This receiver must be deployed and supervised.** It is a TCP line server, not
> an HTTP route — nothing in the request/queue path starts it. Until it runs, the
> dply Logs channel still *connects/sends* (and the per-channel "Test" reports
> "sent, not yet seen in App logs"), but no records are stored.

## What runs

```
php artisan dply:log-drain:listen --host=0.0.0.0 [--port=$DPLY_LOG_DRAIN_PORT]
```

- Binds a TCP socket (TLS-terminated when `tls=true`) on the drain port. `--port`
  defaults to `config('log_drains.dply_realtime.port')`.
- Accepts connections, reads complete newline-framed lines, extracts the `dly_*`
  token (→ site via `sites.log_drain_token`, cached), reads the level word,
  applies the per-site rate limit, and writes one `app_logs` row per line.
- Tolerant by design — a malformed line is dropped, never fatal; an over-long
  line (no newline) is discarded.

## Where it runs

On the box that owns `DPLY_LOG_DRAIN_HOST` (the dply control plane, or a
dedicated drain host). It must be reachable on the drain TCP port from customer
site servers, so open that port in the host/cloud firewall (see the firewall
architecture notes).

## TLS

TLS is **on by default**. The receiver terminates TLS using the cert/key in
config; sites connect with `tls://`. Provide a cert valid for the hostname sites
use (`DPLY_LOG_DRAIN_HOST`):

```
DPLY_LOG_DRAIN_TLS=true
DPLY_LOG_DRAIN_TLS_CERT=/etc/dply/log-drain.crt   # PEM (fullchain)
DPLY_LOG_DRAIN_TLS_KEY=/etc/dply/log-drain.key    # PEM private key
# DPLY_LOG_DRAIN_TLS_PASSPHRASE=                  # if the key is encrypted
```

Set `DPLY_LOG_DRAIN_TLS=false` **only** on a trusted/private network where a
trusted cert isn't available — sites then connect with plain `tcp://` (cleartext).
Changing the TLS setting requires sites to redeploy (it's baked into their
generated `config/logging.php` scheme).

## Supervisor unit

A ready unit is committed at **`deploy/supervisor/dply-log-drain.conf`** — install
it on the drain host only:

```
sudo cp deploy/supervisor/dply-log-drain.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
```

It runs the listener under a bash guard that backs off ~30s (rather than
crash-looping) when the drain port/cert isn't configured yet.

## Config

| env | meaning | default |
| --- | --- | --- |
| `DPLY_LOG_DRAIN_HOST` | host sites connect to (and the receiver advertises) | — |
| `DPLY_LOG_DRAIN_PORT` | TCP port the receiver binds and sites connect to | — |
| `DPLY_LOG_DRAIN_TLS` | terminate TLS (sites use `tls://`); false = plain `tcp://` | true |
| `DPLY_LOG_DRAIN_TLS_CERT` | PEM cert the receiver presents | — |
| `DPLY_LOG_DRAIN_TLS_KEY` | PEM private key | — |
| `DPLY_LOG_DRAIN_TLS_PASSPHRASE` | key passphrase, if encrypted | — |
| `DPLY_LOG_DRAIN_RETENTION_DAYS` | days of `app_logs` kept (pruned daily) | 30 |
| `DPLY_LOG_DRAIN_RATE_MAX` | max records/window per site (0 = unlimited) | 600 |
| `DPLY_LOG_DRAIN_RATE_WINDOW` | rate-limit window, seconds | 60 |

`HOST`/`PORT` are the same values injected into each site's `.env` for the dply
Logs channel (`config/log_drains.php`). Run `php artisan config:cache` after edits.

## Retention

`app_logs` lives in the main DB, so `php artisan app-logs:prune` runs daily
(`DplySchedule`, 04:05) and deletes records older than `retention_days`.

## Security posture

- **TLS by default** — the connection is encrypted, and the app verifies dply's
  certificate, so log contents are confidential in transit and the endpoint is
  authenticated. (Plain `tcp://` is available for trusted private networks only.)
- **Routing is by per-site token** (`dly_…`) baked into each line. TLS protects
  confidentiality; the token is what attributes a line to a site. Still firewall
  the port to known site-server IPs and rotate a token (re-save the binding) if it
  leaks — a client cert per site (mTLS) is a possible future hardening.
- **Per-site rate limit** caps ingest so one chatty/abusive app can't flood
  `app_logs` (drops excess for the rest of the window).

## Diagnostics

- `php artisan dply:log-drain:listen --once` processes a single line then exits —
  useful to confirm binding/cert/permissions.
- The per-channel **Test** button on the Logs tab emits a tokened record and
  polls `app_logs` for it: a green "Confirmed" means the full round-trip works.

## Not in scope (later product phase)

Live tailing and full-text search over `app_logs`. This phase ships ingestion +
storage + retention + a paged/filtered reader only.
