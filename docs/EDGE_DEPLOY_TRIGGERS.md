# Edge deploy triggers

The **Deploy triggers** section controls **what starts a deploy** without opening the dashboard: inbound webhooks and GitHub push/PR events.

Build settings still has a **Deploy on push** checkbox; this section is where you connect GitHub and manage hook URLs.

## Deploy hooks

**Deploy hooks** are per-site URLs you POST to trigger a redeploy — useful for CMS publish flows (Sanity, Contentful, Strapi, etc.).

1. Enter a **Hook name** and click **Create hook**.
2. Copy the full URL when shown (**dply only displays it once**).
3. Configure your external system to POST to that URL on content changes.

Revoke a hook from the list when the URL should stop working.

## GitHub auto-deploy

When your repo is on GitHub:

1. Pick a **Linked GitHub account** (OAuth-connected under Profile → Source control).
2. Click **Enable auto-deploy webhook** to register push and pull-request webhooks.
3. Ensure **Deploy on push** is enabled under **Build** for production branch deploys.

Pull requests get a GitHub Check Run and a summary comment (updated in place on each push) with the preview URL when the deploy lands.

If automatic registration fails, manual webhook instructions appear in this panel.

## Preview workspaces

Deploy hooks are available on production sites. GitHub auto-deploy blocks may be simplified on preview child sites.

## Related sections

- **Build** — **Deploy on push** toggle and production branch
- **Deploys** — history, rollback, manual redeploy
- **Previews** — PR/branch preview list and protection
