# Choose-an-Application post-creation flow

Status: **Built behind `choose_app_enabled` flag (default off)**. Owner: Tom.
Grilled + first build: 2026-05-28.

## What shipped (v1)

- `config/dply.php` → `choose_app_enabled` (env `DPLY_CHOOSE_APP_ENABLED`).
- `Site::STATUS_AWAITING_APP` + `isAwaitingApp()` / `needsAppChoice()` /
  `canRechooseApp()` helpers.
- `App\Services\Sites\AppCatalog` — VM tile registry (git, wordpress, laravel,
  statamic, craft, symfony, static, blank).
- `Sites\Create::storeBare()` + `_create-bare.blade.php` + a flag-gated branch
  in `create.blade.php` — bare-create (name + domain) on VM hosts.
- `Sites\ChooseApp` Livewire component + `choose-app.blade.php` +
  `sites.choose-app` route — pick → configure → run.
- `SiteWorkspaceController` redirects `awaiting_app` (non-skipped) sites to the
  picker.

### Deviations from the grilled spec (accept or tell me to change)

1. **Placeholder vhost deferred.** Bare-create makes the Site row in
   `awaiting_app` but does NOT provision a vhost yet — provisioning happens
   when the app is chosen. (A naive static placeholder risked colliding with
   the `awaiting_app` status via reachability timeouts → `STATUS_ERROR`.) The
   normal provisioner already serves a default page for fresh sites, so
   **"Blank / Skip" does provision a live default page** and stays
   re-choosable — that's where the "placeholder" behavior actually lives now.
