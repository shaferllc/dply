---
title: "one-click WordPress and Laravel, scaffolded"
date: 2026-05-03
slug: "2026-05-03-scaffold-pipelines-and-remote-cli"
summary: "Built async scaffold pipelines for WordPress and Laravel plus a RemoteCli foundation, with a journey UI to watch it all install."
tags: [scaffold, ui, remote-cli, deploys]
published: true
---

Another heavy one. The theme today was scaffolding — going from an empty server to a real, running app without ever touching SSH yourself.

The two big pipelines: a WordPress scaffold (a 6-step async install with hardening baked in) and a Laravel scaffold (8 steps). Both run as background jobs, and I built a scaffold journey UI on top of them so you can watch each step land, retry a failed one, and reveal the generated admin password exactly once. The Site Create wizard grew a scaffold-mode branch to kick it all off, and there's a WordPress server preset plus prerequisite self-heal so the box is ready before the install starts.

Underneath sits a `RemoteCli` foundation — WP-CLI and Artisan over a hybrid sync/async dispatch — which then fed a wave of new WordPress and Laravel management surfaces: a Console and Cron sub-tab, a Plugins tab with Wordfence advisory badges (backed by a new Wordfence Intelligence provider), Laravel Schedule/Migrations/Pail sub-tabs, database snapshots, and a Hardening tab that just shows you which secure defaults are already on.

## DNS without the wait, and a container that matches prod

Two smaller-but-important pieces:

- `PlaceholderDnsManager` gives every site an instant `<slug>.ondply.io` with a nip.io fallback, so you can hit a fresh site before real DNS resolves.
- I made the local `ssh-dev` container an exact mirror of DigitalOcean's `ubuntu-24-04-x64` baseline — pre-baked ondrej PPA and PHP 8.4 — so "works locally" actually means something.

The annoying part was the apt/PPA flakiness during bootstrap: I ended up classifying PPA and apt fetch timeouts as transient on the journey page and retrying `apt-get update` until the ondrej PPA shows up. Headless package installs are where dreams go to hang.
