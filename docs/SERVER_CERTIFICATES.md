# Certificate inventory

The **Certificates** section lists TLS certificates installed on the server — site ACME certs, manual uploads, and shared wildcard files.

## Inventory table

Columns typically include:

- **Hostname(s)** covered
- **Issuer** — Let's Encrypt, custom CA, etc.
- **Expires** — sort by soonest expiry
- **Path** on disk
- **Managed by dply** — yes for ACME automation

## Bulk renew

Select expiring certificates and queue **Renew** when ACME automation applies. Manual certs require upload or external renewal.

## Site-level certs

Per-site HTTPS settings live in the site workspace under **Certificates**. This server view is the fleet-wide inventory across all sites on the host.

## Alerts

Pair with **Health** and org notification channels for expiry warnings before browsers show errors.

## Related sections

- **Site → Certificates** — issue or reissue for one app
- **Site → DNS** — DNS-01 challenge credentials
- **Webserver** — engine serving these certs