2. ~~Statamic / Craft / Symfony are presets, not real installers.~~ **Resolved:**
   a generic recipe-driven `ScaffoldComposerPipeline` (+ `RunComposerScaffoldJob`)
   now installs Composer apps from a catalog `recipe`. Auto-installs today:
   **WordPress, Laravel** (dedicated pipelines) and **Statamic, Symfony, Craft,
   Drupal** (generic pipeline). Adding more = a catalog entry with a `recipe`
   (package / needs_db / env / migrate / finish_in_browser). Craft + Drupal
   install code + DB and finish their setup wizard in the browser.

   Git/static tiles can pull from a **connected git provider** (dropdown of the
   user's linked accounts + repos via `SourceControlRepositoryBrowser`) or a
   pasted URL.
3. **DB auto-creation** rides on the existing WordPress/Laravel scaffold
   pipelines (which already provision their DB). Import/preset/static/blank
   create no DB — matching "DB only when the app needs it".

## Problem

You can create a site today without committing to what actually runs on it. The
import/scaffold choice is buried inside the create wizard, before the Site row
exists, and a freshly-created site can sit in a half-defined state. We want to
**force an explicit "what app goes here?" decision** as a distinct, unskippable
step that happens *after* the bare site exists.

## Scope

**VM hosts only for now** (`Server` with `meta['host_kind'] === 'vm'`,
`HOST_KIND_VM`). Container / Kubernetes / DO Functions / App Platform / Lambda /
App Runner keep their existing dedicated creation flows untouched. A
host-kind-aware "front door" that also fronts those flows was discussed and
**shelved until the VM flow is proven**.

Entire feature is **flag-gated** behind a new `choose_app` flag; the current
import/scaffold wizard remains the fallback while the flag is off.

## Core model

1. **Bare-create.** The create wizard collects only **domain (+ alias domains)
   + target server**. No in-wizard import/scaffold mode toggle on the new path.
2. **`awaiting_app` status.** The Site row is created in a new `awaiting_app`
   status with `type` / `runtime` / `document_root` unset.
3. **Placeholder vhost (VM).** On create we immediately provision a vhost that
   serves a static "awaiting app" holding page, so the domain resolves to
   something instantly. No PHP/runtime is committed yet (static nginx).
4. **Redirect to `sites.choose-app`.** Bare-create redirects to a dedicated
   route/Livewire component. Any `awaiting_app`/blank site loaded at
   `sites.show` also redirects there. This same route is the deep-link for
   re-choosing later.

## The step: pick → configure → run

- **Tile picker** of available apps for the host kind, then an **app-specific
  config sub-form**, then a confirm button that runs the install.
- **PHP version is auto-picked** (server default); no version dropdown in v1.
- **Database is auto-provisioned (DB + user) only for apps that need one**
  (WordPress, Laravel, Statamic, or a repo that opts in). Credentials are
  injected into wp-config / `.env`. **Blank/Skip and `awaiting_app` get no
  database.**

## Catalog architecture — data-driven registry + 3 real pipelines

- **First-class installers (real scaffold pipelines):**
  - **WordPress** — reuse `RunWordPressScaffoldJob` / `ScaffoldWordPressPipeline`.
  - **Laravel** — reuse `RunLaravelScaffoldJob` / `ScaffoldLaravelPipeline`.
  - **Statamic** — *new*, small `composer create-project` pipeline.
- **Everything else = config-array catalog entries**, rendered as tiles, that
  either pre-fill the import form (preset) or run a generic
  `composer create-project`. Adding an app later = a config entry, **not new
  code**.
- **VM tiles (initial):** Git repository · WordPress · Laravel · Statamic ·
  Static HTML · Generic PHP starter · Craft / Symfony (presets) · Blank/Skip,
  plus whatever else lands in the registry over time.

Tile kinds:
- **Real installer** — runs a first-class pipeline.
- **Preset** — pre-fills the existing import form (web root, build cmd,
  framework defaults); effectively "Git repo with defaults".
- **Generic composer-create** — parameterized `composer create-project <pkg>`.
- **Static / Blank** — `type=static` default index, or empty PHP site.

Reuse `DetectsRepositoryRuntime` (`app/Livewire/Concerns/DetectsRepositoryRuntime.php`)
verbatim for the Git-repository tile.

## Lifecycle & failures

- Picker stays available while **no real app is installed** — i.e. while
  `awaiting_app` or blank/skip. These are **re-choosable anytime**.
- A **successful real install locks** the gate. Changing the app afterward is a
  future destructive "reset site" feature, out of scope for v1.
- A **failed install does NOT lock**: revert to `awaiting_app`, re-show the
  picker with the **error surfaced + the chosen tile's form pre-filled**. The
  placeholder vhost stays up. User can retry or pick something else.
  (`scaffold_failed` status already exists and can back this.)

## Migration

- New **`choose_app` feature flag** (mirror the `scaffold_v1_enabled` pattern at
  `config/dply.php:170`, env `DPLY_CHOOSE_APP_ENABLED`).
- Flag **on** → bare-create + `sites.choose-app`. Flag **off** → current
  import/scaffold wizard (unchanged fallback).
- Once proven, delete the old in-wizard mode toggle and retire `scaffold_v1`.

## Touch points (existing code to reuse / change)

- `app/Livewire/Sites/Create.php` — split bare-create from app choice; gate on flag.
- `app/Livewire/Forms/SiteCreateForm.php` — strip mode/scaffold fields off the new path.
- New `app/Livewire/Sites/ChooseApp.php` + `resources/views/livewire/sites/choose-app.blade.php`.
- `app/Models/Site.php` — add `awaiting_app` status + helpers; gating in `sites.show`.
- `app/Jobs/RunWordPressScaffoldJob.php`, `app/Jobs/RunLaravelScaffoldJob.php` — reused.
- New `ScaffoldStatamicPipeline` + job.
- New app-catalog config (registry array) — drives tiles + handlers.
- `config/dply.php` — add `choose_app` flag.

## Open / deferred details

- Holding-page content & branding for the placeholder vhost.
- Exact registry schema (per-entry: key, label, icon, kind, pipeline/preset,
  needs_db, default web root, composer package, etc.).
- Statamic pipeline steps.
- Host-kind-aware front door for container/serverless (deferred).
- Post-install "reset site" / change-app flow (deferred).

## Decisions consciously accepted

- **VM-only first** — keeps blast radius small; generalization later.
- **Flag-gated** — reversible; current wizard stays as fallback.
- **DB only when the app needs it** — no throwaway DBs for static/blank.
