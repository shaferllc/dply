# Removing Cloud / Edge / Serverless from dply

Scope decided: **code only.** All 38 migrations stay, no tables or columns are
dropped, nothing destructive runs against any database. Dead tables remain and
are simply never read.

Delete this file when the last phase lands.

---

## 1. Name collisions — read before deleting anything

`grep -i cloud|edge|serverless` over this repo is *not* a delete list. Five
separate features share those words. Each was traced to its callers:

| Looks like it goes | Actually | Verdict |
|---|---|---|
| `app/Support/Servers/CaddyEdgeBackendLayout`, `app/Services/Servers/{Envoy,HAProxy,OpenResty}EdgeConfigBuilder`, `app/Support/Sites/EdgeBackendPortResolver`, `app/Services/Sites/SiteEdgeBackendProvisioner`, `app/Livewire/Servers/WorkspaceEdgeProxy` + `Concerns/ManagesServerEdgeProxy`, `app/Support/Servers/EdgeProxyWorkspaceViewData` | **L7 reverse proxy on customer VMs** (Traefik / HAProxy / Envoy / OpenResty). `EdgeBackendPortResolver` docblock: *"Stable high port for Caddy backends behind an L7 edge proxy"*. Nothing to do with the Edge product. | **STAYS** |
| `app/Models/CloudDatabase`, `CloudDatabaseSite`, `CloudDatabaseTrustedSource`, `database/factories/CloudDatabaseFactory` | The **managed-database** record. 24 references in `Modules/Database`, 7 in `Modules/Cache`. CLAUDE.md is explicit: *"The record is still `App\Models\CloudDatabase`"*. | **STAYS** |
| `app/Jobs/PollUpCloudIpJob`, `ProvisionUpCloudServerJob`, `app/Support/Servers/HetznerCloudFirewallRules` | UpCloud / Hetzner Cloud — **VM provider** integrations. | **STAYS** |
| `app/Services/Sites/Dns/CloudflareDnsProvider`, `app/Jobs/DetectSiteCloudflareTlsJob`, `app/Livewire/Concerns/ManagesSiteBindingCloudflareEmail` | Cloudflare as a **DNS/TLS/email provider** for ordinary sites. | **STAYS** |
| `app/Support/Servers/FakeCloudProvision`, `app/Jobs/Concerns/HandlesFakeCloudPoll`, `app/Actions/Servers/ApplyFakeCloudProvisionAsReady`, `config/servers/provision_fake.php` | Fake **VM** provisioning used by tests. | **STAYS** |
| `app/Support/Cloud/{AzureAccessToken,GcpAccessToken,OciRequestSigner}` | Provider auth helpers consumed by `Modules/Providers` (Azure/GCP/Oracle compute + DNS). Misfiled under `Support/Cloud`, unrelated to the PaaS. | **STAYS** — move to `app/Support/Providers/` in Phase 7 |
| `app/Livewire/Sites/{WorkerEnvComparison,WorkerProvisionPath}`, `Servers/WorkspaceWorkerPool`, `Servers/Concerns/ManagesWorkerPool*`, `Sites/Concerns/ManagesSiteWorkerPool`, `Livewire/Pulse/WorkerServersCard`, all of `app/Services/WorkerPools/`, `app/Models/WorkerPool` | **VM worker fleets** (on-box daemons, Horizon, autoscaling). Distinct from `CloudWorker`. | **STAYS** |
| `SiteType::Container` | Also covers Docker hosts and Kubernetes clusters on customer VMs — `Server::siteType()` returns it for `isDockerHost()`/`isKubernetesCluster()`. Only *creation* was Cloud-only. | **STAYS** |

