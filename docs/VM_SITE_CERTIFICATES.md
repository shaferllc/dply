---
title: "Site certificates"
slug: vm-site-certificates
category: "Sites & deploys"
order: 80
description: "Issue and renew HTTPS certificates for a site's hostnames via ACME (Let's Encrypt), covering HTTP-01, DNS-01, and wildcard certificate flows."
group: sites
---

# Site certificates

The **Certificates** section issues and renews **HTTPS certificates** for this site's hostnames via ACME (Let's Encrypt).

## Certificate list

Each row shows:

- **Domain** covered
- **Status** — pending, issued, failed, expiring
- **Method** — HTTP-01 or DNS-01
- **Expires** — renewal date

## Issue or renew

Click **Obtain certificate** or **Renew** on a row. DNS-01 may require fields from **DNS** and manual record confirmation at your provider.

## Wildcard certs

Wildcard issuance requires DNS-01 with a valid apex on the chosen credential.

## Server inventory

See all certs on the host under **Server → Certificates**.

## Related sections

- **Routing → Domains** — hostnames needing TLS
- **DNS** — challenge records
- **Web server config** — SSL directives in vhost
