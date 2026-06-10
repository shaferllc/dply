---
title: "Teams"
slug: org-teams
category: "Organization"
order: 60
description: "Explains how teams group members to scope servers, sites, and notifications, how teams relate to roles, and who can manage them."
group: organization
---

# Teams

**Teams** group members so you can scope servers, sites, and notifications to the right people instead of giving everyone access to everything. A member can belong to multiple teams.

## Why teams

- **Scope access** — point a team at the servers and sites it owns.
- **Targeted notifications** — route alerts to the team responsible, not the whole organization.
- **Organize by shape** — by product, environment (staging vs production), or client.

The header shows counts for **teams**, total **member slots** across teams, and the organization's overall **member** count.

## How teams relate to roles

Teams and roles are **independent**:

- A member's **role** (owner / admin / member / deployer) sets *what kinds of actions* they can take. See **[Organization roles & plan limits](org-roles-and-limits)**.
- A **team** sets *which resources and notifications* are in their scope.

A person keeps their role regardless of how many teams they're in.

## Managing teams

Admins can create teams, rename them, add or remove members, and delete teams. Adding someone to a team does not change their organization role — add them to **[Members](org-members)** first, then assign them to teams.

## Who can manage

Creating and editing teams is an **admin‑level** action. Members and deployers can see teams they belong to.

## Related guides

- **[Members](org-members)** — invite people and set roles
- **[Organization roles & plan limits](org-roles-and-limits)** — what each role can do
- **[Organization overview](org-overview)** — the workspace map
