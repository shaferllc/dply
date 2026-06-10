---
title: "Automation & API"
slug: org-automation
category: "Organization"
order: 40
description: "Covers wiring the organization to the outside world via API tokens, outbound notification webhooks, notification defaults, and who can manage them."
group: organization
---

# Automation & API

The **Automation** page is where you wire the organization up to the outside world: programmatic access through API tokens, outbound webhooks, and the notification defaults that fire automatically on the organization's behalf.

## API tokens

Organization **HTTP API tokens** let CI pipelines and scripts talk to dply — read servers and sites, trigger deploys, run commands, and more.

- Tokens carry **granular abilities**; grant only what the integration needs.
- A token's abilities are capped by the **role of the person who created it**. If you are a **deployer**, tokens you mint are limited to the deployer allowlist (for example, read servers/sites and deploy) even where the UI would otherwise offer broader scopes.
- Treat tokens like passwords — they are shown once. Rotate or revoke any token that may have leaked.

See the **[HTTP API](api)** guide for endpoints and authentication.

## Webhooks

Outbound **notification webhooks** POST a payload to a URL you control when events occur, so you can forward dply events into Slack, a status page, or your own systems.

- Each destination can be **enabled or disabled** without deleting it.
- The header shows how many webhooks exist and how many are currently enabled.

## Notification defaults

This page also carries organization‑wide notification and regional defaults — the automatic behaviours new resources inherit. For *where* alerts are delivered (email, Slack, etc.), see **Notification channels** in the sidebar.

## Who can manage

Automation is an **admin‑level** area — owners and admins can create tokens, manage webhooks, and change defaults. Deployers may create deploy‑scoped tokens only.

## Related guides

- **[HTTP API](api)** — endpoints and token authentication
- **[Organization roles & plan limits](org-roles-and-limits)** — how role caps token abilities
- **[Source control & deploy flow](source-control)** — connecting deploys to automation
