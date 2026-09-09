---
title: "Edge crons"
slug: edge-crons
category: "Edge"
order: 116
description: "UTC cron schedules for Edge Workers. Dashboard rows merge with dply.yaml on deploy."
group: edge
---

# Edge crons

**Crons** attach UTC cron triggers to your Edge Worker. Use them for periodic middleware/SSR work (cleanup, fan-out, health pings) without a separate Cloud app.

Requires a site that ships a Worker (middleware and/or SSR). Schedules apply on the **next deploy**. Adding rows in the dashboard alone does nothing until a Worker with a `scheduled` export is deployed.

## Dashboard vs repo

| Source | Editable here? | Notes |
|--------|----------------|-------|
| **Dashboard** | Yes | Stored on the site; merged at deploy time |
| **Repo (`dply.yaml`)** | Read-only | Commit + redeploy to change |

On schedule merge, both lists ship together. Prefer the repo for anything you want reproducible in git.

## How to use it

1. Add **middleware** at `src/middleware.ts` (or `middleware.ts` at the repo root) that exports `scheduled`.
2. Add a **schedule** (standard 5-field cron, UTC) in the dashboard and/or `dply.yaml`.
3. Redeploy so Cloudflare cron triggers attach and call your handler.

### Worker code

```ts
// src/middleware.ts
export default {
  async fetch(request, env) {
    // Pass through to static assets / hybrid origin
    return new Response(null, {
      status: 204,
      headers: { "X-Dply-Middleware": "continue" },
    });
  },

  async scheduled(controller, env, ctx) {
    // Runs on every matching cron (controller.cron is the expression)
    console.log("cron", controller.cron);
    // e.g. await env.JOBS.send({ type: "cleanup" });
  },
};
```

Return `204` + `X-Dply-Middleware: continue` on `fetch` so normal page traffic keeps working. Cron invokes `scheduled` on the same Worker script.

### Schedules in `dply.yaml`

```yaml
crons:
  - schedule: "*/5 * * * *"
  - schedule: "0 3 * * *"
```

## Tips

- Expressions are **UTC**, not the browser’s timezone.
- **v1** always calls the Worker’s `scheduled` export; a Handler name in the UI is reserved for later.
- Pair with **Jobs** / **Bindings** when a cron should enqueue queue work.

## Related sections

- **Jobs** — default queue binding for Worker enqueue
- **Bindings** — KV, R2, D1, queues available to cron handlers
- **Deploy triggers** — git/webhook deploys (not cron)
