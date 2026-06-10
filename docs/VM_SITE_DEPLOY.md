---
title: "Site deploy"
slug: vm-site-deploy
category: "Sites & deploys"
order: 130
description: "How the Deploy section controls when and how code reaches a site, covering deploy now, atomic vs simple strategy, webhooks, deploy hooks, and CLI linking."
group: sites
---

# Site deploy

The **Deploy** section controls **how** and **when** code reaches the server.

## Deploy now

**Deploy** queues a Git fetch and release build. Watch progress in the deploy banner and **Logs**. Use **`dply deploy --follow`** from the CLI for the same flow locally.

## Strategy

- **Atomic** — new release directory, flip `current` symlink (zero-downtime)
- **Simple** — update live checkout in place

Toggle from **Settings** or here depending on UI layout.

## Webhooks

Copy the **deploy hook URL** for GitHub/GitLab/Bitbucket push events. Validates signature or shared secret per provider setup.

## Deploy hooks

Shell scripts run at phases (`before`, `after`, etc.) during deploy. **`dply.yaml` `deploy_hooks`** sync after each deploy.

## Link file

Local projects use **`.dply/site.json`** from **`dply link`** so **`dply deploy`** targets this site.

## Related sections

- **Repository** — branch and Git remote
- **Environment** — vars needed at build/runtime
- **Notifications** — deploy finish emails
