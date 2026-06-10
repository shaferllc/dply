---
title: "System users"
slug: server-system-users
category: "Servers"
order: 420
description: "Lists Unix accounts on a server and manages deploy-related users, including per-site system users, creation, and removal guards."
group: servers
---

# System users

The **System users** section lists Unix accounts on the server and manages deploy-related users.

## User table

Columns typically include:

- **Username** — `dply`, `root`, site-specific users
- **UID / home** — home directory path
- **Shell** — login shell
- **Sites** — apps running as this user

## Site system user

Each VM site may run as a dedicated user configured in **Site → System user**. Changes may require redeploy or permission fixes.

## Create and remove

Add users for custom workflows; removal confirms no active sites depend on the account.

## Feature flag

Requires **`workspace.system_users`**. Hidden when SSH is unavailable.

## Related sections

- **Site → System user** — per-app Unix account
- **SSH keys** — keys authorized for each user
- **Files** — ownership reset to `:user:www-data`
