# ADR: `dply init` — site creation from the terminal

Status: proposed (2026-08-21)

## Context

The goal is one command: stand in a folder, type `dply init`, answer as little
as possible, and end on a live URL. Serverless is the first path down.

Two facts in the current codebase stand in the way, and everything below follows
from them.

**Nothing in the HTTP API creates anything.** `routes/api.php` deploys, reads,
reconfigures, invokes and tears down, but there is no endpoint that provisions a
Site — and correspondingly no ability in
`config/product/api_token_permissions.php` that spends money. `dply link`
(`packages/dply-cli/src/link-interactive.mjs`) only attaches a folder to a site
that already exists, and only for two of the four kinds. So `dply init` is not a
matter of wiring up commands that exist; it needs a create surface that does not.

**Serverless can only deploy what it can `git clone`.** `CreateServerlessFunction::handle()`
throws on an empty `repo`; `ServerlessRepositoryCheckout::checkout()` clones into
`storage_path('app/serverless-repositories/…')`; `ServerlessRuntimeDetector` runs
against that clone. There is no upload path anywhere in the module. But "cd into a
folder" describes a local directory, which may have no remote, an unreachable one,
or unpushed work — so a create API alone does not reach the goal.

## Decision

### The source seam

`DigitalOceanFunctionsArtifactBuilder::build()` is git-specific for exactly
twenty-four lines:

```php
// :47-51   the only hard git requirement
$repositoryUrl = trim((string) $site->git_repository_url);
if ($repositoryUrl === '') { throw new \RuntimeException('Choose a repository…'); }

// :55-70   the only git-specific work
$checkout = $this->repositoryCheckout->checkout('build-'.$site->id, $repositoryUrl, …);

// :73 onward — hooks, Bref, detect, build, adapters, zip, publish
$checkout['working_directory']   // ← everything downstream only wants a path
```

Past line 73 nothing knows a clone happened. Git is the first step of the
pipeline, not a thread running through it.

So a `SourceResolver` sits behind that call with two implementations —
`GitSource` (today's checkout, unchanged) and `UploadSource` (extract a tarball
the CLI posted) — both returning the same `{workspace_path, repository_path,
working_directory}` shape. Upload sites therefore inherit framework detection,
the Laravel adapter injection, build hooks, asset publishing, log drains and
rollback with no further work.

Rollback in particular is free: `DigitalOceanFunctionsActionDeployer` keeps an
`artifact_history` of the last `releases_to_keep` **built** zips (default 5, the
same knob VM deploys use), prunes to it via `pruneArtifactsExcept()`, and
`redeployArtifact(Site, $artifactPath)` replays one. That is keyed on the built
artifact, not on where the source came from.

The two sources are equal citizens, chosen by folder state, and the site records
which one it is. What upload cannot have is push-to-deploy:
`SiteDeployWebhookController` fires on a git push, and there is no remote to push
to. An upload site redeploys when `dply deploy` re-tars and re-posts.

### Endpoints

Per-kind, matching how `routes/api.php` is already grouped and how the per-product
ability scopes already work:

| endpoint | notes |
|---|---|
| `POST /api/serverless/sites` | create; `dry_run: true` runs every gate and returns the detected plan without side effects |
| `POST /api/serverless/sites/{site}/source` | tarball ingress; the same endpoint a redeploy uses |
| `DELETE /api/serverless/sites/{site}` | **undo only** — refuses once any deploy has succeeded |
| `GET /api/capabilities` | enabled surfaces, creatable kinds, region list, managed availability, upload size cap |

`dry_run` is the load-bearing one. It runs before any question is asked, so a
missing DigitalOcean credential or a paused trial surfaces *before* the user
answers anything, and it returns both blockers and the real detected plan —
cloning a preview workspace for git sources exactly as
`DetectsRepositoryRuntime::runServerlessDetection()` already does for the web
form, or detecting on the posted tarball for upload sources. One detector,
server-side, identical to what deploy will decide. Nothing is reimplemented in
JavaScript.

`GET /api/capabilities` exists because the CLI cannot hardcode any of it.
`config/features.php` has `'edge' => false` on this instance, the region list
lives in `Create::render()`, and dply is self-hostable with one CLI able to
address several instances. A 404 on this endpoint means an instance older than
the feature, and the CLI says so by name rather than failing obscurely.

Delete is deliberately the narrowest thing that serves init's undo: it wraps the
existing `DeleteServerlessFunction` action but refuses any site that ever
deployed successfully, so no name-typing ceremony is needed and a leaked token
cannot destroy production. `dply serverless delete` for a live function stays a
separate decision with its own confirmation design.

