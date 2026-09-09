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

## Working with more than one instance

A CLI token belongs to the instance that minted it, so pointing the CLI at a
second dply install is a swap of both URL and credential. `dply use` keeps them
side by side:

- `dply use` — pick from the instances you are signed in to.
- `dply use list` — show them, with the active one marked.
- `dply use <host>` — switch to a saved one (`dply use dply.io`).
- `dply use <url>` — add one; it signs you in and leaves the others saved.
- `dply use forget <host>` — drop a saved session.

`dply login --base-url <url>` adds an instance rather than replacing the current
one, and `dply logout` signs out of the active instance only.

## Sessions

Every approved device shows up under **CLI authentications**. **Revoke** a session to immediately invalidate that machine's token.

## Starting a project
### Declaring the kind in `dply.yaml`

A repository can say what it wants to be, and `dply init` stops asking:

```yaml
kind: serverless      # vm | cloud | edge | serverless
runtime: php
```

`kind:` is read **only when creating** the site. A deploy never migrates an
existing site from one product to another because a file changed — that is not
something a config edit should be able to do silently. `--kind` on the command
line still wins over the file.

`dply deploy` in a folder that is not linked to anything now offers to run
`dply init` rather than erroring: deploying and creating are different verbs,
but "deploy this folder" is a reasonable thing to type before the site exists.


`dply init` is the one command for a folder that is not on dply yet: it signs you
in if you are not, works out what the folder is, creates the site, and follows
the deploy to a live URL.

```
cd ~/work/acme/checkout
dply init
```

What it does, and why:

- **Not signed in?** It runs the device-flow approval inline and carries on — no
  restart, no second command.
- **Picks a kind.** All four are listed, best fit first, and an option that does
  not suit the folder still says why ("Edge — no static build output found")
  rather than disappearing. Kinds the CLI cannot create yet open the dashboard
  wizard prefilled.
- **Picks a source.** A folder with a reachable git remote deploys from git and
  gets push-to-deploy. A folder with no remote — or one dply cannot clone — is
  **uploaded**, and deploys exactly the same way afterwards. Both are fully
  supported; the only thing an uploaded source cannot have is push-to-deploy,
  because there is no remote to push to.
- **Tells you what will actually deploy.** dply builds `origin/<branch>`, not
  your working folder. If you have unpushed commits it says so and offers to
  push, deploy the remote as it stands, or upload the folder as-is.
- **Handles a monorepo.** Run it in `apps/api` and that subdirectory is what gets
  built.
- **Asks before secrets leave the machine.** A `.env` is offered by **key name
  only** — values are never printed — and goes straight into the site's encrypted
  environment rather than riding the upload.
