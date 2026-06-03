# dply Open-Source Extraction Spec — `dply-engine` + `dply` (CE)

**Status:** draft / planning
**Goal:** Launch dply via open source for top-of-funnel awareness, drawing an open-core line that gives away the single-server node + a self-hostable web UI, while keeping fleet, billing, and managed cloud/edge/serverless proprietary.
**Appetite:** heavy (months). Two new public repos under the `dply-io` org.

---

## 1. Repos

| Repo | License | Visibility | Role |
|---|---|---|---|
| `dply-io/dply-engine` | MIT | public | Framework-agnostic PHP library: SSH, provisioning, firewall, deploy phases, webserver config, runtime detection, presets. **Consumed by both CE and Cloud.** |
| `dply-io/dply` | MIT (or AGPL — see §7) | public | CE: slim Laravel app wrapping `dply-engine` with a single-server web UI, queue, SQLite/MySQL, GitHub deploy hooks, one-command installer. **The star magnet / launch artifact.** |
| this repo (private) | — | private | dply **Cloud**. Depends on `dply-io/dply-engine` via Packagist. Adds control plane: billing, orgs, fleet, edge, serverless, managed cloud. |

**Why node is separate, not a subtree of CE:** Cloud must consume the node *without* the CE web UI. A standalone composer package is the only clean dependency graph and is what makes the dogfooding claim true ("the node you self-host is the exact code running our managed fleet").

---

## 2. The open-core line (validated against the codebase)

### OPEN → `dply-engine`
- `app/TaskRunner/**` — already a dependency-free SSH execution engine. **Seed crystal.**
- `app/Services/SshConnection.php` + `app/Contracts/RemoteShell.php` (`exec` / `putFile` — the clean port)
- `app/Services/Servers/ServerProvisionCommandBuilder.php` — the master provision-script generator
- Firewall: `ServerFirewallProvisioner`, `FirewallRuleDiffService`, `FirewallRuleStateHasher`, `FirewallRuleTemplateApplicator`, `ServerFirewallSnapshotService`
- Webserver config builders: `Caddy*`, `Nginx*`, `Apache*`, `Haproxy*` (the non-`Edge` ones), `RemoteWebserverConfigService`, `WebserverConfigDriftDetector`, `WebserverSmokeTestRunner`
- Deploy: `DeployPhaseRunner`, `DeployContext`, `ByoServerDeployEngine`, `DockerDeployEngine`, `DeploymentPreflightValidator`, `RuntimeDetection/**`, `LocalRuntimeDetector`
- Supervisor/presets: `SupervisorProvisioner`, `SupervisorDeployRestarter`, script catalog
- Env requirements scanner (`.env.example` + `env()`/`config()` → missing-no-default detection)

### CLOSED → Cloud (this repo)
- `app/Services/Billing/**`, `config/subscription.php`, Cashier/Stripe, `Subscription*` models
- `Organization`, `Team`, `OrganizationInvitation`, `OrganizationSshKey`, `ProviderCredential`
- `app/Services/Cloud/**` (DO App Platform, AWS App Runner), `app/Services/Edge/**` (Cloudflare R2/KV/Workers), `app/Services/Serverless/**`
- Deploy engines: `AwsLambdaDeployEngine`, `DigitalOceanFunctionsDeployEngine`, `KubernetesDeployEngine` (Cloud-only; CE ships BYO + Docker)
- Admin panel, Pennant feature flags, referrals

### The seam is shallow (good news)
Deploy/provision code **never writes billing state**. `Organization` appears only for **audit context and UI cost cards**:
- `EphemeralDeployCredentialManager` (type check only)
- `ServerSshSessionManager`, `ProvisionStepEtaService` (historical lookups / audit)
- `ServerCostCard`, `OrganizationCostObservatory` (UI layer, off the deploy path)

→ Decoupling is a parameter-type refactor (`Organization $org` → `string $orgId` / `NodeContext` interface), not surgery. No hardcoded secrets; all `.env`; SSH keys encrypted at rest.

### The real cost
Agent code currently leans on **Eloquent models** and the **Laravel container** (`app()`/`resolve()`). Standalone `dply-engine` must replace model dependencies with plain DTOs/interfaces and constructor injection. This is the bulk of the estimate.

---

## 3. `dply-engine` package shape

```
dply-io/dply-engine/   (composer: dply-io/dply-engine, PSR-4 Dply\Engine\)
  src/
    Contracts/        RemoteShell, DeployEngine, ProvisionTarget, NodeContext
    Ssh/              SshConnection, SecureShellKey, ProcessRunner (from TaskRunner)
    Provision/        ProvisionCommandBuilder, role recipes
    Firewall/         FirewallProvisioner, RuleDiff, StateHasher
    Webserver/        Caddy/Nginx/Apache/Haproxy config builders
    Deploy/           PhaseRunner (BUILD→SWAP→RELEASE→RESTART), ByoEngine, DockerEngine
    Runtime/          detection (Laravel/Node/etc.), env-requirements scanner
    Presets/          supervisor + script templates
    Dto/              Server, Site, Deployment (plain data — NOT Eloquent)
  bin/dply-engine       thin CLI: provision | firewall | deploy | webserver-config
  tests/
```

