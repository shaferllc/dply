# Serverless / OpenWhisk Roadmap

Status: in flight (audit 2026-05-24). Decided 2026-05-21 via a structured
design interview. Supersedes the scope in `project_serverless_v1` memory.

A substantial Phase 0 / Phase 5 / Phase 6 scaffolding landed in the
2026-05-21 set of migrations and `app/Services/Serverless/{Aws,Backends}`
classes. The narrative below has been updated with per-PR state; the
**Sequenced roadmap** section is the source of truth for what is and
isn't shipped.

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
- **PR1 ✅** Capability-resolver honesty: model all 4 DO runtimes, add a Go flag,
  flip Python to `true`, remove the dead Rails detector branch.
  *(Done — see `ServerlessTargetCapabilityResolver` modelling all four
  runtime flags.)*
- **PR2 ✅** `function_actions` table + forward-only backfill migration (one action
  row per existing serverless Site, built from `meta.serverless.*`); backfill
  `FunctionInvocation.function_action_id`. Model only — no behavior change.
  Touches billing tables (`functions_active`): written forward-only with a
  dry-run / verification step. No database resets.
  *(Done — `2026_05_21_000002_create_function_actions_table.php`,
  `2026_05_21_000003_add_function_action_id_to_function_invocations_table.php`,
  `app/Models/FunctionAction.php` with `KIND_CODE` / `KIND_SEQUENCE`.
  **Open**: verify the workspace-tables seeding gap from
  `project_provision_seeding_gap` memory does not block backfill on existing
  function workspaces.)*

### Phase 1 — Raw multi-language (DO)
- **PR3 ⏳** Detector generalization — the precedence ladder; raw-action detection
  for Node/Python/Go/PHP.
- **PR4 ⏳** Artifact builder — per-language build commands and adapter dispatch.
- **PR5 ⏳** The 4 per-language logging shims.
- **PR6 ⏳** Create-flow change — `runtime` becomes "Auto-detect" with override.

### Phase 2 — Package model (1 Site = N actions)
- **PR7 ⏳** Action discovery — `functions/*` convention + `project.yml`.
- **PR8 ⏳** Workspace IA — action list + drill-in, per-action pages.
- **PR9 ⏳** Per-action billing — `functions_active` re-metered at action granularity.
  **Open: reconcile with the new tiered pricing model (see Cross-stream
  conflicts below) before this PR locks the billing shape.**

### Phase 3 — Framework adapters (independent leaves, parallelizable)
- **PR10 ⏳** Express · **PR11 ⏳** Flask · **PR12 ⏳** Django · **PR13 ⏳** Gin.
  **Priority note**: Edge hybrid SSR (shipped 2026-05-23) covers most
  framework-app workloads via Edge front + Cloud origin container. Demand for
  *serverless-native* framework adapters is now narrower (cost-sensitive,
  spiky workloads). Worth re-validating before committing PR10–13 effort.

### Phase 4 — Triggers
- **PR14 ⏳** DO cron triggers via the OW REST API; retire the tick subsystem;
  migrate the Laravel scheduler and keep-warm onto triggers.
  *(Trigger backend abstraction exists —
  `app/Services/Serverless/ServerlessTriggerProvisioner.php` +
  `app/Services/Serverless/Backends/ServerlessTriggerBackend.php`. Tick code
  still live and referenced by `BackgroundPanel`, `Workers`, `Schedule`.
  PR14 must retire the latter to call this done.)*

### Phase 5 — Sequences
- **PR15 🟡** Codeless Site type; `kind=sequence` rows; sequence-builder UI.
  *(Model + backend wiring exist — `FunctionAction::KIND_SEQUENCE`,
  `ServerlessSequenceBuilder`, `ServerlessSequenceDeployer`. UI/Livewire
  surface still pending.)*

### Phase 6 — Lambda parity
- **PR16 ✅** Backend-abstraction interface
  (`ServerlessTriggerBackend`, `ServerlessSequenceBackend`,
  `UnsupportedServerlessBackend`).
- **PR17 🟡** EventBridge — backend exists
  (`AwsEventBridgeTriggerBackend`, `EventBridgeCronExpression`); needs
  wiring into the trigger provisioner + create flow once PR14 ships.
- **PR18 🟡** Step Functions — backend exists
  (`AwsStepFunctionsSequenceBackend`, `StepFunctionsDefinition`); needs
  the same wiring once PR15 ships.

Legend: ✅ shipped · 🟡 scaffolded, not wired through · ⏳ not started.

## Cross-stream conflicts to resolve before re-starting Phase 1

These have emerged since the 2026-05-21 design interview. Each should be
decided before the affected PR begins, not during.

1. **Pricing model**. The current roadmap commits to "per code action, flat
   per-function fee". The newer pricing redesign
   (`project_pricing_model` memory) sets a $15 base + spec-tiered per-server
   fee with a $40 cap. Functions sites have no "spec" in the VM sense.
   Decide: do function sites bill (a) a per-action flat fee outside the
   tier model, (b) a per-site flat fee folded into the new tier as the
   smallest tier, or (c) usage-only (DO Functions invocations + storage)
   passthrough plus a small platform overhead? PR9 cannot ship without
   this choice.

2. **Edge hybrid SSR shipped**. Edge front + Cloud origin (Phase 4a edge
   roadmap, 2026-05-23) covers Next.js / SvelteKit / Nuxt with a real
   container backend. This narrows the addressable demand for serverless
   framework adapters (PR10–13). Action: defer PR10–13 until at least
   PR3–9 have shipped and we can validate demand from real usage.

3. **Provision seeding gap**. `project_provision_seeding_gap` memory
   notes that workspace tables are not seeded by provisioning today. The
   `function_actions` backfill in PR2 used a forward-only migration, but
   any *new* function workspaces created after the gap is fixed will need
   to seed `function_actions` from `meta.serverless.*` automatically.
   PR3+ should assume action rows always exist for a function site;
   ProvisioningJob needs the corresponding seed call.

4. **Tick subsystem still in production**. `ServerlessTickCommand`,
   `InvokeFunctionTick`, and the Livewire consumers (`Workers`, `Schedule`,
   `BackgroundPanel`) still rely on the old tick path. PR14 must remove
   these *together* — partial removal will silently break scheduled
   functions. Worth treating PR14 as a hard sequence: retire ticks ↔
   migrate scheduler + keep-warm in the same PR.

5. **EdgeBackend pattern is the model**. The container/Edge work
   (`project_dply_edge_architecture` memory) established the
   `EdgeBackend` interface as the way to abstract over DO App Platform,
   AWS App Runner, etc. PR16's `ServerlessTriggerBackend` /
   `ServerlessSequenceBackend` follow the same pattern — good. PR17/PR18
   should reuse the existing `ProviderCredential` flow for the AWS
   credential rather than introducing a parallel mechanism.

## Open implementation details (resolve during the relevant PR)

- Per-language entrypoint conventions: Go exported `Main`, Python `main`,
  Node `main` export. DO's remote `build.sh` / virtualenv mechanics.
- `ServerlessFunctionProxyController` + the `/fn/{slug}` route must become
  per-action once a Site holds N actions.
- DO scheduled-trigger minimum granularity is 1 minute.
- Retiring blocking-invoke ticks loses the platform cold-start annotation —
  accepted; the logging shim reports wall-time duration only.
