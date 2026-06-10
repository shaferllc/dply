---
title: "Site backups"
slug: vm-site-backups
category: "Sites & deploys"
order: 50
description: "Links to server backups with the site selected to configure scheduled database dumps, destinations, and restore caution for shared databases."
group: sites
---

# Site backups

The **Backups** section links to **Server → Backups** with this site selected — database backup jobs for engines this app uses.

## What you configure

On the server backups page:

- **Schedule** — dump frequency
- **Databases** — include schemas this site depends on
- **Destination** — on-server path or org S3

## Restore caution

Restoring a shared database affects **all sites** using that DB — confirm in the modal.

## Site data only

File-level site backups (uploads, storage) are not the default scope — focus is **database dumps**. Use provider snapshots for full VM backup.

## Related sections

- **Databases** — create DB and user
- **Environment** — `DB_*` connection names
- **Laravel** — migration state after restore