**Hard rules** (CI-enforced): no `Illuminate\Database\*`, no `App\Models\*`, no `app()`/`resolve()`, no billing/org imports. All host/site data enters via DTOs; all side effects go through `RemoteShell`.

---

## 4. CE (`dply-io/dply`) shape

Slim Laravel app = `dply-engine` + thin persistence + UI.
- **Web UI** (Livewire): add server (paste SSH creds) → provision → add site → deploy → logs. Single server (or a small handful), no orgs/teams/billing.
- **Queue** (honors "queue all SSH operations" — never inline in HTTP).
- **DB:** SQLite default, MySQL optional. Migrations for `servers`/`sites`/`deployments` only.
- **Source control:** GitHub deploy hook + manual deploy.
- **Installer:** `curl -fsSL get.dply.io/ce | bash` → Docker compose (app + queue + db) bootstrapped on a fresh box. **This is the viral demo.**

---

## 5. Sequencing (task breakdown)

### Phase 0 — Scaffold ✅ (done — local at `~/Projects/Apps/dply-engine`, not yet pushed to `dply-io`)
- [x] Composer skeleton (`dply-io/dply-engine`, PSR-4 `Dply\Engine\`, MIT) + CI (import-ban lint, phpstan L8, pest matrix 8.2–8.4). `composer check` green.
- [x] Contracts: `RemoteShell` (exit-code-aware), `NodeContext` (tenant/audit seam), `DeployEngine`. Exception: `RemoteCommandFailed`.
- [x] DTOs (the Eloquent-replacing data contract): `Server`, `Site`, `Deployment`, `PhaseResult`; enums `ServerRole`, `Webserver`, `DeploymentStatus`.
- [x] Import-ban lint enforcing zero control-plane coupling (`scripts/check-imports.php`).
- [ ] Create the GitHub repo under `dply-io` and push (owner action — not done automatically).

> **Naming note:** `dply-node` was already taken — it's the existing TypeScript **dply Node SDK** (API client) at `~/Projects/Apps/dply-node`. The PHP server engine is therefore named **`dply-engine`** to avoid collision.

### Phase 1 — node core: SSH + firewall + provision (≈2–3 wk)
- [ ] Lift `TaskRunner/` (strip Livewire/Http/Broadcasting sub-trees → those belong to CE/Cloud, not the lib).
- [ ] Port `SshConnection` against `RemoteShell`; `ProvisionCommandBuilder`; firewall services.
- [ ] CLI: `dply-engine provision`, `dply-engine firewall`. **Shippable, star-worthy on its own.**

### Phase 2 — deploy layer (≈3–4 wk)
- [ ] Port `DeployPhaseRunner` + `ByoServerDeployEngine` + `DockerDeployEngine` behind data interfaces.
- [ ] Webserver config builders + runtime detection + env scanner.
- [ ] CLI: `dply-engine deploy --repo … --site …`.

### Phase 3 — decouple Organization (≈1 wk, parallel with P2)
- [ ] `Organization $org` → `string $orgId`/`NodeContext` in `ServerSshSessionManager`, `ProvisionStepEtaService`, `EphemeralDeployCredentialManager`.
- [ ] Move audit logging + cost cards to Cloud (listeners), out of the node path.
- [ ] **Gate:** node compiles & tests with zero `App\Models`/billing imports.

### Phase 4 — CE app + web UI + installer (≈3–4 wk)
- [ ] Slim Laravel app on `dply-engine`; Livewire single-server flow; queue; SQLite.
- [ ] One-command Docker installer + `get.dply.io/ce`.
- [ ] README with the 60-second fresh-VPS demo video.

### Phase 5 — Cloud consumes node (dogfood) (≈1–2 wk)
- [ ] This repo `require dply-io/dply-engine`; replace in-process agent code with the package.
- [ ] Verify managed fleet runs on the published node. → credibility proof for launch.

### Launch
- [ ] Show HN / r/selfhosted / r/laravel / r/PHP; "CE vs Cloud" comparison table; multi-runtime + open + managed-upsell positioning.

---

## 6. Risks / open questions
- **Eloquent → DTO churn** is the schedule driver; Phase 0 DTO design de-risks it.
- **TaskRunner has Livewire/Http/Broadcasting sub-trees** — confirm those are CE/Cloud concerns and only the execution core goes in the lib.
- **Env scanner location** — currently entangled with deploy gate; extract the pure scanner, leave the gate in CE/Cloud.

## 7. License — DECIDED: MIT
- **Both repos MIT.** Max adoption and goodwill; accepts that a competitor could re-host CE. The bet is that the managed control plane (fleet, billing, edge, serverless, cloud) is the moat, not the single-server mechanics.

## 8. Installer — DECIDED
- `curl -fsSL get.dply.io/ce | bash` → Docker compose (app + queue + db) on a fresh box.
