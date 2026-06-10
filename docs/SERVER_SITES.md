---
title: "Sites on this server"
slug: server-sites
category: "Servers"
order: 390
description: "Lists every BYO VM site hosted on this server with name, runtime, and status, plus how to add sites, plan limits, and site versus server scope."
group: servers
---

# Sites on this server

The **Sites** section lists every BYO VM site hosted on this server. Open a site to enter its workspace (deploy, routing, runtime, etc.).

## Site list

Each row typically shows:

- **Site name** and primary hostname
- **Runtime** — PHP, Ruby, static, Docker, etc.
- **Status** — active, deploying, suspended, etc.

Click a site name to open **General** in the site workspace.

## Create a site

Use **Add site** (or create from **Infrastructure → Sites**) and pick this server as the host. Site creation requires a finished server with working SSH.

## Plan limits

Your organization plan caps total sites. If you hit the limit, dply shows an upgrade prompt instead of creating another site.

## Site vs server scope

- **Server workspace** — host-level tools (firewall, webserver engine, system users)
- **Site workspace** — app-level tools (Git deploy, env vars, domains for one app)

Cron, daemons, and queue workers can be configured per site or at server scope depending on the section.

## Related sections

- **Run** — ad-hoc commands on the host
- **Webserver** — reverse proxy serving these sites
- **Manage** — runtimes and packages shared by sites
