---
title: "Webserver workspace"
slug: server-webserver
category: "Servers"
order: 430
description: "Controls a server's primary reverse proxy (Nginx, Caddy, Apache, or OpenLiteSpeed) with health checks, engine switching, and config editing."
group: servers
---

# Webserver workspace

The **Webserver** section controls the primary reverse proxy on the server — Nginx, Caddy, Apache, or OpenLiteSpeed.

## Top tabs

| Tab | Purpose |
|-----|---------|
| **Overview** | Active engine, version, health |
| **Health** | Config test, process status |
| **Change** | Engine-specific settings, cache, modules |

## Switch engine

**Change → Switch** opens a confirm modal immediately, then loads a preflight plan asynchronously. Switching rewrites site vhosts and may require brief downtime.

Some engines show **Coming soon** in the picker until enabled in platform config.

## Config editor link

**Configuration** tab links to the full **Configuration** editor filtered to this engine (`from=webserver`). Use it for advanced file edits with diff and drift detection.

## Site vhosts

Per-site server blocks are edited in **Site → Web server config**. This section manages the global engine, modules, and defaults.

## Related sections

- **Edge proxy** — optional Traefik/HAProxy L7 add-on in front of the primary webserver
- **Site → Caching** — site-level cache headers
- **Certificates** — TLS termination at this layer
