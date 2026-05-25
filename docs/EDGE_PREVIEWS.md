# Edge previews

**Previews** deploy branch and pull-request builds to isolated URLs so you can review changes before merging.

## Where previews appear

- **Parent site → Previews** section — list of active preview deployments for this production site
- **Edge fleet → Previews tab** — all preview sites across the org

Each preview is a **child site** with its own hostname and reduced workspace (no fleet analytics/billing tabs on the child).

## How previews are created

Previews are typically created when:

- A pull request is opened or updated against the connected repository
- A branch deploy is triggered (depending on your Git integration settings)

The preview URL follows your org’s Edge testing domain pattern.

## Preview list actions

For each preview row you can:

- **Open** — visit the live preview URL
- **Tear down** — delete the preview deployment and free resources

Tearing down removes the preview site and its edge routing; it does not merge or close the Git PR.

## Production vs preview

| | Production site | Preview child |
|---|-----------------|---------------|
| Branch | Production branch | PR/feature branch |
| Billing | Counts toward live site fee | Free |
| Sidebar | Full observability | Overview, Deploys, Domains, Build, Logs, Danger |

## Build settings on previews

Preview children inherit build configuration from the parent. Webhook auto-deploy blocks may be hidden on preview workspaces.

## Comment widget

Enable the preview comment widget on the parent site under **Build settings** to collect feedback tied to preview URLs (see **Preview comments**).

## Clean up stale previews

Remove unused previews from the **Previews** list or fleet tab to avoid clutter. Failed preview builds appear under **Failed** on the fleet index.
