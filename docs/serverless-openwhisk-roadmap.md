# Serverless / OpenWhisk Roadmap

Status: planned (not started). Decided 2026-05-21 via a structured design interview.
Supersedes the scope in `project_serverless_v1` memory.

## Corrected premise

DigitalOcean Functions **is** managed Apache OpenWhisk. dply was already an
OpenWhisk tenant — `FunctionInvoker` already speaks the OpenWhisk REST API and
the deploy handler already speaks the `__ow_*` web-action protocol. This program
does **not** adopt OpenWhisk. It (a) exposes more of the OpenWhisk surface DO
permits, and (b) builds AWS equivalents for Lambda parity.

Permanently impossible as a DO tenant (cluster-operator only):
custom feeds, arbitrary Docker/blackbox actions, API Gateway admin,
`wsk` namespace admin.

DO Functions runtimes are fixed: **Node.js, Python, PHP, Go**. Ruby/Rails is
impossible — the detector's existing Rails branch is dead code.

## Locked decisions

| Area | Decision |
|---|---|
| Backend | DO Functions (tenant). AWS Lambda reaches feature parity via an abstraction. |
| Multi-language | Auto-detected. Full adapter matrix — Laravel (exists) + Express, Flask, Django, Gin — plus a raw-action path. |
| Detection | Strict precedence ladder: explicit config → framework markers → `project.yml` → single-entry `main()` → hard fail with a clear message. |
| Raw observability | dply injects a per-language logging shim around the user's `main()`; the shim POSTs the invocation to `FunctionLogIngestController`. |
| Model | 1 Site = N actions (an OpenWhisk package). Framework apps are always exactly 1 action. New `function_actions` table; `FunctionInvocation` gains a `function_action_id` FK. |
| Action discovery | `functions/*` directory convention; honor an OpenWhisk `project.yml` if present. |
| Billing | Per **code** action (flat per-function fee). Sequences and triggers bill nothing. |
| Sequences | A codeless Site (no `git_repository_url`) holding `function_actions` rows with `kind=sequence`. Components may reference any code action in the namespace (cross-package). |
| Scheduling | Real DO cron triggers via the OW REST API. Delete `ServerlessTickCommand` + `InvokeFunctionTick`. Laravel scheduler becomes a `* * * * *` trigger carrying `x-dply-run: schedule`. Keep-warm becomes a trigger. |
| Deploy mechanism | Pure OpenWhisk REST API — no `doctl` binary dependency. |
| Workspace IA | Action list + drill-in. Per-action pages for runtime / memory / timeout / trigger / invocation URL / logs. Repo + build + deploy history stay Site-level. |
| Lambda parity | A backend-abstraction interface, plus AWS EventBridge (triggers) and AWS Step Functions (sequences). |

## Sequenced roadmap (~18 PRs, reviewed one at a time)

### Phase 0 — Foundation
- **PR1** Capability-resolver honesty: model all 4 DO runtimes, add a Go flag,
  flip Python to `true`, remove the dead Rails detector branch.
- **PR2** `function_actions` table + forward-only backfill migration (one action
  row per existing serverless Site, built from `meta.serverless.*`); backfill
  `FunctionInvocation.function_action_id`. Model only — no behavior change.
  Touches billing tables (`functions_active`): written forward-only with a
  dry-run / verification step. No database resets.

### Phase 1 — Raw multi-language (DO)
- **PR3** Detector generalization — the precedence ladder; raw-action detection
  for Node/Python/Go/PHP.
- **PR4** Artifact builder — per-language build commands and adapter dispatch.
- **PR5** The 4 per-language logging shims.
- **PR6** Create-flow change — `runtime` becomes "Auto-detect" with override.

### Phase 2 — Package model (1 Site = N actions)
- **PR7** Action discovery — `functions/*` convention + `project.yml`.
- **PR8** Workspace IA — action list + drill-in, per-action pages.
- **PR9** Per-action billing — `functions_active` re-metered at action granularity.

### Phase 3 — Framework adapters (independent leaves, parallelizable)
- **PR10** Express · **PR11** Flask · **PR12** Django · **PR13** Gin.

### Phase 4 — Triggers
- **PR14** DO cron triggers via the OW REST API; retire the tick subsystem;
  migrate the Laravel scheduler and keep-warm onto triggers.

### Phase 5 — Sequences
- **PR15** Codeless Site type; `kind=sequence` rows; sequence-builder UI.

### Phase 6 — Lambda parity
- **PR16** Backend-abstraction interface · **PR17** EventBridge · **PR18** Step Functions.

## Open implementation details (resolve during the relevant PR)

- Per-language entrypoint conventions: Go exported `Main`, Python `main`,
  Node `main` export. DO's remote `build.sh` / virtualenv mechanics.
- `ServerlessFunctionProxyController` + the `/fn/{slug}` route must become
  per-action once a Site holds N actions.
- DO scheduled-trigger minimum granularity is 1 minute.
- Retiring blocking-invoke ticks loses the platform cold-start annotation —
  accepted; the logging shim reports wall-time duration only.
