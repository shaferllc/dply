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

### Instance capabilities

`GET /api/v1/capabilities` reports what this instance actually offers: which
surfaces are enabled, which kinds the CLI can create, the serverless region
list, whether dply-managed delivery is available, the upload size cap, and the
organization's function quota. `dply init` reads it before showing anything, so
the CLI hardcodes none of it — a self-hosted instance with Edge switched off
simply does not offer Edge. An instance that predates this endpoint 404s, and
the CLI reports that by name rather than failing obscurely.

### Creating a serverless function

`POST /api/v1/serverless/sites` creates a function and starts its first deploy.
This is the only endpoint in the API that provisions billable infrastructure, so
it carries its own **`serverless.create`** ability — `serverless.write`
reconfigures a function that exists and deliberately does not extend to making
one — plus a per-organization rate limit and a feature flag operators enable per
instance.

| field | notes |
| --- | --- |
| `name` | required |
| `source_kind` | `git` (default) or `upload` |
| `repo`, `branch`, `git_ref_kind` | required for `source_kind=git`; `owner/name` shorthand is accepted |
| `repository_subdirectory` | build from a subdirectory of the repo (monorepos) |
| `region` | one of the regions `GET /capabilities` lists; defaults to the instance default |
| `delivery_mode` | `managed` (dply's platform) or `byo` (your DigitalOcean account) |
| `provider_credential_id` | optional — the organization's preferred healthy DigitalOcean credential is used when omitted |
| `runtime` | `auto` (default) leaves it to deploy-time detection |
| `env_file_content` | written straight to the site's **encrypted** environment; never logged or echoed back |
| `source_handle` | a handle from the upload endpoint, for `source_kind=upload` |
| `enable_push_to_deploy` | default true; registers the git webhook for a git source |

Send `"dry_run": true` to run every gate and the real runtime detection **without
side effects**. The response carries the detected plan (framework, runtime,
entrypoint, build command, and long-running signals like Horizon) plus the
organization's function quota. Detection is the same detector the deploy runs —
for a git source it clones a throwaway preview workspace, for an upload it
inspects the posted archive — so what the dry run reports is what the deploy will
decide.

When a precondition fails, the response is `422` with a typed **blocker** rather
than a message to parse:

```json
{"blocker": {
  "code": "no_provider_credential",
  "message": "This organization has no DigitalOcean credential…",
  "resolve_url": "https://dply.io/credentials",
  "resolve_command": null
}}
```

Codes: `surface_disabled`, `cli_create_disabled`, `forbidden`, `quota_exceeded`,
`trial_paused`, `managed_unavailable`, `no_provider_credential`,
`credential_unhealthy`, `invalid_region`, `source_required`. Codes are added,
never renamed. A `403` on this endpoint means the token predates the
`serverless.create` ability — re-approve it with `dply auth refresh`.

Send an **`Idempotency-Key`** header. A create whose response is lost otherwise
means a retry provisions a second billable namespace; with the key the original
create is replayed (`200`, `"replayed": true`) instead.

`POST /api/v1/serverless/sites/source` accepts a `.tar.gz` of a project folder as
`archive` and returns a `source_handle` for a create that has not happened yet.
`POST /api/v1/serverless/sites/{site}/source` replaces an existing function's
source and redeploys — for an upload-source site that *is* the deploy, since
there is no remote to push to. Archives are rejected server-side if they contain
absolute paths, `..` components, symlinks, hard links or device nodes, or if they
exceed the instance's size, entry-count, or uncompressed-size caps.

`DELETE /api/v1/serverless/sites/{site}` is the CLI's **undo**, not a general
delete: it refuses (`409`) any function that has ever deployed successfully, so a
token cannot destroy something that served a request. Deleting a live function
stays a dashboard action. The response reports `remote_error` / `bucket_error`
separately, because a namespace or bucket dply could not reach keeps billing.

### Creating a site on a server you own

`POST /api/v1/servers/{server}/sites` creates a site on a BYO server, behind the
**`sites.create`** ability and the `surface.vm_cli_create` flag. Same contract as
the other two creates — `dry_run`, typed blockers, `Idempotency-Key`, shared
create limiter.

Fields: `name` (required), `type` (`php` | `static` | `node`), `document_root`,
`primary_hostname`, `git_repository_url`, `git_branch`, `runtime`,
`runtime_version`, `build_command`, `start_command`, `app_port`, `framework`,
`env_file_content`.

`document_root` is optional: it defaults to `/home/dply/<hostname-or-slug>`,
following the same convention as `Site::conventionalRepositoryPath()`, with
`/public` appended for PHP. The dry run returns the path it would use, so the
caller can show it before committing.

**Narrower than the managed creates, deliberately.** It creates on ordinary
webserver hosts only. A functions, Docker, Kubernetes, or headless
(`webserver=none`) host returns a `host_unsupported` blocker pointing at the
dashboard, because sites on those need host-specific configuration — internal
port allocation, runtime targets, container registries — that the create wizard
builds from capability-specific form state. Additional blocker code:
`server_not_ready`, since a site created against a half-built server would sit
pending with the reason recorded on the server rather than the site.

### Creating a cloud app

`POST /api/v1/cloud/sites` creates a managed container app. Same shape as the
serverless create — own **`cloud.create`** ability, own feature flag, the shared
per-organization create limiter, `Idempotency-Key`, typed blockers on `422`, and
a `dry_run` that runs the whole gate chain with no side effects.

| field | notes |
| --- | --- |
| `name` | required |
| `mode` | `source` (default, builds from a repo) or `image` (prebuilt) |
| `repo`, `branch`, `dockerfile_path` | source mode |
| `image` | image mode |
| `port`, `instances`, `size_tier` | defaults `8080`, `1`, `small` |
| `region` | validated against the resolved backend's region list |
| `backend` | `auto` (default), `digitalocean_app_platform`, or `aws_app_runner` |
| `env_file_content` | the app's environment; never logged or echoed back |
| `deploy_on_push` | default true |

The dry run returns the **resolved backend**, its regions, the size tiers, and
the organization's cloud quota — the backend is what decides which regions are
even valid, so it has to be resolved before a region can be checked.

**A cloud app has no uploaded-source mode.** The container backend clones and
builds the repository itself, so dply never holds the source: a folder with no
reachable git remote genuinely cannot become a cloud app, and
`GET /api/v1/capabilities` reports this as `kinds.cloud.requires_git = true`.
That is the one structural difference from a function, which can be deployed
from a folder as-is.

Blocker codes add `no_backend` (no container credentials connected),
`source_unsupported` (App Runner source builds need an authorized GitHub
connection on the credential), and `spec_rejected` (the provider refused the
app spec). Note the web wizard additionally pre-flights the spec against the
provider before creating; that path lives in the shell, so over HTTP a spec
rejection surfaces on the real create rather than on the dry run.

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
