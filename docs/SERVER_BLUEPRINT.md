---
title: "Server blueprint"
slug: server-blueprint
category: "Servers"
order: 90
description: "Capture a server's configuration (runtimes, webserver, firewall, cron) as a reusable org-level template that pre-fills the create-server wizard."
group: servers
---

# Server blueprint

The **Blueprint** section captures and reapplies server configuration templates — packages, webserver choice, and provision options — when creating similar hosts.

## Capture blueprint

From a healthy server, **Capture** saves:

- Installed **runtimes** and versions
- **Webserver** engine preference
- **Firewall** baseline
- **Cron** and **daemon** patterns (optional)

Store blueprints at org level for reuse on **Create server**.

## Apply on create

When provisioning a new server, pick a blueprint to pre-fill wizard steps. dply still runs the full install — blueprints guide defaults, not live cloning.

## Export and share

Blueprint JSON may be downloadable for documentation or compliance. Treat exports as sensitive if they reference internal hostnames.

## Related sections

- **Create server** wizard — apply blueprint
- **Manage** — runtime versions on the source server
- **Settings** — provider and region metadata (not in blueprint)
