---
title: "Site routing and domains"
slug: vm-site-routing
category: "Sites & deploys"
order: 240
description: "Manage how HTTP requests reach a site through primary domain, aliases, redirects, and preview hostnames, with queued webserver applies."
group: sites
---

# Site routing and domains

The **Routing** section manages how HTTP requests reach your app — primary domain, aliases, redirects, and preview hostnames.

## Domains sub-tab

- **Primary domain** — canonical hostname; renames happen here
- **Aliases** — additional hostnames serving the same site
- **Testing hostname** — dply preview pool when no custom domain yet

## Redirects sub-tab

Configure path and host redirects applied at the webserver layer. **`dply.yaml` `redirects`** sync on deploy may override or merge — check deploy logs.

## Aliases vs domains

**Domains** are full hostnames with TLS. **Aliases** often include www/non-www pairs or legacy hostnames.

## After changes

Webserver apply is **queued** — toast says *Webserver config queued.* Wait for the job before testing URLs.

## Related sections

- **DNS** — automation for apex and challenges
- **Certificates** — TLS for each hostname
- **Web server config** — low-level vhost edits
