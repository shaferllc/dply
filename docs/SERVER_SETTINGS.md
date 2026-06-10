---
title: "Server settings"
slug: server-settings
category: "Servers"
order: 370
description: "Server-level metadata and connection preferences, including general details, provider info, SSH connection config, and danger actions like delete."
group: servers
---

# Server settings

The **Settings** section holds server-level metadata and connection preferences.

## General

Edit:

- **Display name** — workspace label
- **Notes** — operator comments
- **Project** — workspace grouping container

## Provider

Read-only fields for cloud provider, region, size, and instance ID. **Managed servers** on dply's platform token show hosting backend context.

## SSH connection

Configure:

- **SSH user** — deploy user vs root for repairs
- **Port** — non-default SSH port
- **Reconnect** — re-run key sync and connectivity probe

## Danger actions

**Delete server** removes the control-plane record. Optionally destroy the cloud VM when using a linked provider credential. Sites on the server must be deleted or moved first.

## Related sections

- **Overview** — status and provision journey
- **SSH keys** — authorized keys for this host
- Org **Server providers** — credentials for provisioning
