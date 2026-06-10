# Multi-backend sites

The prerequisite for **rolling** and **canary** deployments (see
`docs/DEPLOYMENT_METHODS.md`). Today a Site binds to exactly one server
(`sites.server_id`); a deploy runs on that one box. Rolling/canary are inherently
multi-backend — they drain/health-gate node by node, or shift weighted traffic
across backends — so a Site must be able to declare *"serve me from ≥2 backends
behind a balancer."*

## The model

A Site gains a **backend group**: its original server plus N replica backends, all
running the same code/env, fronted by a load balancer. One domain, one Site
record, multiple serving points.

- **Backends are rows, not new sites you manage.** Each backend is a
  `site_backends` row linking the logical Site → a backend `server`. Reusing the
  worker-pool replica pattern, the code on a backend server lives in a derived
  child Site (`parent_site_id` → the logical Site); `site_backends.backend_site_id`
  points at it. The primary backend's row has `backend_site_id = null` (the code
  is the logical Site itself on its own server).
- **Provisioning reuses worker-pool replica cloning** (decided). The
  `WorkerWorkloadReplayer` / `ReconcileWorkerPoolJob` machinery already clones a
  site + env to a fresh server and deploys it; we adapt it for web backends
  (scheduler/Horizon specifics stay off; the backend serves HTTP, not queues).
- **Two balancer substrates, chosen per site** (decided):
  - `haproxy` — software LB dply manages over SSH. Supports **per-backend weight +
    drain**, so it enables *both* rolling and weighted **canary**.
  - `hetzner` — provider-managed cloud LB. Add/remove targets → **rolling only**;
    no clean per-target weights, so **canary is not offered** on it.
  - Substrate + LB linkage live in `sites.meta['backend_group']`
    (`{enabled, substrate, load_balancer_id, desired_count}`); the
    `site_backends` rows are the source of truth for membership.

## Capability gating

- A site is multi-backend when `backend_group.enabled` and it has ≥2 `active`
  backends.
- **rolling** offered when the site is multi-backend (either substrate).
- **canary** offered only when the substrate supports weights (`haproxy`). On
  `hetzner` it stays hidden — never shown-then-errored
  (`DeploymentMethod::availableFor`).

## Schema — `site_backends`

| column | meaning |
|---|---|
| `id` (ulid) | pk |
| `site_id` | the logical Site that owns the group |
| `server_id` | the backend app server |
| `backend_site_id` (nullable) | derived child Site on that server (null = the primary's own server row) |
| `role` | `primary` \| `replica` (exactly one primary per site — partial unique index) |
| `weight` (u16, default 100) | weighted routing for canary (HAProxy) |
| `state` | `provisioning`→`replaying`→`deploying`→`active`→`draining`→`errored` (mirrors WorkerPool member states) |
| `drained_at` (nullable) | set while a backend is pulled from rotation for a rolling step |
| `meta` (json) | per-backend scratch (provider ids, last health, …) |

`unique(site_id, server_id)` — a server backs a site at most once.

## Phasing

1. **4a — data model (this slice):** `site_backends` table + `SiteBackend` model +
   `Site::backends()` / `Server::siteBackends()` relations + helpers
   (`Site::isMultiBackend()`, `backendGroup()`). Purely additive, no behavior
   change — nothing reads it yet.
2. **4b — provisioning:** an "add backend" flow that adapts the worker-pool
   replayer to stand up a web backend (provision server → replicate site+env →
   deploy → join group as `active`), and a balancer-attach step (create/extend the
   chosen LB, register the backend as a target).
3. **4c — routing + weight/drain:** teach the chosen substrate to render weights
   and drain a backend — HAProxy backend lines (`server … weight=N`,
   drain/`disabled`) and Hetzner target add/remove. Nginx/Caddy upstream weight
   parsing for the software path.
4. **P5 — rolling:** orchestrate drain → deploy that backend → health-gate →
   re-add, backend by backend, with auto-rollback on health regression.
5. **P6 — canary (HAProxy only):** weighted shift (e.g. 10→25→50→100) gated on
   health + error-rate (Insights), auto-promote or roll back to weight 0.

Each phase ships behind capability gating so a half-built method never surfaces.
