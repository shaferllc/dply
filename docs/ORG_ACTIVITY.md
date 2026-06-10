---
title: "Activity"
slug: org-activity
category: "Organization"
order: 30
description: "Describes the organization-wide audit trail: what each entry logs, filtering and search, compliance export, and how it differs from system logs."
group: organization
---

# Activity

The **Activity** page is the audit trail for the whole organization. Every meaningful change — a server created, a member invited, a credential rotated, a deploy triggered — is recorded here so you can answer *who did what, and when*.

## What gets logged

Each entry typically shows:

- **Actor** — the user or API token that performed the action
- **Action** — the verb and its subject (for example, "invited a member" or "deleted a server")
- **Family** — the area the action belongs to (members, billing, servers, sites, …)
- **Time** — timestamp in the organization's timezone
- **Tone** — success, info, warning, or danger, so high‑impact events stand out

## Filter and search

- **Filter by family** to narrow to one area (for example, only membership changes).
- **Search** by action or subject to find a specific event.

The header shows at‑a‑glance counts: total events and how many families have recent activity.

## Compliance export

Admins can export the activity log for compliance and record‑keeping. The export captures the audit entries so you can hand them to auditors or archive them outside dply.

## Activity vs system logs

| **Activity** (this page) | **Logs** (per server/site) |
| --- | --- |
| dply control‑plane actions | Raw log files on the host |
| Who changed what in the workspace | `auth.log`, nginx/php error logs |
| Organization‑wide audit trail | Scoped to one server or site |

## Who can view

Activity is an **admin‑level** view — owners and admins of the organization can see it.

## Related guides

- **[Organization overview](org-overview)** — the workspace map
- **[Organization roles & plan limits](org-roles-and-limits)** — who counts as an admin
- **[Members](org-members)** — manage the people who appear as actors here