### `ServerlessCreateGate`

Today's create gates are scattered across four places — route middleware, an
`abort_unless` in `Create::mount()`, three checks in `Create::create()`, and two
more inside `CreateServerlessFunction::handle()`. `dry_run` must reproduce all of
them exactly and cannot call a Livewire component, since modules must never
depend on the shell.

So they extract into one module class returning a typed blocker
(`{code, message, resolve_url}`): `dry_run` serialises it, the API create calls it
before handling, and `Create::create()` renders it as the inline error it already
shows. The blocker contract the CLI needs falls out of it rather than being
invented alongside it.

The gate also fixes a live bug. `quotaUsageBySurface()` classifies every site
through `QuotaSurface::forSite()`, so serverless sites already tally into the
**Serverless** bucket — but the create path asks `canCreateSite()` →
`canCreateOnSurface(Site)`, which counts only machine sites. Functions never
increment the number their own gate checks, so `max_functions` is currently
unenforced entirely. The gate uses `QuotaSurface::Serverless`, and both the
wizard and the API get the correction, because shipping a public endpoint on top
of an unenforced ceiling would cement it.

### The flow

```
dply init
 ├─ no token?          → device-flow login inline, resume in place
 ├─ linked already?    → status + [deploy / open / create another]; stale link → relink or recreate
 ├─ kind menu          → all 4, ranked by a local heuristic, each ineligible one carrying a
 │                       reason string. Kinds without an endpoint open the prefilled web wizard
 ├─ dry_run            → blockers + detected plan. missing scope → auth refresh inline
 ├─ source             → remote reachable = git · none/unclonable = upload
 │                       dirty or ahead → [push and deploy] / [deploy origin] / [upload this folder]
 │                       monorepo → repository_subdirectory from `git rev-parse --show-prefix`
 │                       nothing deployable → offer to write a hello-world, deploy as upload
 ├─ .env present?      → prompt listing key names only (never values)
 ├─ delivery mode      → prompt only when managed and BYO are both genuinely possible
 ├─ summary            → [Y/n/edit], every field flag-addressable, --yes for headless
 ├─ create             → write .dply/site.json immediately, before the deploy
 ├─ engines            → Horizon/scheduler detected → offer to enable workers / schedule tick
 └─ follow             → provisioning, then the deploy, then a verified live URL
     └─ failed         → phase + log tail, [retry / Journey / delete]
```

Three points in that tree carry most of the design weight.