Genuinely going, despite the shared prefix: `CloudWorker` (docblock: *"A background
process attached to a Cloud container Site… on DigitalOcean App Platform each
CloudWorker becomes a `workers` component in the app spec"*), and `CloudBucket` /
`CloudBucketSite` (attached to Cloud sites, still unprovisioned — the model says
actual bucket creation *"happens in a follow-up PR"*).

Serverless asset delivery goes too. CLAUDE.md describes it as *"published
front-end asset delivery"*, which reads like it might serve ordinary sites — it
does not. Every entry point is serverless-scoped: `ServerlessAssetController`,
`ServerlessFunctionProxyController`, and `DigitalOceanFunctionsArtifactBuilder`
(which publishes a *function* site's built assets so the CDN serves static while
the function serves the API).

### The one thing that must be rescued, not deleted

Three jobs sit inside `app/Modules/Edge/Jobs/` but belong to the VM L7 proxy:

- `AddEdgeProxyJob` — 12 consumers outside the module (`Models/Server`,
  `Jobs/Concerns/BuildsWebserverInstallScripts`, `Services/Servers/TraefikStaticConfigOptions`,
  `EnvoyStaticConfigOptions`, `LiveState/TraefikLiveStateProbe`,
  `WebserverStatsEndpointTemplates`, `Console/Commands/BackfillWebserverStatsEndpointsCommand`, …)
- `RemoveEdgeProxyJob`
- `ApplyEdgeBackendConfigsJob`

**Phase 0 moves these to `app/Jobs/` before anything is deleted.** Deleting
`app/Modules/Edge` first would break webserver switching, Traefik/Envoy config,
and the server workspace.

---

## 2. Phases

Each phase is one commit and leaves the tree parseable. Run
`composer analyse` at the end of every phase; `php artisan route:list` after 1
and 3; the full check runs in Phase 9.

### Phase 0 — rescue the L7 proxy jobs
Move to `app/Jobs/`, renamespace `App\Modules\Edge\Jobs` → `App\Jobs`, update the
12 consumers + `tests/Feature/EdgeProxyTest.php`, `tests/Feature/WebserverSwitchTest.php`,
`tests/Unit/Services/Servers/ServerRemoteAccessLoggerTest.php`.
Nothing is deleted in this phase. **3 files moved, ~15 edited.**

### Phase 1 — routes, providers, nav, feature flags
The user-visible amputation. After this the products are unreachable even though
the code still exists, so the rest is dead-code removal.

- `routes/web.php` — ~117 matching lines; `routes/api.php` — ~64
- `bootstrap/providers.php` — drop `CloudServiceProvider`, `EdgeServiceProvider`,
  `ServerlessServiceProvider` (+3 `use` lines)
- `bootstrap/app.php` — `SkipSessionCookiesForServerlessAssets` middleware
- `config/features.php` — `surface.cloud` / `surface.edge` / `surface.serverless`,
  `global.edge_delivery_enabled`. **Keep** `edge_proxy` (line 219 — the L7 proxy flag)
- nav: `components/compute-index-nav.blade.php`, `site-header.blade.php` (17 lines),
  `organization-shell.blade.php`, `settings-nav.blade.php`, `breadcrumb-trail.blade.php`
- `app/Livewire/Concerns/RunsCommandPaletteActions.php`, `app/Support/SiteSettingsSidebar.php`
- `app/Console/Scheduling/DplySchedule.php` — scheduled Edge/Cloud/Serverless commands

### Phase 2 — shell Livewire + views
- delete `app/Livewire/Cloud/` (10), `app/Livewire/Concerns/Edge/` (11),
  `app/Livewire/Sites/Edge/` (25)
- delete `app/Livewire/Concerns/{ManagesEdgeDeploymentLifecycle,ManagesEdgeSite,ManagesEdgeSiteProvisioning,ManagesServerlessRuntime,ManagesContainerSite}.php`
- delete `app/Livewire/Sites/{EdgeDeploymentDetail,EdgePreviewComments,EdgeSettings,ServerlessRouting,Workers}.php`
  (`Sites/Workers.php` is serverless — it imports `Modules\Serverless\Services\SiteWorkerRegistry`)
- delete `app/Livewire/Forms/{EdgeBuildSettingsForm,EdgeCreateForm}.php`,
  `app/Livewire/Sites/Concerns/ManagesSiteCreateFunctions.php`
- **edit, don't delete:** `app/Livewire/Sites/Resources.php` (mixes `CloudDatabase`
  + `WorkerPool`, which stay, with `CloudWorker` + Cloud jobs, which go),
  `Sites/{Monitor,Schedule}.php`, `Sites/Concerns/{ManagesSiteProvisioning,ManagesSiteDomainsRouting}.php`,
  `Livewire/Concerns/ManagesProviderCredentials.php`, `Livewire/Infrastructure/Index.php`
