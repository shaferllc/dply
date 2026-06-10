---
title: "Security digest"
slug: server-security
category: "Servers"
order: 350
description: "A digest summarizing SSH and auth findings from system logs, including failed logins, suspicious IPs, and authorized key changes, with hardening links."
group: servers
---

# Security digest

The **Security** section (sidebar **Security**) summarizes SSH and auth findings from system logs — failed logins, suspicious IPs, and recent key changes.

## Digest cards

Typical panels:

- **SSH failures** — recent `auth.log` entries grouped by source IP
- **Successful root logins** — unexpected privileged access
- **Authorized keys changes** — sync events from dply or manual edits

Use this for weekly operator review, not real-time IDS.

## Drill-down

Click a finding to see raw log excerpts (truncated). Heavy scans run via `wire:init` so the page loads quickly.

## Hardening actions

Links point to:

- **Firewall** — block abusive IPs
- **SSH keys** — rotate or remove keys
- **Access graph** — who can reach the host

## Coming soon preview

When gated, a teaser explains the security digest without scanning logs.

## Related sections

- **SSH access graph** — time-boxed sessions and key lineage
- **Firewall** — UFW rules and templates
- **Activity** — audit trail of workspace actions
