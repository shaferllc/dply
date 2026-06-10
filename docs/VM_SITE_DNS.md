---
title: "Site DNS automation"
slug: vm-site-dns
category: "Sites & deploys"
order: 140
description: "How the DNS section picks a provider credential and apex zone so dply can manage preview hostnames, ACME DNS-01 challenges, and primary A/CNAME records."
group: sites
---

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
