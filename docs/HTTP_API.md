# HTTP API

Use organization-scoped **API tokens** from **Profile → API keys** to call the dply HTTP API from CI/CD, scripts, or integrations. Tokens are sent as **Bearer** credentials.

## Base URL

All versioned routes are under:

```text
{APP_URL}/api/v1
```

Replace `{APP_URL}` with your application origin (for example `https://app.example.com`).

## Authentication

Send the header:

```http
Authorization: Bearer YOUR_TOKEN_HERE
```

Tokens belong to a **user** and **organization**. Each token lists **abilities** (permissions). The **deployer** organization role can only use abilities allowed by organization policy (typically read + deploy).

Creating new tokens may require a **Pro** subscription when your instance enables `DPLY_API_TOKENS_REQUIRE_PAID_PLAN`.

## Common endpoints

| Method | Path | Typical ability |
| --- | --- | --- |
| `GET` | `/api/v1/servers` | `servers.read` |
| `POST` | `/api/v1/servers/{server}/run-command` | `commands.run` |
| `GET` | `/api/v1/sites` | `sites.read` |
| `POST` | `/api/v1/sites/{site}/deploy` | `sites.deploy` |
| `GET` | `/api/v1/sites/{site}/deployments` | `sites.read` |
| `GET` | `/api/v1/sites/{site}/deployments/{deployment}` | `sites.read` |
| `GET` | `/api/v1/sites/{site}/errors` | `sites.read` |
| `POST` | `/api/v1/sites/{site}/errors/dismiss` | `sites.write` |
| `POST` | `/api/v1/sites/{site}/errors/{event}/retry` | `commands.run` |
| `POST` | `/api/v1/sites/{site}/errors/{event}/remediate` | `commands.run` |
| `GET` | `/api/v1/sites/{site}/uptime` | `sites.read` |
| `GET` | `/api/v1/sites/{site}/uptime/history` | `sites.read` |
| `POST` | `/api/v1/sites/{site}/uptime/check` | `sites.write` |
| `GET` | `/api/v1/notifications/channels` | `notifications.read` |
| `GET` | `/api/v1/notifications/events` | `notifications.read` |
| `POST` | `/api/v1/notifications/channels/{channel}/test` | `notifications.write` |
| `GET` | `/api/v1/sites/{site}/notifications` | `notifications.read` |
| `POST` | `/api/v1/sites/{site}/notifications` | `notifications.write` |
| `GET` | `/api/v1/servers/{server}/notifications` | `notifications.read` |
| `POST` | `/api/v1/servers/{server}/notifications` | `notifications.write` |
| `GET` | `/api/v1/serverless/sites/{site}/platform` | `serverless.read` |
| `GET` | `/api/v1/serverless/sites/{site}/platform/schedules` | `serverless.read` |
| `POST` | `/api/v1/serverless/sites/{site}/invoke` | `serverless.invoke` |
| `GET` | `/api/v1/serverless/sites/{site}/credentials` | `serverless.read` |
| `PUT` | `/api/v1/serverless/sites/{site}/credentials` | `serverless.write` |
| `GET` | `/api/v1/serverless/sites/{site}/workers` | `serverless.read` |
| `PUT` `POST` `PATCH` `DELETE` | `/api/v1/serverless/sites/{site}/workers` | `serverless.write` |
| `POST` | `/api/v1/serverless/sites/{site}/workers/tick` | `serverless.invoke` |
| `GET` | `/api/v1/serverless/sites/{site}/schedule` | `serverless.read` |
| `PUT` | `/api/v1/serverless/sites/{site}/schedule` | `serverless.write` |
| `POST` | `/api/v1/serverless/sites/{site}/schedule/tick` | `serverless.invoke` |
| `GET` | `/api/v1/serverless/sites/{site}/runtime` | `serverless.read` |
| `PATCH` | `/api/v1/serverless/sites/{site}/runtime` | `serverless.write` |
| `POST` | `/api/v1/serverless/sites/{site}/runtime/rotate-secret` | `serverless.write` |
| `GET` | `/api/v1/servers/{server}/firewall` | `network.read` |
| `POST` | `/api/v1/servers/{server}/firewall/apply` | `network.write` |
| `GET` | `/api/v1/insights/summary` | `insights.read` |
| `GET` | `/api/v1/servers/{server}/insights` | `insights.read` |

Operator-only routes under `/api/v1/operator/*` use separate middleware and are not for general API tokens.

### Sites are one resource, four kinds

`GET /api/v1/sites` returns every site in the organization — VM, Cloud container
app, Edge site, and serverless function are the same model — and each row carries
a `kind` of `vm`, `cloud`, `edge`, or `serverless`. `GET /api/v1/edge/sites` and
`GET /api/v1/serverless/sites` are the product-specific views of the same rows,
behind their own abilities, and return the extra fields those products have.

`{site}` accepts either the slug or the ULID.

### Serverless platform

`GET /api/v1/serverless/sites/{site}/platform` reads the function host live and
returns the deployed action — `runtime`, `entry`, `memory_mb`, `timeout_ms`,
`concurrency`, `log_limit_mb`, `web_export`, `published`, `code_bytes`,
`version` — alongside the namespace inventory (`actions`, `packages`,
`triggers`, `rules`). When the host cannot be read, `action` is `null` and
`error` carries the reason; the request itself still succeeds.
`…/platform/schedules` returns the cron triggers.

