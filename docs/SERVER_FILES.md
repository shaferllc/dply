---
title: "Remote files"
slug: server-files
category: "Servers"
order: 210
description: "Browse, edit, upload, and download files on a server outside the Configuration allowlist, with ownership resets and confirmation-guarded deletes."
group: servers
---

# Remote files

The **Files** section browses and edits files on the server outside the strict **Configuration** allowlist.

## Browser

Navigate directories under permitted roots (deploy home, site paths, logs). Upload and download where enabled.

## Edit and permissions

Saving may offer **Reset ownership** to `:effective_user:www-data` for VM PHP sites. Confirm before chmod/chown on production paths.

## Safety

Destructive deletes use in-product confirmation modals — not browser `confirm()`.

## Coming soon preview

When gated, shows a teaser file browser without live SSH listing.

## Related sections

- **Configuration** — validated config files with diff/drift
- **Site → Deploy** — release directory layout for atomic deploys
- **Console** — quick `cat` / `less` for one file
