---
title: "teaching the wizard to speak kubernetes"
date: 2026-05-15
slug: "2026-05-15-kubernetes-in-the-wizard"
summary: "Brought managed Kubernetes (DOKS and EKS) into the create wizard end to end, and finally retired the standalone container launcher."
tags: [kubernetes, wizard, servers, refactor]
published: true
---

Yesterday I split the containers launcher; today I tore the old one down and folded everything into the main create wizard. Fourteen commits, mostly about making **Kubernetes a first-class host kind** rather than a bolted-on side flow.

The header line: I demolished the standalone container launcher. It had served its purpose, but having two front doors for "make a thing that runs containers" was confusing me, let alone a user. So the wizard is now the one path, and it learns what you want from the server's `host_kind`.

## what landed

- A new **kubernetes** value in the `provider_host_kind` enum, plus a K8s host-kind tile and routing through StepWhere (DOKS only to start).
- **DOKS**: a cluster picker on StepWhat (list-and-pick), and `StoreServerFromCreateForm` lands DOKS servers at `STATUS_READY` immediately — there's no cold provisioning to wait on, so don't fake one.
- **EKS**: end-to-end AWS Kubernetes registration, also list-and-pick. Two clouds, same shape.
- StepReview got K8s adaptations — the right chips, a billing disclosure, a sane back-target — and StepWhat grew a K8s-shaped heading and stepper label so it doesn't read like a VM.
- On the Sites side, container-mode is now auto-decided by the server's host kind instead of asking, plus an add-first-container CTA on Overview and a waiting hint on the Sites tab.

The fiddly part was vocabulary. A Kubernetes cluster is not a VM, and every place that assumed "server = box you SSH into" needed a gentle nudge to also mean "cluster you register." Tests for the Kubernetes wizard branch came along to lock the behavior in.

Retiring code feels better than writing it. One front door, two new clouds, fewer ways to get lost.
