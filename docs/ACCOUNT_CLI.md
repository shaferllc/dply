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

## Serverless functions

`dply serverless` drives managed functions from the terminal. It needs the **`serverless.read`** scope.

- `dply serverless list` — every function in the organization.
- `dply serverless status <name>` — limits, invoke URL, and a 24-hour health rollup.
- `dply serverless errors --site <name>` — failed invocations; `--watch` to poll. Exits 1 when any failed.
- `dply serverless invocation <id> --site <name>` — one invocation with the stdout/stderr captured from it.
- `dply serverless logs --site <name> --level error --follow` — the function's application log drain.

Failures for a function live in dply's invocation table rather than in the error events shown by `dply errors`, because the provider's activations API returns nothing — so `serverless errors` and `errors` are separate views, not duplicates.

## Checking errors

`dply errors` lists the open error events for a site — the same rows as the site workspace **Errors** tab, newest first. It needs the **`sites.read`** scope.

- `dply errors --site <id>` — newest open errors.
- `dply errors --full` — detail, remediation code, and the deep link back into the workspace.
- `dply errors --watch` — poll for new events (`--interval` ms).

The command **exits 1 when any error is open**, so `dply deploy --wait && dply errors` gates a CI job on a clean site.

## Related

- [[api]] — organization API tokens used for CI and automation.
