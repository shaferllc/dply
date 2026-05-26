# Edge build & deploy logs

**Build & deploy logs** shows CI output from clone-and-build jobs — not visitor HTTP logs (see **Traffic & analytics**).

## Deployment list

The page includes a deploy history table. Select a deployment to read its log stream.

## Log content

Typical log sections:

- Repository clone
- Dependency install (`npm ci`, `pnpm install`, etc.)
- **Build command** output
- Publish/upload to edge storage
- Failure reason summary when the job exits non-zero

## Live polling

While a deploy status is **building**, logs refresh automatically so you can watch progress without reloading.

## Failed builds

When a deploy fails:

1. Scroll to the end of the log for the error message.
2. Fix the repository, **Environment** vars, or **Build** settings (command/output dir).
3. **Redeploy** from the Deploys section.

Common failures: wrong output directory, missing build script, Node version mismatch.

## Hybrid publish

Hybrid deploys log static asset publish separately from origin health. Origin errors appear at runtime, not always in the static build log.

## Build vs visitor logs

| Log type | Where |
|----------|--------|
| Build & deploy | This section |
| CDN / HTTP access sample | Traffic & analytics |
| Origin application logs | Linked Cloud app or external origin |

## Retention

Log retention follows platform policy. Download or copy important failure excerpts before retrying if you need a permanent record.
