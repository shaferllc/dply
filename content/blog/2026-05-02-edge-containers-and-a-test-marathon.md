---
title: "Standing up dply edge on two clouds, then paying down the test debt"
date: 2026-05-02
slug: "2026-05-02-edge-containers-and-a-test-marathon"
summary: "How the managed-container layer got wired across DO App Platform and AWS App Runner behind one abstraction, and why the day ended in a long, deliberate test marathon."
tags: [edge, containers, modules, tests, deploys]
type: deep-dive
published: true
---

This was the day managed containers stopped being a slide and became a layer. dply edge — the part of the platform that runs your app as a container instead of on a VM you babysit — went from a sketch to something with backend clients, provider gates, a dashboard panel, and a headless CLI. And because real product work shakes loose a surprising amount of latent test debt, the back half of the day was me sitting down and grinding ~150 commits of test fixes until the suite was green again. Both halves taught me something, so this is a deep dive on both.

## One seam, two clouds

The core design decision was to never let the rest of the app know *which* cloud is running a container. DigitalOcean App Platform and AWS App Runner are wildly different APIs — different lifecycle vocabularies, different ways of expressing a deploy, different polling semantics — but from the perspective of a Site, "deploy me as a container" should be one verb.

So I built two backend clients, `DigitalOceanAppPlatformService` and `AwsAppRunnerService`, behind a thin orchestration layer (`dply edge`). The orchestration layer speaks intent; the clients translate that intent into each provider's dialect. To make the data model honest about what a container site actually is, I added a `HOST_KIND` concept plus the Site fields that hang off it, and wired provider gates and credential handling so a backend simply isn't selectable until the org has connected the right cloud.

The shape, roughly:

```php
interface EdgeBackend
{
    public function deploy(Site $site, DeploySpec $spec): DeploymentHandle;
    public function status(Site $site): EdgeStatus;
    public function attachDomain(Site $site, string $hostname): void;
}

// Resolved by HOST_KIND, never instantiated directly by callers.
$backend = match ($site->host_kind) {
    HostKind::DoAppPlatform => app(DigitalOceanAppPlatformService::class),
    HostKind::AwsAppRunner  => app(AwsAppRunnerService::class),
};
```

The win of this seam is that the UI and the CLI both target `EdgeBackend`, not a provider. A `dply:edge:deploy` command can do a headless deploy without knowing or caring whether it lands on DO or AWS. That matters for CI scripting and it matters for my own sanity later when a third backend shows up.

## Making it visible

A container that deploys but that you can't *see* deploying is a black box, and black boxes erode trust fast. So a good chunk of the day was the observability surface around the new backends:

- a **container deployment panel** on the site dashboard, so the deploy isn't a CLI-only event;
- a **recent-activity timeline** on the container dashboard, so you can read the history;
- **edge status polling on a cron**, so the dashboard reflects reality even when you didn't trigger the change;
- **custom domain attachment** for edge sites;
- an **`/edge/create` page** to start one from the UI.

The cron-based status polling is the unglamorous piece that makes the rest feel alive. Provider lifecycles are eventually-consistent — you ask App Runner to deploy and it says "okay, working on it" — so without a poll the dashboard would just lie until you refreshed at exactly the right moment.

## The pointed product call: retiring the Fly.io upsell

Up to this point I'd been leaning on Fly.io as the "if you want managed containers, go here" answer, complete with upsells sprinkled through the UI. Today I pulled them out and replaced them with dply edge. The reasoning is simple and a little uncomfortable: if I'm going to build the managed-container product, I should sell *my* managed-container product. Pointing customers at a competitor for the exact capability I'm shipping is a confused message.

It's a handoff, not a wall, though. I kept a Fly value-prop explainer on the credentials connect panel and added a `dply:fly:eligibility` CLI plus a `dply:fleet:summary` extension that surfaces Fly edge connection state — so existing Fly users can see their migration candidates rather than hitting a dead end. The principle: stop *upselling* a competitor, but don't pretend existing users don't exist.

## Then the bill came due

Here's the honest part. New `HOST_KIND` fields, renamed buttons, retired banners, reworded journey copy — each one is a small change that ripples into dozens of test assertions. By mid-afternoon the suite was a sea of red that had very little to do with the actual edge feature and everything to do with the blast radius of touching shared UI and models. So I stopped building and started fixing, methodically, down the whole list.

A representative sample of what was actually wrong:

- **ULID length drift** — `MetricsIngestTest` fixtures had 27/28-character IDs where the schema wanted true 26-char ULIDs. Trimmed them.
- **Mockery overload poisoning** — the `SshConnection` test used a Mockery `overload:` mock, which leaks into sibling tests in the same process. I isolated it into its own process so it stopped corrupting neighbors.
- **Coming-soon middleware** — webhook, referral, and TaskRunner tests were hitting a `coming-soon` gate that the test env should bypass. Routed around it.
- **Copy-and-arch churn** — `ServerTest` create-wizard assertions, the `WorkspaceFirewall` "Advanced" panel, the renamed Add-key button, a retired SSH-keys reminder banner, an unsupported-PHP-version fixture in `SiteTest` — all updated to match the new reality.
- **Genuine pre-existing gremlins** — provider flags and a backup ULID that had been quietly red and that I finally just fixed.

The lesson I keep relearning: a renamed button is a schema change for your test suite. There's no free UI copy edit once you assert on text.

## What shipped

- `DigitalOceanAppPlatformService` + `AwsAppRunnerService` behind a single edge backend seam
- `HOST_KIND` + Site fields, provider gates, and credentials for both clouds
- Container deployment panel, recent-activity timeline, cron status polling, custom domains, `/edge/create`
- `dply:edge:deploy` for headless deploys; `dply:fly:eligibility` + fleet summary for migration candidates
- Replaced Fly.io upsells with dply edge while keeping a clean handoff path
- ~150 commits of test repair to get back to green

## What I'd do differently

The test marathon was self-inflicted. If I'd built the new UI copy and fields behind their final names from the start — and leaned less on asserting exact button text — half of those fixes would never have existed. The deeper fix is to assert on roles and test IDs rather than human copy, so that wording is free to change. I didn't do that today; I paid the tax instead. But the edge seam is the thing I'm proud of: two genuinely different clouds now look identical to everything upstream, which is exactly the kind of boundary that lets me add a third backend tomorrow without touching the UI at all.
