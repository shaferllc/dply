# Deployment flow (Dply)

Order of operations for a **simple** deploy (git directly in the deploy path):

1. **`before_clone` hooks** — run in the deploy directory (after `mkdir -p`).
2. **Git** — `git clone` (first time) or `fetch` / `checkout` / `pull`.
3. **`after_clone` hooks** — run in the repository root after code is updated.
4. **Pipeline steps** — ordered steps (Composer, npm, Artisan, custom) with per-step timeouts.
5. **Post-deploy command** — single legacy shell string from the site settings.
6. **`after_activate` hooks** — for simple deploys, same working directory as the repo root.

**Atomic** deploys use the same hook phases, but:

- `before_clone` runs in the site root (parent of `releases/`).
- `after_clone` and the **pipeline** run inside the new `releases/<timestamp>` directory.
- The post-deploy command runs there **before** the `current` symlink is updated.
- `after_activate` runs with working directory set to the **activated** `current` path.

## Webhook signing

**Recommended (replay-resistant):**

- Header `X-Dply-Timestamp`: Unix time in seconds.
- Header `X-Dply-Signature`: `sha256=` + `hash_hmac('sha256', "{timestamp}." . raw_request_body, webhook_secret)`.
- Clock skew must be within `DPLY_WEBHOOK_TIMESTAMP_TOLERANCE` seconds (default 300).
- Identical timestamp + body within ~15 minutes returns `409` (duplicate delivery).

**Legacy:** `X-Dply-Signature: sha256=` + `hash_hmac('sha256', raw_request_body, webhook_secret)` with no timestamp (still supported).

Optional **IP allow list** on the site (one IPv4/IPv6 or IPv4 CIDR per line). Empty list = any client IP (signature still required).

## API idempotency

`POST /api/v1/sites/{id}/deploy` accepts `Idempotency-Key`. The first response is `202` (queued) or `200` with a body when `sync=true`. Retries with the same key return the **cached** JSON for 24 hours after completion, or `409` while a deploy for that key is still running.

## Concurrent deploys

Only **one** deploy runs per site at a time (cache lock). Additional triggers create a deployment row with status **`skipped`** and a short message.

## Remote cleanup on site delete

When a site is deleted in Dply, a queued job removes the Nginx vhost file and enabled symlink on the server (when SSH is available) and reloads Nginx. It does **not** remove Git data, releases, or SSL certificates automatically.

## Configuration reference

| Env / config | Purpose |
|--------------|---------|
| `DPLY_WEBHOOK_TIMESTAMP_TOLERANCE` | Max clock skew for webhook timestamp (seconds). |
| `DPLY_WEBHOOK_MAX_ATTEMPTS_PER_MINUTE` | Per-site webhook throttle. |
| `DPLY_MAX_ORG_MEMBERS` | Hard cap on members + pending invites (null = unlimited). |
| `DPLY_SITE_HEALTH_CHECK` | Enable scheduled HTTPS/HTTP checks for nginx-active sites with domains. |
| `DPLY_DEPLOY_NOTIFICATIONS` | Email site owner + org admins on deploy success/failure/skip. |
