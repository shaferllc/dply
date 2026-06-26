---
title: "teaching dply to recognize a repo"
date: 2026-05-01
slug: "2026-05-01-runtime-detection-engine"
summary: "Built the runtime detection foundation — a per-language detector set plus an orchestrator and a dply.yaml manifest parser."
tags: [deploys, runtime, modules, services]
published: true
---

Today was the fun kind of greenfield. I wanted dply to be able to look at a repo and figure out what it actually is — Node? PHP? Go? a static site? — without me hand-holding it through a wizard every time.

So I built the foundation for runtime detection. It started with the Node detector and a small framework around it, then I just kept going: PHP, Static, Go, Ruby, Python. Each one is a focused little detector that knows the fingerprints of its ecosystem. On top of them sits a `RuntimeDetectionEngine` orchestrator that runs the detectors and picks a winner, and a `RepositoryRuntimePlanComposer` that turns that result into an actual plan for what to install and how to build.

## the manifest escape hatch

Detection is great until it's wrong, so I also added a `dply.yaml` manifest parser. The idea: auto-detect by default, but let people drop a manifest in their repo to override anything explicitly. Trust the magic, but always give an off-ramp.

A few supporting pieces landed alongside:

- new `runtime_version` and `build_command` columns on sites, so a detected plan has somewhere to live
- a `SiteProcess` model, with a web process row auto-created when a site is created
- runtimes/frameworks tags on marketplace items, so presets can advertise what they're for

The detectors will need real-world repos thrown at them before I trust them — fingerprints are easy to get subtly wrong. But the bones feel right, and it's satisfying to have the multi-runtime story start as actual code instead of a slide.
