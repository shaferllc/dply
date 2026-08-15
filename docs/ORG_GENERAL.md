---
title: "Organization settings"
slug: org-general
category: "Organization"
order: 20
description: "Covers the organization's identity (name and icon), email defaults, data residency, API tokens, who can edit them, and owner-only deletion."
group: organization
---

# Organization settings

The **Settings** page holds the organization's identity and every workspace‑wide
default. Only owners and admins can change these.

## Identity

- **Name** — how the organization is labelled across the app, in breadcrumbs, and on invitations.
- **Icon** — an optional logo shown next to the name in the sidebar and organization switcher. When no icon is set, dply shows the organization's initials.
- **Handle**, **contact email**, **timezone**, and **description** round out the profile.

## Email defaults

What dply emails about for sites and servers in this organization:

- **Deploy-finish emails** — notify the deployer when a site's deploy completes or fails.
- **Server credentials** — host, port, and username go to the server creator when provisioning finishes. The SSH private key stays gated behind the dashboard.
- **Database credentials** — off by default, because a plain‑text password in a mailbox is an attack surface.

For *where* alerts are delivered (Slack, Discord, PagerDuty, webhooks, …), see
**Notification channels** in the sidebar. These toggles only control dply's own
transactional mail.

## Cloud alert destinations

When the organization has Cloud apps, this page also collects a Slack webhook URL
and extra recipient emails for deploy‑failed, restart, CPU, and memory alerts.
These are sent by **DigitalOcean's App Platform**, not by dply — which is why the
form accepts only a Slack webhook and emails, and why dply notification channels
cannot receive them. Org owners are always included.

## Edge data region

The preferred Cloudflare R2 region for buckets created on behalf of the
organization. Existing buckets stay where they are — the setting applies to
future Edge bootstraps only. Selecting **EU** creates buckets in Cloudflare's EU
jurisdiction, so data is stored in the EU and the EU jurisdiction header is set
on every request.

## API tokens

This page lists **every API token issued for the organization, across all
members**, with its prefix, last use, and expiry, and lets an admin revoke any of
them. Revocation is immediate and is recorded in the audit trail.

Tokens are **created** on your own **API keys** settings page, not here — that is
the one place that enforces the plan gate, caps abilities by your role, and
writes a creation audit record.

- Tokens carry **granular abilities**; grant only what the integration needs.
- A token's abilities are capped by the **role of the person who created it**. If you are a **deployer**, tokens you mint are limited to the deployer allowlist.
- Treat tokens like passwords — the secret is shown once. Rotate or revoke any token that may have leaked.

See the **[HTTP API](api)** guide for endpoints and authentication.

## Who can edit

| Role | Can edit settings |
| --- | --- |
| **Owner** | Yes |
| **Admin** | Yes |
| **Member / Deployer** | No (read‑only or hidden) |

Deleting the organization is an **owner‑only** action and lives in its own danger zone, separate from everyday settings.

## Related guides

- **[Organization overview](org-overview)** — the workspace map
- **[Organization roles & plan limits](org-roles-and-limits)** — permissions and caps
- **[HTTP API](api)** — endpoints and token authentication
- **[Billing & plans](billing-and-plans)** — subscription state
