# VM site pipeline

The **Pipeline** workspace configures how code moves from Git to live traffic after you connect a repository.

## Tabs

| Tab | Use for |
|-----|---------|
| **Overview** | Strategy summary (atomic vs simple), step/hook counts, health-check status |
| **Pipeline** | Visual timeline: build steps (before activate), release steps (after activate, e.g. migrations), and hooks at clone / activate boundaries |
| **Rollout** | Zero-downtime toggle, post-activate HTTP health check, releases to keep, scheduler/supervisor/nginx extras |
| **Reference** | Deploy script variable placeholders and CLI snippets |

## Related pages

- **Deployments** — trigger deploys, view history, roll back atomic releases
- **Repository** — Git remote, branch, deploy key, quick-deploy webhook, sync groups

Run deploys from **Deployments**, not Pipeline. Pipeline is configuration only.

## Multiple pipelines

Each site can keep **alternate pipelines** (for example Default vs Staging build). The pipeline marked **Deploy** is what runs on the next deployment; others are recipes you can switch to or duplicate.

**Dply templates** (Laravel, Node SSR, static site, Ruby/Rails, plus runtime defaults) replace all steps on the pipeline you are editing — use them to bootstrap a new pipeline quickly.

Drag pills in the **build** zone (before activate) or **release** zone (after activate) to set order. Palettes below mirror those zones — **Migrate** and **Optimize** default to release so they run on the live `current` path after the symlink flip.

Click the **pencil** on a step pill to edit type, phase, timeout, or command (custom / npm run). **Clone** and **Activate** anchors have their own pencil — edit optional shell scripts per pipeline (leave empty to keep Dply’s built-in Git clone / symlink activate). Use **Add step** or **Custom command** for new steps — custom accepts any shell command in the release directory.

## Build vs release phases

| Phase | When it runs | Examples |
|-------|----------------|----------|
| **Build** | In the new release folder, before activate | `composer install`, `npm ci`, asset builds |
| **Release** | On the active release path after activate | `php artisan migrate --force`, `optimize` |

Atomic deploys: clone → build steps → **before activate** hooks → symlink → release steps → **after activate** hooks.

## Pipeline hooks

Hooks are part of the same timeline as build steps (not a separate page):

| Kind | Runs as |
|------|---------|
| **Shell** | Bash on the server over SSH (same working directory rules as legacy hooks) |
| **Webhook** | HTTP POST with JSON payload (`site.pipeline.hook`) |
| **Notification** | Message via an org **notification channel** (deploy started or finished/failed) |

**When** options:

- **Before clone** — deploy base directory
- **After clone** — new release directory
- **After a build step** — immediately after that pill succeeds
- **Before activate (swap)** — still on the new release folder, immediately before the `current` symlink flips
- **After activate** — on the live `current` path after the symlink updates (atomic deploys)

Drag **Shell**, **Webhook**, or **Notification** from the **Add hooks** palette onto a dashed zone in the timeline — the zone sets **when** it runs (before/after clone, before/after activate, or onto a build/release step for “after step”). A configure form opens for every type (shell script, webhook URL, or notification channel). Click a palette pill to add a hook manually and pick the timing yourself.

Only hooks on the **active deploy pipeline** run on deployment.
