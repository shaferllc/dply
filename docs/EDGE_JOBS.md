---
title: "Edge jobs"
slug: edge-jobs
category: "Edge"
order: 117
description: "Point middleware or SSR workers at a default queue binding so they can enqueue background work without a Cloud app."
group: edge
---

# Edge jobs

**Jobs** wires a default queue binding name so middleware or SSR workers can enqueue background work without a separate Cloud app.

Requires **Dply-hosted Edge delivery** and a queue binding under **Bindings**.

## How it fits together

1. Create a **queue binding** on **Bindings** (e.g. name `JOBS`).
2. Open **Jobs**, set that name as the default queue binding, enable, and **Save**.
3. From middleware/SSR code, send messages using the binding name, for example:

```js
await env.JOBS.send({ type: 'notify', … })
```

Consumers run in your bound worker scripts — this page only configures the default binding name and UX flag.

## Tips

- If the bindings list is empty, add a queue binding first, then return here.
- Binding names are case-sensitive; match `env.NAME` to the dashboard name.
- Long-running PHP/Rails workers still belong on Cloud or BYO — Edge jobs are for Worker-side queues.

## Related sections

- **Bindings** — create KV, R2, D1, and queue attachments
- **Build** / **Delivery** — SSR and middleware context where `env` is available
