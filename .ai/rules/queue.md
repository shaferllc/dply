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

## Fleet worker containers must run as the app's owner, not root
`DockerWorkerRuntime` starts workers with `--cap-drop ALL`. That drops CAP_DAC_OVERRIDE — the capability that lets root ignore file permission bits.

The generated Dockerfile ends with `chown -R www-data:www-data /var/www/html`. So a container running as root cannot write `storage/logs/laravel.log`, and the worker dies on its first log line:

    UnexpectedValueException: The stream or file ".../storage/logs/laravel.log"
    could not be opened in append mode: Failed to open stream: Permission denied

`FleetImageBuilder` therefore appends `USER www-data` (after a re-`chown`, because `composer require` runs as root and leaves root-owned vendor files). Do not "fix" this by re-adding capabilities or chmod 777.

A bring-your-own image must do the same: its USER has to own the app directory, or set no USER and accept that root has no DAC override.

Found by `php artisan dply:queue:fleet-smoke` against a real daemon — no mocked test reaches it.
