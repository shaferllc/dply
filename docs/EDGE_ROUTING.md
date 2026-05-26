# Edge routing

The **Routing** section is a read-only view of **redirects, rewrites, and header rules** from your repo’s `dply.yaml` (or `dply.toml`) on the latest deploy.

In v1 you **edit routing in Git**, not in the dashboard. After changing the file, redeploy so the edge Worker picks up new rules.

## Where rules come from

Rules are parsed from the repo config file at build time. A compact summary also appears on **Build** under **Redirects, rewrites & headers**.

## Rule types

| Type | Typical use |
|------|-------------|
| **Redirects** | Permanent or temporary URL moves (301/302) |
| **Rewrites** | Serve a different path internally (SPA fallbacks, proxy paths) |
| **Headers** | Security or cache headers on path patterns |

## Empty state

If no deploy has shipped a config file yet, Routing shows starter `dply.yaml` examples you can copy into your repo root.

Use `dply edge lint` locally or check **Build & deploy logs** if a deploy fails config validation.

## Related sections

- **Build** — repo config snapshot and build overrides
- **Delivery** — hybrid proxy routes (origin fetch) vs static routing rules in `dply.yaml`
- **Domains** — custom hostnames attached to this site
