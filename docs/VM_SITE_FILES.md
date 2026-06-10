---
title: "Site files"
slug: vm-site-files
category: "Sites & deploys"
order: 20
description: "Browse, edit, and download a site's files in the dashboard over SSH as the site user, scoped to the site's paths with no terminal or SFTP needed."
group: sites
---

# Site files

Browse and edit your site's files right in the dashboard over SSH — no terminal or SFTP client required.

## Browser

Navigate the site tree as the site's effective login user. Listing is scoped to the site's own paths, so you can't wander outside it.

## Editing and downloads

Edit text files below the configured size limit, and download files up to the download limit. Larger files are listed but not opened inline to keep the panel responsive.

## Safety

Access runs over SSH as the site user, so permissions match exactly what your app sees at runtime. Destructive actions confirm in-product before running.

## Related sections

- **Repository** — the deployed code and release directories
- **Deploy** — how releases are laid out on disk
- **Environment** — manage the `.env` file safely
