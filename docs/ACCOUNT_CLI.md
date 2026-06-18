---
title: "CLI"
slug: account-cli
category: "Account"
order: 630
description: "Install the dply CLI, sign in once with device-flow login, and manage the CLI sessions tied to your organizations."
group: account
---

# CLI

**Profile → CLI** is where you install the command line, authenticate, and review every CLI session tied to your organizations.

> Managing CLI authentications requires **org admin** access.

## At a glance

- **Sessions** — active devices signed in to the CLI.
- **Organizations** — the organizations you administer.
- **Last used** — your most recent CLI sign-in.

## Install

The CLI is hosted by **this dply instance — not npm**. The install script downloads `/cli/dply-cli.tgz` and installs it globally. **Node 18+** is required.

## Sign in

Run `dply login` — your browser opens here, you approve the device once, and the terminal drops into `dply shell`. Each approval creates a **session** listed on this page. If you need more scopes later, run `dply auth refresh` (same browser approval, new token on that machine).

## Sessions

Every approved device shows up under **CLI authentications**. **Revoke** a session to immediately invalidate that machine's token.

## Deploying

- `dply link` opens a picker for BYO and Edge sites.
- Edge: `dply edge status --wait` or `dply deploy --wait`.
- Server SSH: `dply server run --server <id> <command>` (needs the `commands.run` scope).
- **GitHub Actions:** create an org API token with `sites.deploy`, link the site once locally (`dply link --byo …`) and commit `.dply/site.json`, or pass `--site` in CI.

## Related

- [[api]] — organization API tokens used for CI and automation.