`POST /api/v1/serverless/sites/{site}/invoke` sends a real request at the
function: `method`, `path`, `body`, `query`, and a `headers` object. The result
includes the status code, duration, response excerpt, and log lines, and is
stored as a `source=test` invocation. It runs customer code, so it sits behind
its own `serverless.invoke` ability rather than `serverless.read`.

`GET …/credentials` reports the namespace, API host, the **key id only**, and a
live check of whether the host still accepts the stored key. `PUT` the same path
with `{"access_key": "<key-id>:<secret>"}` to store a rotated key: it is verified
against the host before it sticks, and the previous key is restored if the host
rejects it.

### Serverless workers

`GET …/workers` returns `engine_enabled`, the last `queue` tick, and the worker
list with each worker's derived status. `PUT` the same path with
`{"enabled": bool}` to flip the queue engine; `POST` adds a worker
(`name`, `command`, optional `concurrency` / `restart_policy` / `enabled`);
`PATCH …/workers/{worker}` patches only the keys you send; `DELETE` removes one.
A worker is addressed by id **or** by name. `POST …/workers/tick` fires one
queue tick — it runs your code, so it needs `serverless.invoke`.

### Serverless schedule

`GET …/schedule` returns `enabled`, `total_ticks`, and a page of firing history
(`?limit=`, `?failed=1`). `PUT` it with `{"enabled": bool}` to flip dply's
minute-cadence scheduler tick; `POST …/schedule/tick` fires one now
(`serverless.invoke` — it runs your code). The functions host's own cron
triggers are a separate surface: `GET …/platform/schedules`.

### Serverless runtime

`GET …/runtime` returns the Runtime tab as data: `limits` (with
`pending_redeploy`), `http` (web mode, secured, CORS), `parameters`,
`log_forwarding` (provider + `token_set`, never the token), `maintenance`, and
`keep_warm`. `PATCH` the same path with any subset of `memory_mb`,
`timeout_ms`, `concurrency`, `logs_kb`, `web_mode`, `secured`,
`provide_api_key`, `cors`, `parameters`, `parameters_final`, `log_forwarding`,
`maintenance`, `keep_warm` — anything absent is left alone, except
`parameters`, which replaces the map whole. Limits apply on the next deploy;
HTTP settings are pushed to the live action, and the response's `applied` says
which happened. `POST …/runtime/rotate-secret` replaces the endpoint secret
(every existing caller starts getting 401s).

A function's environment variables are not here — they are the shared site
surface, `/api/v1/sites/{site}/env`.

### Notifications

`GET /api/v1/notifications/channels` lists the channels the token's user may
route to (their own, plus org and team channels they can manage), each with
`type` and a redacted `destination`. `GET …/notifications/events` returns the
event catalog grouped by category; `?subject=site` or `?subject=server` narrows
it to what that kind of subject can subscribe to.

`GET /api/v1/sites/{site}/notifications` (and the `servers` equivalent) returns
`groups` — every event group that applies to this subject, including `edge.*`
for Edge sites and `serverless.*` for functions — plus `channels`, each with the
`events` currently routed to it.

`POST` the same path with `{"channel": "…", "subscribe": [...], "unsubscribe":
[...]}`. It adds and removes rather than replacing the channel's selection, and
touches no other channel. An event outside the subject's groups is a `422`; a
channel the token cannot reach is a `404`.

`POST /api/v1/notifications/channels/{channel}/test` sends that channel's own
test message and returns `{"data": {"ok": bool, "message": "…"}}` — `200` when
the provider accepted it, `422` when it did not.

### Uptime

`GET /api/v1/sites/{site}/uptime` returns one row per monitor with `check_type`,
`path`, `probe_region`, and its last result: `status` (`up` | `down` |
`unchecked`), `http_status`, `latency_ms`, `last_error`, `last_checked_at`.

`GET …/uptime/history` adds the rollup the workspace charts: `uptime` for
`24h`/`7d`/`30d` (null until there is data) and the ten most recent incidents,
each with `severity`, `cause`, `started_at`, `resolved_at`, and `ongoing`.
`?monitor=<id>` narrows it to one. The per-check latency series is not included.

`POST …/uptime/check` with `{"id": "…"}` or `{"all": true}` queues a probe and
returns `202` with `{"data": {"queued": N, "ids": [...]}}`. The job is unique per
monitor for two minutes, so asking twice in that window probes once.

### Errors

`GET /api/v1/sites/{site}/errors` lists open (undismissed) error events for a
site of any kind, newest first, `limit` capped at 50. Each row includes
`category`, `title`, `detail`, `remediation_code`, `link_url`, and `retryable` —
the last says whether `…/retry` will do anything.

Acting on one:

- `POST …/errors/dismiss` with `{"id": "…"}` or `{"all": true}` → `{"data": {"dismissed": N}}`.
- `POST …/errors/{event}/retry` → `202` when the failed operation was re-queued, `422` when that category isn't retryable.
- `POST …/errors/{event}/remediate` (optional `{"action": "<key>"}`) → `202` when the fix was queued; `422` when there is no fix, the action is stale, or the fix is a manual one (a link to a settings page rather than a script).

## Metrics

`POST /api/metrics` is used for server metrics ingestion and related callbacks; it is **not** part of the bearer-token API surface described above.

## Related

- [Organization roles & plan limits](/docs/org-roles-and-limits)
- [Source control & deploy flow](/docs/source-control)
