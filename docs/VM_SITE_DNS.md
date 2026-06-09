# Site DNS automation

The **DNS** section picks the **server provider credential** used for DNS automation on this site.

## Provider and zone

Select:

- **Credential** — DigitalOcean or Cloudflare org credential (where supported)
- **DNS zone (apex)** — validated apex domain for record creation

Wrong apex blocks automated records for previews and certificates.

## What dply manages

dply can align:

- **Preview/testing** hostnames when using the testing-domain pool fallback
- **DNS-01** ACME challenges when HTTP-01 is insufficient
- **A/CNAME** records for primary domain attachment (provider-dependent)

Full arbitrary DNS editing is not in scope — focus on the apex you control.

## Related sections

- **Routing → Domains** — hostnames attached to the site
- **Certificates** — challenge method fields
- Org **Server providers** — add credentials at `/credentials`
