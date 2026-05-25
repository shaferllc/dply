# Edge fleet index

The **Edge sites** page (`Infrastructure → Edge`) lists every Edge app in your current organization.

## Summary cards

At the top you see counts for:

- **All sites** — every Edge site and preview child
- **Active** — live sites serving traffic
- **Provisioning** — sites still building or publishing

## Filter tabs

Use the pill tabs to narrow the list:

| Tab | Shows |
|-----|--------|
| **All** | Every Edge site |
| **Previews** | Preview deployments (PR/branch children) |
| **Provisioning** | Sites mid-build |
| **Failed** | Sites whose last deploy or provision failed |

Click a site row to open its workspace.

## Deploy a new app

Click **Deploy an edge app** to start the create wizard (requires Edge to be enabled for your org).

## Delete a site

From the fleet index you can delete sites without opening the workspace:

1. Click **Delete** on the site row.
2. Choose **Delete now**, **Delete in 30 minutes**, or schedule a time.
3. Confirm in the modal.

Deletion queues teardown of CDN entries, deployments, and routing. This cannot be undone.

## Coming soon state

If Edge is not enabled for your organization, the page shows a **Coming soon** message. The Edge link remains in navigation for visibility; contact your admin or dply support to enable the product.

## Back navigation

From any Edge site workspace, use **Back to Edge sites** in the sidebar footer to return here.