- delete view dirs: `livewire/cloud` (17), `livewire/edge` (8), `livewire/serverless` (16),
  `livewire/sites/edge` (29), `livewire/sites/serverless-routing` (5),
  `livewire/sites/partials/edge` (36) — **111 blades**
- delete `components/{cloud,edge,serverless}-index-page.blade.php`,
  `{cloud,serverless}-starter.blade.php`, `edge-yaml-{advanced,example}.blade.php`,
  `components/partials/{cloud,edge,serverless}-index-card.blade.php`

### Phase 3 — the three modules
`rm -rf app/Modules/{Cloud,Edge,Serverless}` — **366 files** after Phase 0's rescue.
Plus `app/Contracts/{CloudBackend,EdgeBackend}.php` and `app/Support/Cloud/{CloudIndexRow,ContainerActivityTimeline}.php`,
`app/Support/Edge/`, `app/Support/EdgeSiteNotificationKeys.php`, `app/Support/Serverless/`,
`app/Support/Sites/{EdgeProvisioningViewData,EdgeSiteViewData}.php`.

### Phase 4 — Deploy module's serverless half
40 files under `app/Modules/Deploy/Services/` — every `Serverless*`, every
`*Functions*`, `AwsLambdaFunctionDeployer`, and all of `ServerlessProviders/`
(Aws, Cloudflare, DigitalOcean, Netlify, Stub, Vercel).
**Edit** `Modules/Deploy/Jobs/RunSiteDeploymentJob.php` to drop the serverless branch.
Delete `resources/serverless/` and `config/serverless/`.

### Phase 5 — kernel models, jobs, services, config
- models (**19**): `CloudBucket`, `CloudBucketSite`, `CloudDeployTask`,
  `CloudDeployTaskRun`, `CloudWorker`, `EdgeAccessLog`, `EdgeDeployHook`,
  `EdgeDeployment`, `EdgeDeployReplay`, `EdgePerformanceHourly`,
  `EdgePreviewComment`, `EdgePreviewReviewApproval`, `EdgeSiteAccessRule`,
  `EdgeSiteEnvVar`, `EdgeSiteMember`, `EdgeUsageSnapshot`, `EdgeWebVital`,
  `FunctionAction`, `ServerlessUsageSnapshot`
  — **not** `CloudDatabase*`
- `database/factories/CloudWorkerFactory.php` — **not** `CloudDatabaseFactory`
- `app/Models/Concerns/Site/{ManagesEdgeHosting,ManagesServerless}.php` + their two
  `use` lines in `Site.php`; prune `Site::$casts`/scopes that reference the traits
- `app/Models/Server.php` — drop `HOST_KIND_DPLY_CLOUD`, `HOST_KIND_DPLY_EDGE`,
  `HOST_KIND_DIGITALOCEAN_FUNCTIONS`, `HOST_KIND_DIGITALOCEAN_APP_PLATFORM`,
  `HOST_KIND_AWS_LAMBDA`, `HOST_KIND_AWS_APP_RUNNER` and their `hostKinds()` entries.
  **Keep** `VM`, `DOCKER`, `KUBERNETES`
- `app/Enums/QuotaSurface.php` — drop `Edge`, `Cloud`, `Serverless`; keep `Site`.
  Rewrite the class docblock, which currently explains the three-way split
- controllers: `CloudDeployWebhookController`, `GithubCloudWebhookController`,
  `FunctionLogIngestController`, `ServerlessQueueWakeController`,
  `ServerlessWorkspaceController`; **edit** `Api/CapabilitiesApiController`,
  `Api/SiteEnvApiController`
- jobs/commands: `app/Jobs/PollCloudStatusJob.php`; **edit**
  `app/Jobs/DetectRepositoryRuntimeJob.php`, `app/Console/Commands/{DplyRuntimeCheckCommand,OpsSummaryCommand,PruneLocalWorkspaceArtifactsCommand}.php`