- **Confirms once.** Everything it inferred is shown as one block (name, source,
  detected runtime, region, whose account it runs in, and where it lands against
  your plan's function quota) behind a single `[Y/n]`.

Useful flags — every prompt has one, so init works in a pipeline:
`--kind`, `--name`, `--region`, `--source git|upload`, `--delivery managed|byo`,
`--runtime`, `--template node|php|python`, `--exclude <path>`, `--env-file` /
`--no-env-file`, `--no-deploy`, `--yes`. Non-interactive without enough flags
exits 2 naming what is missing.

Re-running `dply init` in a folder that is already linked shows the site's status
and offers to deploy, open it, or create another — so it is always a safe thing
to type. Creating functions from the CLI needs the **`serverless.create`** scope;
if your session predates it, init offers to re-approve inline.

`dply link` remains the way to attach a folder to a site that **already exists**,
and now lists all four kinds rather than just BYO and Edge.

### Cloud apps

`dply init --kind cloud` creates a managed container app from the same folder.
It works the same way, with one structural difference: the container backend
clones and builds your repository itself, so **a cloud app needs a git remote**.
There is no upload path to fall back on, and init says so plainly rather than
failing at provision time — if you want to deploy a folder that has no remote,
that is what serverless is for.

init resolves the backend (DigitalOcean App Platform or AWS App Runner) from
your connected credentials, validates the region against that backend, and shows
the size tier, instance count and port before creating anything. Flags:
`--backend`, `--region`, `--size`, `--instances`, `--port`, `--dockerfile`.

### Sites on your own servers

`dply init --kind vm` creates a site on a server you already own. A site has to
live somewhere, so init lists your ready servers and asks which one — it will
**not** provision a server for you, since that is a bigger decision than a folder
should trigger. An organization with no servers is pointed at the dashboard (or
at serverless, which needs no server at all).

It infers the site type from the folder (composer.json → php, package.json →
node, index.html → static) and shows the document root it will use —
`/home/dply/<hostname-or-slug>`, plus `/public` for PHP — before creating
anything. Flags: `--server`, `--type`, `--hostname`, `--document-root`, `--port`.

Ordinary webserver hosts only for now. A Docker, Kubernetes, functions, or
headless server says so and points at the dashboard, where the host-specific
options live.



## Deploying

- `dply link` opens a picker for BYO and Edge sites.
- Any command that needs a site and wasn't given one opens the same kind of picker on a terminal.
- Edge: `dply edge status --wait` or `dply deploy --wait`.
- Server SSH: `dply server run --server <id> -- <command>` (needs the `commands.run` scope). Queued, so it is not cut off by the request timeout; `--no-wait` plus `--run <id>` to follow it later.
- Site artisan: `dply site artisan <site> -- migrate --force` (same scope). Runs as the site user in the site root with the site's PHP, prompts on destructive commands (`--yes` for CI), and requires site write access for the `env` family.
- **GitHub Actions:** create an org API token with `sites.deploy`, link the site once locally (`dply link --byo …`) and commit `.dply/site.json`, or pass `--site` in CI.

## Listing sites

`dply sites` is the one list. A dply **site** is a single model with four
products behind it, and the CLI names them the same way the platform does:

| kind | what it is |
| --- | --- |
| `vm` | a site on a server you own (BYO) |
| `cloud` | a managed container app (DO App Platform / AWS App Runner) |
| `edge` | a static/SSG site on the edge network |
| `serverless` | a managed function |

- `dply sites` — every site the token can see, with its kind.
- `dply sites --kind cloud` — one product (`vm`, `cloud`, `edge`, `serverless`).
- `dply sites checkout` — filter by name.
- `dply sites --json` — the same rows, unformatted.

Commands that only make sense for one product stay under that product —
`dply edge previews`, `dply serverless invocations`, `dply site deploy` — and
`dply site list` remains the VM-only list. Name a site of the wrong kind and the
CLI says where it actually lives rather than "not found".

Two spellings work everywhere: `dply sites errors acme` and `dply sites:errors
acme` are the same command. Any `dply a b` also reads as `dply a:b`.

## Serverless functions

`dply serverless` drives managed functions from the terminal. It needs the **`serverless.read`** scope.

- `dply serverless list` — every function in the organization.
- `dply serverless status <name>` — limits, invoke URL, and a 24-hour health rollup.
- `dply serverless errors --site <name>` — failed invocations; `--watch` to poll. Exits 1 when any failed.
- `dply serverless invocation <id> --site <name>` — one invocation with the stdout/stderr captured from it.
- `dply serverless logs --site <name> --level error --follow` — the function's application log drain.
- `dply serverless platform <name>` — what is actually deployed on the host: runtime, entry point, limits, code size, and the rest of the namespace. `--schedules` swaps in the cron triggers.
- `dply serverless credentials <name>` — the namespace key dply holds for the function, and whether the host still accepts it. `--set <key-id>:<secret>` stores a rotated key (verified first; the old one stays if the host rejects it). Writing needs **`serverless.write`**.
- `dply serverless env <name> list|set KEY=value|rm KEY|push --file .env|pull` — the Environment tab. Values are write-only, so `list` and `pull` show keys; `pull --values` prints the whole .env and needs **`sites.write`**. `push --replace` swaps the entire file (the tab's "Edit all"); without it each key is upserted. Changes reach the function on its next deploy.
- `dply serverless runtime <name>` — the Runtime tab in one command: `--memory`, `--timeout`, `--concurrency`, `--logs`; `--web-mode off|web|raw`, `--secure` / `--unsecure`, `--api-key on|off`; `--cors on|off` with `--cors-origins`, `--cors-methods`, `--cors-headers`, `--cors-credentials`, `--cors-max-age`; `--param KEY=value` (repeatable) / `--rm-param KEY` / `--params-final on|off`; `--log-provider`, `--log-token`, `--log-endpoint`; `--maintenance on|off`; `--keep-warm on|off`; `--rotate-secret`. Writes need **`serverless.write`**. Limits apply on the next deploy; HTTP settings are pushed to the live function.
- `dply serverless schedule <name>` — the Schedule tab: whether dply's minute-cadence scheduler tick is on, and the firing history behind it (`--limit`, `--failed`). `--enable` / `--disable` flips it, `--tick` fires one now. Exits 1 when a listed tick failed, so `--failed` reads as a check. Not to be confused with `platform --schedules`, which lists the *host's* cron triggers.
- `dply serverless workers <name>` — the Workers tab: whether the queue engine is on, the worker definitions, and each worker's derived status. `--enable` / `--disable` flips the engine, `--tick` fires one queue tick now, `--add <name> --command '<cmd>'` defines a worker (`--concurrency`, `--restart`), and `--start` / `--stop` / `--remove <name>` manage one. Writes need **`serverless.write`**; `--tick` runs your code, so it needs **`serverless.invoke`**.
- `dply serverless invoke <name>` — send a real test request (`--method`, `--path`, `--body`, `--header 'K: V'`). Recorded as a `source=test` invocation, so it shows up in `dply serverless invocations --source test` afterwards. Needs the **`serverless.invoke`** scope.

Failures for a function live in dply's invocation table rather than in the error events shown by `dply errors`, because the provider's activations API returns nothing — so `serverless errors` and `errors` are separate views, not duplicates.

## Notification routing

dply's notification model is one matrix: a **channel** × an **event key** × the
**subject** it fires for. The workspace splits that across tabs — Settings →
Notifications has the full grid, Errors and Monitor each edit their slice — while
`dply notifications` shows every group that applies to a subject at once.
Reading needs **`notifications.read`**; routing and test sends need
**`notifications.write`**.

- `dply notifications` / `dply sites:notifications <site>` — what fires for a site and where it goes.
- `dply notifications --server <id>` — the same for a server.
- `dply notifications channels` — channels this token can route to.
- `dply notifications events --subject site` — the event catalog.
- `dply notifications subscribe site.uptime.down --channel <id> --site <site>` — route one (or several at once).
- `dply notifications unsubscribe …` — stop routing them.
- `dply notifications test <channel>` — send that channel its test message.

Subscribing **adds** to a channel rather than replacing its selection, so two
people editing different events cannot clobber each other. Events are validated
against the subject: a serverless site is offered `serverless.*`, an Edge site
`edge.*`, and a server event on a site is refused.

## Uptime monitors

`dply uptime` (alias `dply monitor`) is the workspace **Monitor** tab from a
terminal, for **all four kinds** of site. Reading needs **`sites.read`**;
probing needs **`sites.write`**.

- `dply uptime` / `dply sites:uptime <site>` — every monitor: status, HTTP code, latency, probe region.
- `dply sites:uptime:history <site>` — 24h / 7d / 30d uptime and recent incidents; `--monitor <id>` for one.
- `dply uptime check <id>` — probe now; `--all` probes every monitor on the site.
- `dply uptime <site> --watch` — print each status change as it happens (`--interval` ms).

Like `dply errors`, it **exits 1 while any monitor is down**, so
`dply deploy --wait && dply uptime --no-prompt` gates on a site that is actually
answering. Leave the monitor id off on a terminal and you get a picker; when the
list shows something down, it offers to re-check it.

## Checking errors

`dply errors` lists the open error events for a site — the same rows as the site
workspace **Errors** tab, newest first. It works for **all four kinds** of site,
because an error event belongs to the site whatever the site runs on. It needs
the **`sites.read`** scope.

- `dply errors` — the linked site, or a picker when there is nothing to go on.
- `dply errors acme` / `dply errors --site <id>` — by name (across all kinds) or ID.
- `dply errors --full` — detail, remediation code, and the deep link back into the workspace.
- `dply errors --watch` — poll for new events (`--interval` ms).
- `dply errors --category ssl,deploy` — filter by category.

The command **exits 1 when any error is open**, so `dply deploy --wait && dply errors` gates a CI job on a clean site.

### Acting on an error

The three actions on the workspace **Errors** tab also work from the terminal,
and run through the same code, so the two can't drift:

- `dply errors dismiss <id>` — clear one; `--all` clears every open error. Needs **`sites.write`**.
- `dply errors retry <id>` — re-run the operation that failed. Only some categories are retryable. Needs **`commands.run`**.
- `dply errors fix <id>` — queue the catalogued remediation (`--action <key>` picks a specific one). Needs **`commands.run`**.

Leave the id off and you get a picker. On a terminal, plain `dply errors` goes
one better: pick an error from the list it just printed and it offers exactly
the actions that error supports — detail, retry, the known fix, open in the
dashboard, dismiss. Some fixes are manual (they link to a settings page rather
than running a script); those say so instead of queueing.

In scripts nothing prompts: a pipe, `--json`, `--no-prompt`, or `DPLY_NO_PROMPT=1`
keeps the plain print-and-exit behaviour.

## Related

- [[api]] — organization API tokens used for CI and automation.
