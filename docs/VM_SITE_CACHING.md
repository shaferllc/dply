---
title: "Site caching"
slug: vm-site-caching
category: "Sites & deploys"
order: 60
description: "Configure HTTP caching for a VM site, setting browser cache headers, proxy cache behavior, and bypass rules that merge into the site vhost."
group: sites
---

# Site caching

The **Caching** section configures **HTTP caching** for this site — browser headers and proxy cache behavior.

## Cache headers

Set:

- **Cache-Control** / **Expires** for static assets
- **Bypass rules** for dynamic paths (admin, API)

## Webserver integration

Rules merge into the site vhost. Purge actions may link to **Server → Webserver → Cache** for engine-level zones (Nginx, OLS).

## CDN interaction

When **CDN / Edge** is enabled, align TTL here with CDN defaults to avoid stale content.

## Apply

Changes queue webserver apply — same toast as **Routing** edits.

## Related sections

- **Web server config** — raw cache directives
- **Server → Caches** — Redis/Memcached backends (app session cache)
- **CDN / Edge** — edge TTL overrides