- services: `app/Services/Sites/{DigitalOceanFunctionsSiteProvisioner,Clone/ServerlessSiteCloneStrategy}.php`,
  `app/Services/Concerns/ManagesDoFunctionsDatabases.php`,
  `app/Services/DeployContract/Checks/{CloudOriginHealthCheck,EdgeDeployReplayPassCheck,EdgeEnvKeysSubsetCheck,EdgeHybridOriginHealthCheck,EdgePreviewLiveDeploymentCheck,EdgePreviewReviewReadyCheck}.php`
  + their `config/deploy/contract.php` entries
- `app/Mcp/Concerns/ResolvesDplyContext.php` line 113
- config: `config/product/edge.php`; prune `filesystems.php` (asset disk),
  `notifications/events.php`, `product/{cli,console_actions,docs,contextual-docs*,api_token_permissions,object_storage}.php`

### Phase 6 — billing
Delete 11 `app/Modules/Billing/Services/` calculators (`CloudResourceCostCalculator`,
`Edge*`, `Serverless*`). Prune `edge_cents` / `cloud_cents` / `serverless_cents`
and the surrounding rate tables from `config/product/subscription.php`, and the
meter registrations in the Billing service provider.

**Flagging for your call:** if any org has an active Stripe subscription carrying
these line items, deleting the calculators stops metering but does not stop
billing. That is a Stripe-side change, out of scope here — say the word and I'll
list the affected subscription items instead of guessing.

### Phase 7 — tidy
Move `app/Support/Cloud/{AzureAccessToken,GcpAccessToken,OciRequestSigner}.php` to
`app/Support/Providers/`, then `app/Support/Cloud/` is empty and goes.

### Phase 8 — tests
268 test files match by name; 167 reference the three namespaces.
- delete whole dirs: `tests/Feature/Serverless` (21), `tests/Unit/Support/Edge` (16),
  `tests/Unit/Services/Edge` (12+2), `tests/Feature/Livewire/Serverless` (11),
  `tests/Unit/Serverless` (8)
- **keep and repoint:** `tests/Feature/EdgeProxyTest.php`,
  `tests/Feature/WebserverSwitchTest.php`,
  `tests/Unit/Services/Servers/ServerRemoteAccessLoggerTest.php` (L7 proxy, Phase 0),
  and the `CloudDatabase` tests under `tests/Unit/Services/Billing`
- `tests/Unit/ModuleBoundaryTest.php` — drop every `BASELINE` entry naming
  Cloud/Edge/Serverless. The companion stale-entry test fails if they're left
- `tests/Arch/ArchTest.php` — prune matching `ignoring()` entries
- `tests/Pest.php` — remove `cloud`, `edge`, `serverless` from the group map
- `tests/Feature/LivewireAliasGuardTest.php` — drop aliases for deleted components
- `phpunit.xml` — check the `<source>` excludes

### Phase 9 — docs
- `CLAUDE.md` — remove Cloud / Edge / Serverless rows from the module map, and the
  Serverless mention in the Database row
- `AGENTS.md`
- delete `docs/adr/{edge-product-boundary,serverless-asset-delivery}.md`; leave a
  line in `docs/adr/modular-monolith-structure.md` recording the extraction
- `deploy/DO_MIGRATION.md`
- **leave `content/blog/*` alone** — 5 posts are published build-in-public history,
  not code references
- delete this file

Then: `composer analyse`, `composer test`, `composer test:arch`.

---

## 3. Totals

| | files |
|---|---|
| deleted | ~800 (369 modules, 111 blades, 19 models, ~200 tests, rest kernel/shell) |
| edited | ~90 |
| moved | 6 (3 proxy jobs, 3 provider auth helpers) |
| migrations touched | **0** |

## 4. Open questions

1. **Does dply still need to reach the new app?** This plan assumes a clean
   amputation — no links out, no API client, no SSO handoff. If existing customers
   have Edge/Cloud/Serverless sites, every workspace tab and index row simply
   disappears with no forwarding. If you want a "this product moved →" stub for
   affected orgs, say so and I'll add it as a Phase 10 rather than retrofitting.
2. **Stripe line items** — see Phase 6.
3. **`sites.edge_backend` / `serverless_backend` / `container_backend`** stay
   populated and unread, per the code-only decision. `Site::$casts` entries for
   them can stay (harmless) or go; I'll leave them unless you'd rather they go.
