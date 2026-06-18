---
title: "Backup destinations"
slug: account-backup-destinations
category: "Account"
order: 620
description: "Register external storage (buckets and remotes) once, then reuse it across every server when scheduling backups."
group: account
---

# Backup destinations

**Profile → Backup destinations** is a reusable library of external storage. Add a bucket or remote here once, then pick it when creating a backup schedule on any server — you don't re-enter credentials per server.

## At a glance

- **Destinations** — saved destinations, reusable across servers.
- **Providers** — how many storage providers are supported.
- **Scope** — whether a destination is **Personal** (just you) or **Shared in this org**.

## Adding a destination

Open **Add destination**, choose a **Storage provider**, give it a name, and enter the bucket/remote credentials. Saved destinations appear in the **Library**, where you can search them by name, edit the label or credentials, or delete one.

## Using a destination

When you create a backup schedule on a server, the destination picker lists everything saved here. A destination is just the *where* — the schedule (what to back up and how often) lives on the server.

> Deleting a destination stops any schedule pointing at it from firing until you pick a new one.

## Related

- [[server-backups]] — scheduling database backups on a server against one of these destinations.
