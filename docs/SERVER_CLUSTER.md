---
title: "Kubernetes cluster"
slug: server-cluster
category: "Servers"
order: 120
description: "The workspace home for Kubernetes host kinds, showing cluster name, API endpoint status, node readiness, and managed namespaces."
group: servers
---

# Kubernetes cluster

The **Cluster** section is the workspace home for **Kubernetes** host kinds — not traditional SSH VM tools.

## Cluster overview

Shows:

- **Cluster name** and provider context
- **API endpoint** status
- **Node** count and readiness
- **Namespaces** dply manages

Most VM sidebar items ( **Run**, **Webserver**, **Firewall**, etc.) are hidden for Kubernetes hosts.

## Workloads

Link out to sites using **Kubernetes runtime** and fleet views. Container images and manifests live in Git deploy settings.

## Feature flag

Requires **`workspace.cluster`**. Only appears when `host_kind=kubernetes`.

## Related sections

- **Infrastructure** — cluster inventory
- **Site → Runtime** — K8s deployment for one app
- **Fleet** — cross-cluster health (when enabled)
