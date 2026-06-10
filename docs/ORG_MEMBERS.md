---
title: "Members"
slug: org-members
category: "Organization"
order: 50
description: "Explains the organization member directory: roles and their permissions, inviting people, changing or removing roles, seats and limits, and teams."
group: organization
---

# Members

The **Members** page is the directory of people in the organization. From here admins invite new people, assign roles, and remove access.

## Roles

Every member has one role in this organization. The same person can hold a different role in another organization.

| Role | What they can do |
| --- | --- |
| **Owner** | Everything, including deleting the organization and owning billing. |
| **Admin** | Day‑to‑day control equal to an owner, **except** deleting the organization. |
| **Member** | Full participant for servers, sites, and credentials not restricted to admins. |
| **Deployer** | Deploy‑focused access; API tokens they create are limited to deploy abilities. |

See **[Organization roles & plan limits](org-roles-and-limits)** for the complete permission matrix.

## Inviting people

- Send an invitation by email and pick the role they'll receive on acceptance.
- Pending invitations appear alongside active members until accepted or revoked.
- The header shows counts for **members**, **pending invitations**, and **teams**.

## Changing roles and removing members

Admins can change a member's role or remove them. Ownership is special — transferring it moves billing responsibility, and only an owner can hand it off or delete the organization.

## Seats and limits

If seat billing is configured, the number of members plus pending invitations may be capped. When both an environment cap and Stripe seats exist, the **lower** limit applies. See **[Billing & plans](billing-and-plans)**.

## Teams

To scope servers, sites, and notifications to a subset of people, group members into **[Teams](org-teams)**. A member can belong to multiple teams.

## Who can manage

Inviting, role changes, and removal are **admin‑level** actions. Members and deployers see the directory but cannot change it.

## Related guides

- **[Organization roles & plan limits](org-roles-and-limits)** — the permission matrix
- **[Teams](org-teams)** — group members for scoped access
- **[Billing & plans](billing-and-plans)** — seats and plan caps
