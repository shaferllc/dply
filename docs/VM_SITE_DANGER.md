---
title: "Delete site"
slug: vm-site-danger
category: "Sites & deploys"
order: 120
description: "How the Danger zone permanently removes a BYO VM site from dply, what cleanup runs, plan-slot impact, and what to back up beforehand."
group: sites
---

# Delete site

The **Danger zone** permanently removes a BYO VM site from dply and optionally cleans server paths.

## What deletion does

- Removes control-plane site record
- Deletes webserver vhost configuration
- May remove deploy directory and releases (confirm scope in modal)
- Stops deploy webhooks for this site

**This cannot be undone.**

## Delete from workspace

1. Open **Danger zone**.
2. Click **Delete site**.
3. Read the confirmation modal — it names the site and server.
4. Confirm to queue cleanup jobs.

## Plan limits

Deleting frees a **site slot** on your org plan immediately after removal completes.

## Before you delete

- Export env vars and **Logs** you need
- Remove DNS records at your provider
- Notify teammates — the URL will stop serving

## Related sections

- **Settings** — suspend instead of delete for temporary downtime
- **Server → Sites** — see remaining apps on the host
- **Repository** — disconnect Git after delete if hooks remain at provider
