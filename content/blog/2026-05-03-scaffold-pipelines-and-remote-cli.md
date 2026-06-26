---
title: "Empty server to running app: scaffold pipelines and a RemoteCli foundation"
date: 2026-05-03
slug: "2026-05-03-scaffold-pipelines-and-remote-cli"
summary: "Async WordPress and Laravel scaffold pipelines, a journey UI to watch them install, and the RemoteCli layer (WP-CLI + Artisan) that powers a wave of new management tabs."
tags: [scaffold, remote-cli, wordpress, laravel, ui]
type: deep-dive
published: true
---

The theme today was getting from an empty server to a real, running application without anyone ever opening an SSH session. Two scaffold pipelines — WordPress and Laravel — plus a `RemoteCli` foundation underneath them, plus a journey UI on top so you can actually *watch* the install land. It's a vertical slice through the whole stack: wizard, queued jobs, remote execution, and a polling UI, all in one day.

## Why scaffolding has to be async

A WordPress install is six steps; a Laravel install is eight. None of them are instant, and several of them are network-bound (package fetches, composer, downloads). The cardinal rule of this codebase is that **nothing slow runs in the HTTP path** — PHP's request lifecycle will guillotine you at 30 seconds, and even if it didn't, a spinning browser tab is a terrible install experience.

So both pipelines run as background jobs, step by step, recording state as they go. The Site Create wizard grew a *scaffold-mode branch* that kicks off the pipeline and then hands you to a journey page rather than blocking on completion. The model is "fire the job, poll the state," the same pattern every long-running operation in dply uses.

```php
class ScaffoldWordPressPipeline
{
    /** Each step records its own status so the journey UI can poll it. */
    protected array $steps = [
        EnsurePrerequisites::class,   // self-heal: is the box ready?
        InstallCore::class,
        ConfigureDatabase::class,
        CreateAdminUser::class,       // password revealed exactly once
        ApplyHardening::class,        // the Q18 secure defaults
        Finalize::class,
    ];

    public function handle(Site $site): void
    {
        foreach ($this->steps as $step) {
            $site->scaffold->markRunning($step);
            app($step)->run($site);   // throws => step marked failed, retryable
            $site->scaffold->markDone($step);
        }
    }
}
```

There's a WordPress server preset plus a *prerequisite self-heal* so the box gets the packages it needs before the install starts, rather than failing three steps in because something wasn't there.

## The journey UI: watch it, retry it, reveal-once

If the install is async, the user needs a window into it. The scaffold journey UI renders each step as it lands, lets you retry a single failed step in place, and reveals the generated admin password exactly once — after that it's gone from the surface. Small touches that ended up mattering: Copy buttons on the REASON and Captured-output blocks so you can paste a failure somewhere, a styled confirm modal for "Resume install" (swapped in for a default browser dialog), and copy that's explicit about the fact that Resume re-runs the *full* bootstrap script, not just the failed step.

The reveal-once admin password is the kind of detail that's easy to skip and expensive to get wrong. Scaffolding generates a credential; leaving it sitting on a dashboard forever is a quiet security smell. Showing it once, prominently, with a copy button, and then never again is the right default.

## RemoteCli: WP-CLI and Artisan as a first-class layer

Under the pipelines sits the real reusable thing: a `RemoteCli` foundation that runs WP-CLI and Artisan over a hybrid sync/async dispatch. "Hybrid" matters — a quick `wp option get` can return inline, but `php artisan migrate` needs to be queued and polled. The layer makes that decision so callers don't have to.

That foundation immediately paid off by powering a wave of management surfaces, all built on the same execution primitive:

- **WordPress Settings**: Console + Cron sub-tabs (PR 9)
- **Plugins** tab with Wordfence advisory badges, backed by a new **Wordfence Intelligence** advisory provider (PR 10a/b)
- **Database snapshots** sub-tab + a `SnapshotService` primitive, with a pre-rollback snapshot before destructive ops (PR 10c)
- a **Hardening** tab that's pure transparency — it just shows you which secure defaults are already on (PR 10d)
- **Laravel Schedule / Migrations / Pail** sub-tabs (PR 11a–c), with the Migrations tab taking a pre-rollback snapshot and Pail rendering as a live `wire:poll` tail with byte-offset incremental fetch
- `dply:wp` + `dply:artisan` umbrella CLI commands (PR 12)

The Pail tail deserves a note: rather than re-reading the whole log every poll, it tracks a byte offset and fetches only the new tail. It's the difference between a log viewer that scales and one that melts as the file grows.

## DNS without the wait

A fresh site has a chicken-and-egg problem: you want to hit it immediately, but real DNS hasn't propagated. So I built `PlaceholderDnsManager`, which gives every site an instant `<slug>.ondply.io` with a nip.io fallback. You can load a brand-new site the moment it's up, then cut over to the real hostname once DNS catches up. I wired this into the scaffold pipelines and lifecycle hooks so it's automatic, not a manual step.

## What bit me: headless package installs

The genuinely annoying part of the day was apt/PPA flakiness during bootstrap. Headless package installation is where dreams go to hang — a mirror is briefly slow, the ondrej PPA isn't visible yet, and a naive script either blocks forever or fails hard on a transient blip.

Two fixes. First, I made the local `ssh-dev` container an exact mirror of DigitalOcean's `ubuntu-24-04-x64` baseline — pre-baking the ondrej PPA and PHP 8.4 — so "works on my machine" actually predicts "works in prod." Second, on the journey page I started *classifying* PPA and apt-fetch timeouts as transient (rather than fatal) and retrying `apt-get update` until the ondrej PPA shows up:

```bash
for attempt in 1 2 3 4 5; do
  if apt-get update -o Acquire::Retries=3 && apt-cache policy | grep -q ondrej; then
    break
  fi
  echo "ondrej PPA not visible yet (attempt $attempt) — transient, retrying"
  sleep 5
done
```

Classifying a failure as *transient vs. fatal* is one of those distinctions that doesn't exist until you've been burned. A timeout fetching a mirror is not the same kind of event as "the install command rejected your config," and treating them identically means either spurious failures or hung jobs.

## What shipped

- WordPress (6-step) and Laravel (8-step) async scaffold pipelines + scaffold-mode wizard branch
- Scaffold journey UI: per-step status, single-step retry, reveal-once admin password, copy buttons
- `RemoteCli` foundation (WP-CLI + Artisan, hybrid sync/async) powering ~8 new management sub-tabs
- `SnapshotService` primitive with pre-rollback snapshots; Wordfence Intelligence advisory provider
- `PlaceholderDnsManager` for instant `<slug>.ondply.io` + nip.io fallback
- ssh-dev container aligned to the DO ubuntu-24-04-x64 baseline; transient-aware apt retries

## Looking back

The thing I'd defend hardest is building `RemoteCli` as its own layer instead of letting each tab shell out ad hoc. Every management surface I added afterward was cheap precisely because the execution primitive already existed and already made the sync-vs-async call. The thing I'd watch is scope: shipping two full scaffolders *and* a dozen management tabs in one day means a lot of surface that the test suite hasn't fully caught up to yet. That's a bill, and like most bills around here, I expect to pay it shortly.
