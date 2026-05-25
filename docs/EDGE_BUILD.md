# Edge build settings

**Build settings** controls how each deploy runs and how hybrid origins behave. Repository and delivery backend are read-only after create in v1.

## Edge delivery (read-only)

Shows **Mode** (managed vs BYO Cloudflare), **Publish hostname**, and linked Cloudflare credential when applicable.

To change delivery backend, create a new Edge site.

## Repository & branch (read-only)

Displays connected **Repository** and **Production branch** from site creation. Changing repo/branch requires a new Edge site in v1.

## Build configuration

Editable fields (save then redeploy to apply):

| Field | Purpose |
|-------|---------|
| **Build command** | Shell command run in CI, e.g. `npm ci && npm run build` |
| **Output directory** | Folder uploaded to edge storage, e.g. `dist` |
| **SPA fallback** | Serve `index.html` when a static file is missing (SPA routers) |
| **Deploy on push** | Auto-deploy on commits to the production branch |
| **Releases to keep** | How many past deployments to retain |

Click **Save build settings** after edits.

## GitHub deploy webhook

When deploy-on-push is enabled for GitHub:

- **Enable auto-deploy webhook** — dply registers the webhook on your repo
- **Disable** — stops automatic deploys until re-enabled
- Manual setup instructions appear if automatic registration fails

## Convert to hybrid

Static sites can **Convert to hybrid** to add an SSR origin later. Follow prompts to link Cloud or enter an origin URL.

## Hybrid origin settings

For hybrid sites:

| Setting | Purpose |
|---------|---------|
| **Origin URL** | HTTPS base URL for SSR (linked Cloud `live_url` or external) |
| **Origin routes** | Path patterns fetched from origin vs served statically |
| **Health check** | URL probed to detect origin outages |
| **Failover HTML** | Static page shown when origin is unhealthy |
| **Cache purge by tag** | Purge edge cache after deploys (tag-based) |
| **Origin auth secret** | Rotate shared secret for origin requests |

Save changes and redeploy static assets when adjusting routes or cache behavior.

## Preview comment widget

Toggle **Enable preview comment widget** to allow inline feedback on preview deployments (see **Preview comments** guide).

## Image optimization

When enabled, Edge can resize and reformat images at the CDN. Configure signing secret rotation for secure transform URLs.

## After saving

Build setting changes apply on the **next deploy**. Use **Redeploy now** on Overview or Deploys to apply immediately.
