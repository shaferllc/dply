---
paths:
  - 'app/Modules/Queue/**'
---

# Queue

## A fleet worker image must contain the dply queue connection
`DockerWorkerRuntime` runs `php artisan queue:work dply` inside the customer's image. That resolves only if a `dply` connection exists in the image's `config/queue.php`.

For a deployed site, `QueueInsightsInstaller` composer-requires `dply/queue-insights` at deploy time and its provider registers the connection. `FleetImageBuilder` does NOT do this — it clones, writes the generated Dockerfile and builds. So an image built from a stock Laravel repo starts and immediately exits with "Queue connection [dply] not configured".

Fix before pointing fleets at built images: install the agent inside the build (and note a private package needs registry auth at build time, which is a separate unsolved problem).

Verify end to end with `php artisan dply:queue:fleet-smoke <server>` — it drives host → docker → endpoint → namespace → fleet → image → env → push → reconcile → container → drain against a real daemon.
