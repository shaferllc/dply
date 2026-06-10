---
title: "Site resources"
slug: vm-site-resources
category: "Sites & deploys"
order: 30
description: "Attach or detach managed databases and caches for a site, merging connection variables into the .env and queuing a redeploy automatically."
group: sites
---

# Site resources

Every backing service attached to this app — managed databases and caches — in one place. Attach more in a click; detach in place.

## Databases and caches

Attach an existing managed Postgres, MySQL, or Redis instance, or create a new one inline. On attach, the connection environment variables are merged into the site's `.env` and a redeploy is queued automatically so the app picks them up.

## Detaching

Detach a resource when the app no longer needs it. The connection variables are removed from the site; the underlying managed instance itself is not deleted.

## Related sections

- **Environment** — view the merged connection variables
- **Server → Databases** — manage the engines themselves
- **Deploy** — the redeploy triggered on attach
