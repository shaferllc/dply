# Edge domains

The **Domains** section controls how visitors reach your Edge site.

## Default hostname

Every Edge site receives a **dply Edge URL** (e.g. `{slug}.on-dply.site`) as soon as the first deploy succeeds. Copy it from the **Default hostname** card or the Overview hero.

Until the first deploy completes, the hostname shows **Pending first deploy**.

## Custom domains

Attach your own hostnames (e.g. `www.example.com`):

1. Click **Attach domain** and enter the hostname.
2. Add the shown **CNAME target** at your DNS provider (point the hostname at the dply Edge hostname).
3. Click **Verify DNS** on the domain row.

### DNS status badges

| Status | Meaning |
|--------|---------|
| **Pending DNS** | CNAME not detected yet |
| **Ready** | DNS verified; traffic can route |
| **Failed** | Verification failed — check CNAME and proxy settings |

Use **Copy** next to the CNAME target for quick paste into DNS panels.

### SSL

HTTPS is provided when traffic is proxied through Cloudflare on your zone (BYO delivery) or via the managed dply Edge zone.

## Remove a domain

Click **Remove** on a custom domain to detach it from this site. DNS records at your provider are not deleted automatically.

## BYO Cloudflare notes

When delivery uses **your Cloudflare account**, ensure the hostname’s zone is on Cloudflare and orange-cloud (proxied) as required by your setup.

## Preview sites

Preview deployments get their own default hostname. Custom domains are typically not attached to previews; use the preview URL from the **Previews** section or fleet index.
