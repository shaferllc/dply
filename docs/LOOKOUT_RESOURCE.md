---
title: Lookout one-click error-tracking resource
status: built
---

> **Status:** both account models are built. `LOOKOUT_ACCOUNT_MODEL=byo`
> (default) uses the customer's own token; `=managed` uses the dply service
> token against Lookout's `POST /api/provision`. The org picker reads
> `GET /api/v1/me`. See "Lookout side" below for what shipped.

# Lookout one-click resource

Goal: a dply user clicks **Resources → Error tracking → Lookout**, dply
auto-creates a Lookout project on [uselookout.app](https://uselookout.app),
installs the `lookout/tracing` package on the box, and injects `LOOKOUT_DSN` so
the deployed app reports to Lookout with zero manual `.env` editing.

Lookout (the SaaS, code at `~/Projects/Apps/lookout`) is a Sentry-style error
tracker. dply already has an `error_tracking` SiteBinding (Sentry / Bugsnag /
Flare). **Lookout is a new provider inside that binding** — not a new binding
type — plus a one-click *provision* path that calls Lookout's API.

---

## What Lookout already ships (no new work)

- `POST /api/v1/projects` (Sanctum-authed) creates a project, returns its
  `api_key` — `app/Http/Controllers/Api/V1/ProjectController.php:50`.
- `Project::ingestDsn()` builds `https://<api_key>@<host>` —
  `app/Models/Project.php:380`. `api_key` is `Str::random(64)`, plaintext,
  auto-minted in the model's `creating` hook (`Project.php:340`).
- Ingest auth: `ValidateIngestApiKey` looks up `Project::where('api_key', …)` —
  `app/Http/Middleware/ValidateIngestApiKey.php`.
- The SDK: Composer package **`lookout/tracing`** (`packages/lookout-tracing`).
  Reads `LOOKOUT_DSN` (parses to api_key + base_uri), or
  `LOOKOUT_INGEST_URL` + `LOOKOUT_PROJECT_API_KEY`. `LOOKOUT_LARAVEL=true`
  turns on the quick-start (errors + tracing + logs). Config:
  `packages/lookout-tracing/src/Laravel/config/lookout-tracing.php`.
- Static-service-token auth template: `ValidateFleetOperatorToken`
  (`fleet.operator` middleware, `FLEET_OPERATOR_TOKEN`).

**The env we inject is always `LOOKOUT_DSN` (+ `LOOKOUT_LARAVEL=true`).**

---

## dply side — identical under either account model

### 1. `error_tracking` binding gains a `lookout` provider
`app/Modules/Deploy/Services/Concerns/ManagesErrorTrackingBindings.php`:
- `ERROR_TRACKING_PROVIDERS` += `'lookout'`.
- `ERROR_TRACKING_PACKAGES` += `'lookout' => 'lookout/tracing'`.
- `resolveErrorTrackingCredentials()` — lookout case: `['dsn' => …]` for
  *attach existing*; for *provision* carry `lookout_token` / `lookout_org`
  (BYO model) — see auth models below.
- `validateErrorTrackingCredentials()` — lookout: require a `http(s)` DSN
  (attach path).
- `errorTrackingEnv()` — lookout → `['LOOKOUT_DSN' => …, 'LOOKOUT_LARAVEL' => 'true']`.
- `errorTrackingLabel()` — `'Lookout'`.
- **New** `provisionLookout(Site,$params): SiteBinding` — calls
  `LookoutProvisioner` (below), persists the returned DSN as `injected_env`.

### 2. Wire the provision path
`app/Modules/Deploy/Services/SiteBindingManager.php`:
- `provisionNew()` match: add `'error_tracking' => $this->provisionErrorTracking($site,$params)`
  (dispatch to `provisionLookout` when `provider === 'lookout'`, else fall back
  to attach — today it falls through to `attachExisting`).
- `ownedEnvKeys()` `error_tracking` case: add
  `LOOKOUT_DSN, LOOKOUT_LARAVEL, LOOKOUT_API_KEY, LOOKOUT_INGEST_URL, LOOKOUT_PROJECT_API_KEY`
  so switching providers strips stale Lookout keys.

### 3. New service: `LookoutProvisioner`
`app/Modules/Deploy/Services/LookoutProvisioner.php` — single chokepoint that
calls the Lookout API and returns `['dsn' => string, 'api_key' => string]`.
The auth model (below) only changes the request it makes; callers are unaffected.
Config in `config/services.php`:
```php
'lookout' => [
    'url' => env('LOOKOUT_URL', 'https://uselookout.app'),
    'provision_token' => env('LOOKOUT_PROVISION_TOKEN'), // service-token model only
],
```

### 4. Auto-install `lookout/tracing` on the box (queued SSH)
New `app/Jobs/EnsureSiteComposerPackageJob.php`, a near-clone of
`EnsureSitePhpRedisExtensionJob` (queued, `SshConnectionFactory`, ConsoleEmitter
streaming, idempotent). Body:
```
cd <site path>
composer show lookout/tracing >/dev/null 2>&1 && echo DPLY_HAVE || \
  composer require lookout/tracing --no-interaction --no-scripts && echo DPLY_OK
```
Dispatched from `saveBinding()` after a successful lookout attach/provision,
exactly like the existing `ensurePhpRedisExtension($binding)` call
(`ManagesSiteBindingActions.php:349`). Routes to the `dply-manage` queue. The
app's own `composer install` at the next deploy then picks the package up; this
job just guarantees it's in `composer.json` first so a fresh app works.

### 5. UI
- `resources/views/livewire/sites/settings/partials/environment/resources.blade.php`
  — add Lookout to the error-tracking provider picker; when selected, show a
  **"Create a Lookout project"** (provision) card vs **"Paste an existing DSN"**
  (attach). Modal note: "dply will run `composer require lookout/tracing` on
  this server."
- `app/Livewire/Concerns/ManagesSiteBindingActions.php::updatedBindingForm()`
  `error_tracking` branch (line 687): reset lookout fields
  (`dsn, lookout_token, lookout_org`) on provider switch.
- `app/Livewire/Concerns/BuildsSiteBindingFormDefaults.php::defaultErrorTrackingBindingForm()`
  (line 369): add lookout defaults.

### 6. Tests
Livewire-alias guard already covers the binding; add a feature test for the
provision happy-path (mock `Http::fake()` for the Lookout call) and a unit test
for `errorTrackingEnv('lookout', …)`.

---

## Lookout side — the one open decision (account model)

Both models return the same thing to dply (`api_key` + DSN); they differ only in
*who owns the Lookout project* and *how dply authenticates*.

### Model A — BYO Lookout token (per customer) — DEFAULT, built
The dply user pastes their uselookout.app **API token** (Sanctum) and picks an
org. `LookoutProvisioner::provision()` calls the existing
`POST /api/v1/projects` with `Authorization: Bearer <customer-token>` +
`organization_id`; the store endpoint returns `api_key` (read directly via
`includeApiKey: true` — the model's `$hidden` only affects JSON serialization,
not that explicit payload). Each customer owns their own Lookout data.
- dply: the token can be saved as an `ErrorTrackingCredential` (opt-in) for reuse.
- **Org picker**: `LookoutProvisioner::organizations()` reads the existing
  `GET /api/v1/me` payload (it already returns `organizations`) — no new Lookout
  endpoint. "Load my organizations" in the modal turns the ULID field into a
  select; a single org auto-selects.

### Model B — dply service token (dply-managed) — built, opt-in
Set `LOOKOUT_ACCOUNT_MODEL=managed`. dply holds `LOOKOUT_PROVISION_TOKEN` and
`LookoutProvisioner::provisionManaged()` calls **`POST /api/provision`** on
Lookout (Bearer service token). The customer never needs a Lookout account; the
modal hides the token/org fields and only asks for a project name.
- Lookout (shipped): `ValidateProvisionToken` middleware (`dply.provision`
  alias), `config/dply_provision.php` (`DPLY_PROVISION_TOKEN` +
  `DPLY_PROVISION_ORGANIZATION_ID`), `Api\ProvisionController@store` →
  `POST /api/provision`. Creates the project under the request's
  `organization_id` or the configured default, returns
  `{ api_key, ingest_dsn }`. The org must already exist (no org/billing
  provisioning yet — that's the remaining gap if dply-managed goes live).
- dply: `services.lookout.{account_model,provision_token,managed_organization_id}`.

---

## Build order
1. dply binding scaffolding (§1–2, §5–6) — invariant to the account model.
2. `LookoutProvisioner` + `config/services.php` (§3) — Model A request shape.
3. `EnsureSiteComposerPackageJob` + dispatch (§4).
4. Lookout: org-list convenience endpoint (Model A) — optional.
5. Later: Model B `/api/provision` if dply-managed Lookout is wanted.
