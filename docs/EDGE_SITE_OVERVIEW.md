---
title: "Edge site overview"
slug: edge-site-overview
category: "Edge"
order: 40
description: "The Overview tab for a production Edge site, covering the hero bar, delivery, source, custom domains, traffic and billing cards, recent deploys, and preview children."
group: edge
---

# Edge site overview

The **Overview** tab is the home screen for a production Edge site. Preview child sites show a reduced sidebar but keep Overview.

## Hero bar

The top hero shows:

- **Live URL** — open the site in a new tab
- **Deploy** — trigger a new production deploy
- Status badge (active, building, failed, etc.)

## Delivery card

Summarizes where the site is published:

- **Backend** — Dply Edge (managed) or your Cloudflare account name
- **Hostname** — default edge delivery hostname
- **Worker / Zone / Routes** — shown for managed delivery when configured

Use this to confirm the site is on the expected backend before sharing URLs.

## Source card

Shows the connected Git repository, production **branch**, and framework type (**Static / SSG** or hybrid indicator).

Link **View build & deploy settings** to open **Build**, **Deploy triggers**, or **Delivery** as needed.

## Custom domains card

Lists hostnames attached to this site. Click **Manage** to open the **Domains** section for DNS verification and new attachments.

## Traffic and billing cards

Quick snapshots with links to **Traffic & analytics** and **Billing & usage** (hidden on preview child sites).

## Recent deploys

A compact deploy history table with status, commit, and time. Open **Deploys** for full history, rollback, and ref picker.

## Preview child sites

If this site is a PR/branch preview, Overview focuses on deploy status and domains. Fleet-level analytics and billing tabs are hidden; use the parent site for org-wide Edge usage.
