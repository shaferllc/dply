---
title: "dply edge gets real, and a long test marathon"
date: 2026-05-02
slug: "2026-05-02-edge-containers-and-a-test-marathon"
summary: "Stood up the managed-container edge layer on DO App Platform and AWS App Runner, then spent the back half hammering the test suite green."
tags: [edge, modules, tests, deploys]
published: true
---

Big day. The headline is that managed containers — dply edge — went from idea to wired layer, and the long tail was me paying down a mountain of test debt that the new work shook loose.

On the product side, I built out the orchestration layer and two backend clients behind it: a `DigitalOceanAppPlatformService` and an `AwsAppRunnerService`. New `HOST_KIND` and Site fields model what a container site actually is, and there are provider gates and credential handling for both clouds. The UI caught up too — a container deployment panel on the site dashboard, a recent-activity timeline, edge status polling on a cron, custom domain attachment, an `/edge/create` page, and a `dply:edge:deploy` CLI for headless deploys.

The slightly pointed decision: I ripped out the Fly.io upsells and replaced them with dply edge. I'd been leaning on Fly as the "managed container" answer, but if I'm building the thing, I should sell the thing. There's still a Fly value-prop explainer and a `dply:fly:eligibility` command for surfacing migration candidates, so it's a handoff, not a wall.

## then the bill came due

The other ~150 commits were tests. Honestly. A new field here and a renamed button there ripple out into dozens of assertions, and today I just sat down and worked through the whole list:

- trimmed 27/28-char fixture IDs down to proper 26-char ULIDs in the metrics ingest tests
- isolated the Mockery overload SshConnection test in its own process so it stopped poisoning neighbors
- bypassed the coming-soon middleware in webhook and referral tests
- chased a pile of "fix pre-existing failure" gremlins that had nothing to do with edge but were sitting red

Tedious, but a green suite is the only thing that lets me move fast tomorrow. Worth the grind.
