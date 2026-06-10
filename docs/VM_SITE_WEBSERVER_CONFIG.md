---
title: "Web server config editor"
slug: vm-site-webserver-config
category: "Sites & deploys"
order: 280
description: "Edit a site's live vhost configuration with basics and advanced editors, diff and drift detection, apply locking, and engine-specific templates."
group: sites
---

# Web server config editor

The **Web server config** section edits this site's **vhost configuration** on the live server with validation, diff, and drift detection.

## Layered editor

- **Basics** — domain, root path, index files, PHP handling
- **Advanced** — raw snippets with before/after injection points
- **Diff** — compare dashboard draft vs live file on server

## Load live config

dply fetches the active config over SSH. A **drift** badge appears when the server file differs from last apply.

## Apply lock

Saving queues apply with an audit entry. Concurrent edits are blocked while a job runs.

## Engine-specific

Templates differ for **Nginx**, **Caddy**, **Apache**, and **OpenLiteSpeed**. Engine comes from **Server → Webserver**.

## Related sections

- **Routing** — hostnames referenced in vhost
- **Caching** — cache headers and zones
- **Server → Configuration** — global engine files
