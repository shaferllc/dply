# Server Error Reference Codes

Status: **PR1–PR3 done** (header + visible reference, Tier-1 manual lookup, Tier-2
auto-capture sweeper). Goal: when a managed site returns a 5xx,
the visitor sees a short **reference code**, and the operator can paste/click that
code in dply to see *what actually happened* — turning the branded "dply hit a
server error" splash from a dead-end into a debuggable event.

Pattern: Cloudflare Ray ID / Sentry event ID. A per-request id is minted at the
edge webserver, shown on the error page + emitted as a response header, written
into the logs, and resolved back to the real error inside dply.

## Why this is needed

- The branded 500 page (`SiteServerErrorPageBuilder`) is a **static file**
  (`/__dply__/errors/500.html`) built once at provision — no per-request context.
- The `ErrorEvent` stream (Errors tab) is fed only by dply's **own** failed
  operations (ConsoleActions, SiteDeployments), **not** the customer app's runtime
  5xx. So the page's "the operator has been notified" line is currently
  aspirational for app errors — dply doesn't see them.

## Architecture

```
 visitor request
      │
   [edge webserver]  ── mints request id  (nginx $request_id / caddy {http.request.uuid})
      │                 ├─ X-Dply-Ref: <id>            (response header, all engines)
      │                 ├─ injects <id> into 500.html  (nginx sub_filter)
      │                 └─ fastcgi_param REQUEST_ID    (→ PHP-FPM)
      ▼
  [php-fpm pool]    ── logs ref=<id> t=<epoch> <method> <uri> status=… to the
      │                 per-site pool access.log (dply-owned; no global nginx cfg)
      ▼
  branded 500 page shows  "Reference  <id>"
      │
      ▼
  dply Errors tab
   ├─ Tier 1: "Resolve a reference" → queued SSH job greps the FPM access log for
   │          <id> → request + epoch, then time-correlates the app/error logs
   └─ Tier 2: scheduled sweeper parses 5xx log entries → writes ErrorEvent rows
              (category=http_5xx, reference=<id>, remediation matched) so errors
              appear automatically + notify; the page code deep-links to the event.
```

## Webserver matrix (the reference plumbing)

| Engine        | `X-Dply-Ref` header | request id var          | body injection (visible row) |
|---------------|---------------------|-------------------------|------------------------------|
| nginx         | ✅                  | `$request_id`           | `sub_filter` ✅ (PR1)        |
| Caddy         | ✅                  | `{http.request.uuid}`   | `templates` — follow-up      |
| Apache        | ✅ (mod_unique_id)  | `%{UNIQUE_ID}e`         | SSI — follow-up              |
| OpenLiteSpeed | — (follow-up)       | built-in                | follow-up                    |

Header + log id is the correlation key → Tier 1 lookup works on every engine that
sets the header. The visible on-page code ships for **nginx first** (clean
`sub_filter`); Caddy uses `templates` and Apache uses SSI for body injection —
deferred because both parse/transform the whole page and are more fragile. Until
then those engines are header-only, and the visible row is **gated on injection
support** (`errorPageReferenceInjected()`), so no literal `{{DPLY_REF}}` ever
leaks to a visitor.

## Delivery

- **PR1 — the code (done).** `X-Dply-Ref` header on nginx/Caddy/Apache 5xx
  + visible "Reference" row on the branded page via nginx `sub_filter` (gated on
  injection support). Existing sites pick it up on next **re-apply / provision**.
- **PR1b — log correlation (done).** Correlation lives on the **FPM side**, not a
  global nginx `log_format` (which would touch http-context config fleet-wide and
  risk `nginx -t`). nginx passes `fastcgi_param REQUEST_ID $request_id`; the
  per-site FPM pool — already dply-owned — logs it via a dedicated `access.log` +
  `access.format` (`ref=… t=<epoch> … <method> <uri> … status=…`) plus a per-pool
  `php_admin_value[error_log]`. The pool-ensure script `mkdir -p /var/log/php-fpm`
  (the FPM master, root, opens the files). PHP 500s — the ones with traces —
  always run through FPM, so coverage is exactly right; a pure 502 (FPM never ran)
  has no app trace to resolve anyway.
- **PR2 — Tier 1 lookup (done).** Errors tab → "Resolve a reference code" card.
  `SiteErrorReferenceResolver` runs one capped bash script over SSH:
  grep the FPM access log for `ref=<id>` → the exact request + epoch, then
  time-correlate (UTC **and** server-local second prefixes, ±2s, to survive log
  TZ differences) against `storage/logs/laravel.log`, the pool error log, and the
  webserver error log. Driven by a queued `LookupSiteErrorReferenceJob`
  (`WritesConsoleAction`) so it streams into the existing console-action banner;
  result panel polls until terminal.
- **PR3 — Tier 2 auto-capture (done).** Scheduled sweeper turns 5xx responses
  into `http_5xx` ErrorEvents on their own — no longer only resolvable by hand.
  - Migration adds a `reference` column to `error_events` (indexed; dedupe key).
  - `SiteHttp5xxLogScanner` runs one capped, read-only bash script per site:
    tail the per-pool FPM access log (current + one rotation), `grep ' status=5xx$'`,
    cap to the most recent N. The fixed access format (`ref=… t=… at=… <method> <uri>
    dur=… status=…`) means no app-side log parsing on the box.
  - `ErrorEventRecorder::recordHttp5xx()` upserts idempotently on the reference;
    the row's link deep-links the Errors tab to the Tier-1 resolver
    (`?reference=…`, URL-bound on `Errors::$referenceQuery`) so the operator pulls
    the actual trace on demand. No remediation is matched here (the access log has
    the request, not the exception text).
  - `SweepSiteHttpErrorsJob` (per site, `SerializeServerSsh` + `ShouldBeUnique`)
    records hits and **folds notifications**: only the first 5xx of a fresh streak
    alerts — a crash loop won't blast one notification per request reference.
  - `SweepSiteHttpErrorsCommand` (scheduled every 10 min) dispatches for eligible
    sites only: PHP-FPM (`usesDedicatedPhpFpmPool()`), SSH-managed, ready server —
    container/serverless/worker/Apache/OLS are skipped.
  - Config: `config/server_error_codes.php` (`sweep_enabled`, `sweep_lookback_minutes`,
    `sweep_max_per_site`). Eligible categories render generically in the stream
    (`http_5xx` → "Http 5xx" chip) with the per-event title `HTTP <code> — <method> <uri>`.

## Interactions / notes

- `expose_server_errors` site meta (raw 5xx pass-through, operator debug) stays —
  this feature makes it rarely necessary, since you can debug from the code now
  without leaking raw errors to visitors.
- `APP_DEBUG` already lets the framework's own 500 page through for app errors;
  the reference header is still emitted in that case for log correlation.
