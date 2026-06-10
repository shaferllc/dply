---
title: "Projects"
slug: projects-overview
category: "Organization"
order: 40
description: "How projects group servers, sites, and member access inside an organization — creating them, assigning roles, and using labels and saved views to stay organized."
group: organization
---

# Projects

A **project** is a workspace inside an organization. It groups the servers, sites, and people behind a single initiative — a client engagement, a product, or an environment — so the right members see the right resources without giving everyone access to the whole organization.

Projects live under the organization you have selected in the header. Switch organizations to manage a different set of projects.

## What a project holds

Each project rolls up three things:

- **Servers** — the machines attached to the project.
- **Sites** — the applications deployed across those servers.
- **Members** — the people invited to the project, each with a role that controls what they can do.

The projects list shows these counts per row so you can see a project's footprint at a glance.

## Creating a project

Use **New project** on the projects page. You only need a name; a short description is optional but helps teammates understand the project's purpose. After it's created, open the project to attach servers and sites and invite members.

You need permission to create projects in the current organization. If you don't see the **New project** button, ask an organization admin to grant access or create the project for you.

## Roles

Every member belongs to a project with a **role**. Your own role for each project is shown in the **Your role** column. Roles determine who can change resources, manage members, and delete the project. Assign the least-privileged role that still lets a member do their work.

## Labels

**Labels** are lightweight tags you attach to projects — for example `production`, `client`, or `internal`. Once a project is labeled you can filter the list to a single label, which is useful when an organization runs many projects at once.

## Filtering and saved views

The filter bar lets you narrow the list by:

- **Search** — matches a project's name, description, or notes.
- **Label** — limits the list to one label.
- **My role** — shows only projects where you hold a specific role.

When you land on a combination of filters you reach for often, give it a name and **Save view**. Saved views appear as pills above the list so you can re-apply them in one click. Saved views are personal to you within the organization.

## Related

- [Roles & limits](/docs/org-roles-and-limits) — how organization-level roles and plan limits interact with project membership.