**The menu never hides a path.** All four kinds are listed even when the folder
suits none of them, each ineligible one carrying a one-line reason ("Edge — no
static build output found"). Detection therefore produces a reason string, not a
boolean. Hiding an option makes the CLI look broken when detection is wrong;
naming the reason teaches the product model instead. The menu's ranking is a
local heuristic and is only ordering — nothing depends on it being right, because
`dry_run` and the deploy-time detector are authoritative.

**The dirty tree is named, not papered over.** In a folder with a remote, dply
deploys `origin/<branch>` — not what is on screen. On a first run that is a coin
flip, and a user who watches a deploy succeed and then sees stale code has been
handed the most confusing possible first impression. `git status --porcelain`,
`git rev-list @{u}..HEAD --count` and a missing upstream tell the CLI exactly
what will happen, so it says so and offers three exits. `dply deploy` inherits a
lighter version of this — a confirm on unpushed commits, a dim note for
uncommitted files — because it is the daily command and most trees have a stray
edit in them.

**The summary is one prompt, not an interrogation.** The web wizard has ten
fields; init infers nine of them (name from the folder basename, repo/branch/ref
from git, `runtime: 'auto'` because deploy-time detection overrides the stored
value anyway, delivery mode and credential from the same preference order
`Create::mount()` uses). Region is the only field with no defensible inference,
so it appears in the summary with the wizard's `nyc1` default. The summary also
shows quota position ("function 3 of 25 on your plan") — a fact `dry_run` already
computed — but no dollar figure, because serverless billing is usage-based and a
pre-deploy estimate would be invented.

A generic folder basename (`api`, `web`, `app`, `src`, `backend`, `frontend`,
`server`, `functions`) is qualified with its parent directory, so `~/work/beta/api`
proposes `beta-api`. Duplicate names are *warned about but never rejected* —
`mintServerlessProxySlug()` is `{name-slug}-{8-char sha1 of site id}` precisely so
two sites named the same never collide.

### Following, and the URL at the end

The wait spans two phases with different observability: `ProvisionServerlessHostJob`
creates the DigitalOcean namespace with no `SiteDeployment` row in existence (state
lives on the Server as `STATUS_ERROR` / `meta.provision_error`), and only then does
`deployConfiguredFunctions()` dispatch `RunSiteDeploymentJob`. So
`GET /api/serverless/sites/{site}` gains provision status plus a redacted provision
error, init polls it until the namespace is ready, then hands off to the existing
`followSiteDeployment()`. A bad DigitalOcean credential is the single most likely
first-run failure and it surfaces in phase one — following only the deployment
would render it as an infinite wait.

On success init prints `/fn/{slug}` — live the instant the action deploys — and
HEADs it first, so "deployed" means "answering". The friendly hostname
`{slug}-{idHash8}.{apex}` is listed below with its real state, because
`ServerlessFunctionDnsProvisioner::provision()` can return `skipped` for an
unconfigured zone or a missing token, and even `ready` is dply's record rather
than the user's resolver. Handing someone a URL that NXDOMAINs ten seconds after
a success message is the one outcome worth designing against.

Ctrl-C detaches and says how to re-attach; it does not cancel. The deploy is
running server-side and the link file is already written, so nothing is orphaned
— and cancelling is the more destructive reading of an ambiguous keystroke.

### Where configuration lives

The server is the source of truth. init sends the detected values once; from then
on `meta.serverless` owns them and the workspace tabs edit them.
`.dply/site.json` stays what it is today — a link — gaining only `kind` and
`source_kind`.

`dply pull` may materialise a `dply.json` for people who want the config in the
repo, and `dply push` applies it. **Deploys never read that file.** Exactly one
writer exists at any moment, so the UI tabs and the file cannot silently
disagree, and `push` can refuse on drift using the revision primitive already in
the deployer. The cost is stated loudly in the CLI: editing the file and
deploying does nothing until you push.

### Secrets and trust boundaries

Three boundaries, none of them lazy.

**`.env` never rides the tarball.** `Site::$casts` has
`'env_file_content' => 'encrypted'`, so a plaintext `.env` sitting in a temp
tarball would be below the standard the codebase sets for exactly that data. On
approval — prompted with **key names only, never values** — the CLI sends the
contents as a create field that writes straight to the encrypted column.
`ServerlessEnvironmentPreparer` then simply skips its repo-seeding branch,
because managed env is already populated. The create payload carries secrets, so
it is excluded from request logging and never echoed in an error.

**Clone URLs are validated.** `GitCloneUrl::normalize()` performs no host
validation: `file://`, `git://10.0.0.5/…` and link-local addresses all reach
`git clone`, and clone stderr flows back through the detection error path. This
is pre-existing — any logged-in user can do it through the web wizard — but a
`serverless.create` token is machine-usable and lives in CI, and `dry_run` makes
the clone cheap and repeatable. Validation goes into the shared helper (require
http(s)/ssh, reject `file://`/`git://`, reject private and link-local addresses,
operator-configurable allowlist for internal git hosts) so the wizard is fixed
too.

**Archives are validated where it counts.** Server-side extraction rejects
absolute paths, any `..` component, symlinks, hardlinks and device entries, and
caps entry count and uncompressed size. The CLI passes `-h` to dereference
symlinks into real files so ordinary repos still work. The client is a
convenience, not a control — the endpoint accepts archives from anything holding
a token.

The tarball itself lives in `storage_path` with a TTL sweep for abandoned
`dry_run` stashes, matching the pipeline's existing assumption that checkouts,
artifacts and workers are co-located. *ponytail: co-located web and worker; needs
shared storage the day workers move to separate boxes.*

Contents are `git ls-files -co --exclude-standard` inside a repo and a built-in
ignore list outside one — never `.git`, never `node_modules`/`vendor`/`.venv`,
since the build runs server-side from the detected `build_command`. Oversized
folders fail client-side with the largest offenders named and an `--exclude`
flag, rather than as an opaque 413 after a two-minute upload. The CLI shells out
to system `tar`, preserving the package's deliberate zero-dependency posture.

### Authority, rate, and record

A new **`serverless.create`** ability in the existing `serverless` scope group.
"Reconfigure this function" and "provision billable infrastructure in my org" are
different authorities, and `grantableAbilities()` can cap the latter by org role
— a deployer token should not be able to spend money. Existing sessions must
`dply auth refresh` once; `dry_run` returns that as a typed blocker and init runs
the refresh inline, the same way it runs login.

Create and delete get their own limiter keyed on **organization**, not token.
Quota bounds real growth, but it does not bound churn: failed creates consume no
quota while still calling DigitalOcean, and a create/delete loop stays under the
ceiling indefinitely. The serverless prefix's existing `throttle:edge-api` is
sized for log polling and is the wrong shape for provisioning.

Create carries an **`Idempotency-Key`**, stored against the resulting site id and
replayed for a window. A dropped response on a money-spending endpoint otherwise
means a retry provisions a second namespace, and the orphan has no
`.dply/site.json` pointing at it — the user finds it on a bill.

Creation is audited (`serverless.function_created`, carrying token id,
`source_kind` and `delivery_mode`) alongside the deletion audit that already
exists, and **token-created** sites additionally raise an `account.*`
notification to org admins. The `account.*` prefix is the existing escape hatch
for events with no server/site subscription target — `site.created` would be
broken by construction, since nobody can subscribe to a site that does not exist
yet. A human who just clicked Create in the UI is not notified.

The whole CLI-create surface sits behind its own feature flag, checked inside
the gate — so turning it off makes `dry_run` report a typed blocker and
`/api/capabilities` report the kind uncreatable, and the CLI needs no
special-casing.

The flag defaults **on**. It was originally specified default-off so the surface
could ship dark, but shipping dark and shipping off-by-default are different
things: the first run of `dply init` on a real instance then said "serverless
sites cannot be created from the CLI yet" — technically about the flag, read by
a human as "not built". The flag stays as the kill switch for an operator who
wants the product without the write endpoint; it is not the rollout gate.

`/api/capabilities` therefore reports `cli_create_supported` (this dply version
has the endpoint) alongside `cli_create` (it is switched on here), plus the env
var that flips it. Without both, the CLI cannot tell "not built yet" from "an
operator has not enabled it" — and only the second is something the person at
the terminal can fix.

### `init` and `link`

They coexist. `init` creates, links and deploys; `link` attaches a folder to a
site that already exists. `link` is repointed at `site-index.mjs` — "one list of
sites, whatever kind they are" — which already merges `/sites` with the per-kind
endpoints. It currently hand-rolls two fetches and so cannot see serverless or
cloud sites at all, which init would otherwise make conspicuous.

## Boundaries

- **Serverless, Cloud, and VM.** `edge` appears in the menu and opens the
  prefilled web wizard until it gets its own endpoint. The menu shape does not
  change as kinds land; each simply stops opening a browser — the CLI routes on
  `kinds.<kind>.cli_create` from `/api/capabilities` rather than on a hardcoded
  list.
- **No infrastructure-as-code.** `dply.json` is a client-side artifact. Deploys
  read the server.
- **No push-to-deploy for upload sites.** Structural, not an omission.
- **Push-to-deploy is enabled, not merely enabled-able.** `CreateServerlessFunction`
  mints `webhook_secret` but never registers a hook — that only happens via
  `enableQuickDeploy()` on the shell's Repository tab. init calls
  `RepositoryWebhookProvisioner` (kernel, so no boundary violation) after create,
  reports it in the summary, and treats failure as a warning rather than a failed
  init.
- **No provisioning end-to-end test.** That path needs DigitalOcean.

## Cloud

The second kind, added after serverless landed. Same shape deliberately — own
`cloud.create` ability, own `surface.cloud_cli_create` flag, the shared
per-organization create limiter (renamed `site-create`, since it is no longer
serverless-specific), `Idempotency-Key`, `dry_run`, and typed blockers — so a
caller that has learned one create endpoint has learned both.

Two things are genuinely different.

**There is no uploaded-source mode, and there cannot be.** The container backend
clones and builds the repository itself; dply never holds the source. So the
`SourceResolver` seam has no Cloud counterpart, and a folder with no reachable
remote simply cannot become a cloud app. `/api/capabilities` reports this as
`kinds.cloud.requires_git`, and `dply init` says so and points at serverless
rather than inventing a path that would fail at provision time.

**The gate adds a check rather than only moving them.** The Cloud wizard checked
quota but never the billing pause, so an organization whose trial had lapsed
could provision container infrastructure it was not allowed to deploy to — the
same hole the Serverless wizard closes with `canDeploy()`, with the same
consequence: infrastructure standing, billing, and unable to go live.
`CloudCreateGate` closes it for both surfaces.

`SiteCreateBlocker` moved from the Serverless module into the kernel when Cloud
needed it. More than one product creates sites, and none of them should depend
on another to describe why it could not.

One gap versus the wizard: the web form pre-flights the app spec against the
provider (`/apps/propose`) before creating a Site row, and that logic lives in
the shell. Over HTTP a spec rejection therefore surfaces on the real create, as
a `spec_rejected` blocker, rather than on the dry run.

## Server sites (vm)

The third kind. Same contract again — `sites.create` ability,
`surface.vm_cli_create` flag, shared create limiter, `dry_run`, typed blockers,
idempotency — with two differences that come from what a BYO site *is*.

**A site has to live somewhere.** `dply init --kind vm` lists ready servers and
asks; it does not provision one. Buying a machine is a larger decision than a
folder should trigger, and an org with no servers is pointed at the dashboard or
at serverless, which needs no server at all. `/api/capabilities` reports this as
`kinds.vm.requires_server`.

**It covers ordinary webserver hosts only.** Unlike Serverless and Cloud, VM
creation had no reusable action to call: it lives inline in a ~500-line Livewire
concern that branches on host capabilities, allocating internal ports, building
container runtime targets, and assembling per-host meta. `CreateVmSite` extracts
the *ordinary* path — where that meta is empty and the sequence is Site row →
slug → web process command → pipeline defaults → optional domain → provision —
and refuses a functions, Docker, Kubernetes, or headless host with a
`host_unsupported` blocker rather than guessing at configuration it cannot see.

That leaves the one place this design knowingly has two creation paths.
`VmCreateGate` is **not** yet shared with the wizard, because extracting the
wizard's create is a change to the app's oldest flow and deserves its own
review. Until then the two agree by both deferring to the same
`Organization`/`SitePolicy` quota and role methods, and the CLI path is the
stricter of the two. Unifying them is the follow-up: the wizard adopting
`CreateVmSite` for ordinary hosts, then the exotic hosts moving into it.

### An ordering bug the VM tests caught

The idempotency replay was checked *after* the gate in all three controllers.
That is wrong: the site a key already created consumes quota, so a retry ran the
gate first and was told the quota was full instead of being handed back the site
it had already made — precisely the case the key exists for. It only surfaced on
VM because the Free plan's site ceiling is 1. Fixed in all three: the replay is
now checked before the gate, and skipped entirely for a dry run.

## Slices

**One — git source, end to end.** Gate class, `/api/capabilities`, `POST` create
with `dry_run`, the quota fix, clone-URL validation, the scope, the flag, the
limiter, idempotency, audit and notification, and the CLI command. Reuses the
entire existing pipeline: no `SourceResolver`, no ingress, no tarball storage.
Proves the gate, blocker and follow contracts against real users. Ships with Pest
tests per endpoint, `ServerlessCreateGate` unit tests, node tests for init's pure
helpers (name qualification, kind ranking, tarball filter, dirty-tree
classification), and updates to `docs/HTTP_API.md`, `docs/ACCOUNT_CLI.md` and the
CLI README.

Until slice two lands, a folder with no remote cannot init — which is the
headline scenario, so slice two is not optional.

**Two — upload source.** `SourceResolver`, the ingress endpoint, tarball storage
and sweep, size cap, extraction validation, `.env` transport, hello-world
scaffolding, and `dply deploy` re-uploading for upload sites. Additive; slice one
needs no rework.

## Consequences

- The API gains its first ability that provisions, and its first destructive
  endpoint. Both are deliberately narrow: capped by org role, bounded by a real
  quota, rate-limited per org, idempotent, audited, notified, and behind a flag.
- Two supported sources forever. Rollback, redeploy and the Repository tab each
  need an upload story; rollback already has one, the other two are CLI-side.
- The gate extraction touches a working wizard, so its current error behaviour is
  the first test to write.
- `max_functions` starts being enforced. Any org already past it stops being able
  to create — the ceiling working, but it will read as a regression, so check the
  current maximum before shipping.
- Detection stays server-side and single-implementation. The CLI never grows a
  second copy of the ladder, at the cost of a network round trip before the
  summary.

## Open

- The `edit` sub-flow: which fields it walks, and whether changing source or
  branch re-runs `dry_run` (it should — detection depends on both).
- A `.dply/site.json` pointing at a site the current token's org does not own
  should say "linked to a site in another organization", not surface a 403.
- Multi-function repos (`project.yml`): `multiActionDeployer` already handles
  siblings, so init creates the primary and reports how many actions deployed.
- Ctrl-C's message promises a cancel verb; `Journey::cancelDeploy()` is UI-only,
  so it needs a name and an endpoint in slice one or the message needs rewording.
