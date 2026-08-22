# `dply` — command-line interface for dply

Zero-dependency Node CLI for the dply REST API. Script Edge deploys,
BYO server operations, and more from your terminal.

## Install

The CLI is **hosted by your dply instance** (not npm). Each install downloads
the package from `/cli/dply-cli.tgz` on the same origin as the web app.

```sh
curl -fsSL https://your-dply.example/cli/install.sh | bash -s -- --login
```

Requires **Node 18+** and **npm** (npm installs the downloaded tarball globally).

When you pipe from your dply server, the script already knows your `APP_URL`.
`--login` opens the browser for device-flow authentication when install finishes.

```sh
curl -fsSL https://your-dply.example/cli/install.sh | bash -s -- --help
curl -fsSL https://your-dply.example/cli/install.sh | bash -s -- --login
```

Check the hosted version:

```sh
curl -fsSL https://your-dply.example/cli/version.json
```

## Update

New commands ship with the instance, not with a registry release — the tarball
is built on demand from the running app. So updating is one command:

```sh
dply update             # install what your instance is serving
dply update --check     # report only; exits 1 when your build differs
dply update --json      # {installed, serving, up_to_date, base_url}
dply --version          # what you are running right now
```

The check is an **equality**, not a greater-than: the instance is the source of
truth, so an instance that has rolled back rolls your CLI back too. `--force`
reinstalls the same version.

If the global install hits a permission wall, re-run with `sudo` or fall back to
the installer (`curl -fsSL https://your-dply.example/cli/install.sh | bash`).

### Developing on the CLI

Point the global `dply` at your checkout once, and every edit is live — no
reinstall, no repack:

```sh
cd packages/dply-cli
npm link                 # global `dply` becomes a symlink to this directory
which dply && ls -la $(which dply)   # confirm it resolves into your repo
```

Undo with `npm unlink -g @dply/cli`, then re-run the installer for a packed build.

While linked, `dply update` refuses and tells you to `git pull` instead —
installing the packed tarball would replace the symlink and quietly end dev
mode. `dply update --force` overrides that if you actually want the packed build.

### Self-hosted config

In `.env` on the dply app:

```env
APP_URL=https://dplyi.test
# Default — download from this app:
DPLY_CLI_INSTALL_METHOD=tarball
DPLY_CLI_NPM_PUBLISHED=false
```

After you publish `@dply/cli` to npm, set `DPLY_CLI_NPM_PUBLISHED=true` and
optionally `DPLY_CLI_INSTALL_METHOD=auto` to try npm first.

## Sign in (seamless device flow)

```sh
dply login --base-url https://your-dply.example
```

1. The CLI prints a short code and opens your browser to the dply instance.
2. Sign in if needed, confirm the code, pick your organization and scopes, click **Approve**.
3. The terminal saves the token and drops you into **`dply shell`** — press **Enter** or run **`menu`** to browse actions without memorizing commands.

Use `dply login --no-shell` in scripts/CI to skip the interactive shell.

Revoke CLI sessions from **Profile → CLI** in the web app. Run `dply auth refresh` (or `dply refresh`) to re-approve scopes when you need more permissions.

## Verify

```sh
dply whoami
dply menu            # numbered menus — type names or numbers
dply server list
dply site list       # BYO VM sites
dply shell           # re-open the interactive shell anytime
```

## Switch instances (`dply use`)

A dply token is minted by, and valid only for, one instance — so moving between
a local install and the hosted one is a swap of URL *and* credential. The CLI
keeps several signed-in instances and switches between them:

```sh
dply use                      # pick from the ones you are signed in to
dply use list                 # show them, active one marked
dply use dply.io             # switch to a saved instance
dply use https://dply.io     # add one — signs you in, keeps the others
dply use live                 # shorthand for the hosted instance
dply use forget dply.test     # drop a saved session
```

Instances are keyed by hostname, so there is no alias to invent or remember.
`dply login --base-url <url>` now *adds* an instance instead of replacing the
one you were already using, and `dply logout` signs you out of the active
instance only.

Config lives at `~/.dply/config.json`. A config written by an older CLI is
adopted on first use — it becomes an instance named after its own host and stays
active, so upgrading logs nobody out.

## Start a project (`dply init`)

One command from a folder to a live URL:

```sh
cd ~/work/acme/checkout
dply init
```

It signs you in if you are not, works out what the folder is, creates the site,
and follows the deploy until the URL answers.

**The kind menu never hides a path.** All four kinds are listed, best fit first,
and one that does not suit the folder still says why — `Edge — no static build
output found` — rather than vanishing. Kinds the CLI cannot create yet open the
dashboard wizard, prefilled.

**Git or upload, whichever the folder is.** A reachable remote deploys from git
and gets push-to-deploy. No remote — or one dply cannot clone — uploads the
folder instead, and that is a fully supported source, not a fallback: it gets the
same framework detection, build hooks, adapters, asset publishing and rollback.
The one thing it cannot have is push-to-deploy, because there is no remote to
push to. `dply deploy` re-uploads.

**It says what will actually deploy.** dply builds `origin/<branch>`, not your
working folder. With unpushed commits it offers to push, deploy the remote as it
stands, or upload the folder as-is — rather than letting you find out from the
deployed site. `dply deploy` keeps a lighter version of that check.

**Monorepos work.** Run it in `apps/api` and that subdirectory is what builds.

**Secrets are asked for, never assumed.** A `.env` is offered by key name only —
values are never printed — and goes into the site's encrypted environment rather
than riding the upload.

**One confirmation.** Name, source, detected runtime, region, whose account it
runs in, and where it lands against your plan's function quota, behind a single
`[Y/n]`.

Empty folder? It offers to write a hello-world (`--template node|php|python`)
and deploys that.

Flags — every prompt has one, so init runs in a pipeline:

```
--kind <vm|cloud|edge|serverless>   --name <name>        --region <slug>
--source <git|upload>               --delivery <managed|byo>
--runtime <auto|nodejs:20|php:8.4>  --template <node|php|python>
--exclude <path>                    --env-file <path> / --no-env-file
--no-deploy                         --yes
```

Non-interactive without enough flags exits 2 naming what is missing. Re-running
`dply init` in a linked folder shows the site and offers to deploy, open, or
create another — it is always safe to type.

Creating functions needs the **`serverless.create`** scope; a session older than
that is offered an inline re-approval.

`dply link` still attaches a folder to a site that **already exists**, and now
lists all four kinds rather than only BYO and Edge.

## Deploy a BYO site (hero workflow)

From your app repo:

```sh
dply link              # interactive picker (BYO + Edge)
dply deploy --follow   # queue deploy + stream logs when linked to BYO
dply site status       # last deployment summary
```

CI / GitHub Actions:

```sh
# Install + auth (see Profile → CLI for full workflow YAML)
dply login --token "$DPLY_TOKEN" --no-shell
dply deploy --sync --wait --idempotency-key "$GITHUB_SHA"
```

Edge linked repos: `dply deploy --wait` blocks until the deployment is live.

### Edge status

```sh
dply edge status              # linked site, or --site <id>
dply edge status --wait       # block until latest deploy finishes
```

### Serverless functions

Requires the **`serverless.read`** scope.

```sh
dply serverless list                          # every function in the org
dply serverless status checkout               # detail, limits, 24h health
dply serverless errors --site checkout        # every failed invocation
dply serverless errors --site checkout --watch
dply serverless invocations --site checkout --source web --limit 20
dply serverless invocation <id> --site checkout   # + captured log lines
dply serverless logs --site checkout --level error --follow
```

`serverless errors` reads **failed invocations** — every one, raw. That is where
a function's failures actually live: the platform's activations API returns
nothing, so dply's own invocation table is the only record. It exits 1 when
anything failed.

It is the drill-down under `dply errors`, not a rival. A failing streak also
folds into **one** open error event for the site, so `dply errors` (and the
site's Errors tab, and site-error notifications) tells you the function is
broken, and `dply serverless errors` tells you which requests broke. The folded
event closes by itself once the function serves a successful invocation again.

Two log surfaces, deliberately separate:

| Command | Shows |
| --- | --- |
| `serverless logs` | the site's application log drain (searchable, level-filtered, 30-day) |
| `serverless invocation <id>` | stdout/stderr captured from that one activation |

### Sites

One list, every kind. `Site` is a single model on the platform — a VM site, a
Cloud container app, an Edge site and a serverless function differ by attributes
— so `dply sites` shows them together with what each one is:

```sh
dply sites                     # vm · cloud · edge · serverless, one table
dply sites --kind cloud        # one kind (vm | cloud | edge | serverless)
dply sites checkout            # filter by name
dply sites --json
```

| kind | what it is |
| --- | --- |
| `vm` | a site on a server you own (BYO) |
| `cloud` | a managed container app (DO App Platform / AWS App Runner) |
| `edge` | a static/SSG site on the edge network |
| `serverless` | a managed function |

The API says which: `/sites` returns every server-backed site with a `kind`
field, and `/edge/sites` + `/serverless/sites` remain the scope-gated
product-specific views. Cloud needs no list endpoint of its own.

The product namespaces keep the verbs only they have — `dply edge previews`,
`dply serverless invocations`, `dply site deploy` — and `dply site list` stays
the VM-only list. Anything that works on a site regardless of where it runs
(`dply errors`) resolves a name across all three kinds, and naming the wrong
kind tells you where it actually lives:

```
$ dply site logs checkout-fn
"checkout-fn" is a serverless function, not a VM site — try
`dply serverless status checkout-fn`, or `dply errors checkout-fn`.
```

### Notifications

One matrix: a **channel** × an **event key** × the **subject** it fires for (a
site or a server). The workspace edits a slice per tab; the CLI shows every group
that applies to the subject at once. Reading needs **`notifications.read`**,
routing and tests need **`notifications.write`**.

```sh
dply notifications                     # what fires for the linked site, and where
dply sites:notifications acme          # by name, any kind of site
dply notifications --server <id>       # same, for a server
dply notifications channels            # what you can route to
dply notifications events --subject site
dply notifications subscribe site.uptime.down --channel <id> --site acme
dply notifications subscribe site.deployments site.ssl.expiring --channel <id>
dply notifications unsubscribe site.uptime.down --channel <id>
dply notifications test <channel>      # send that channel its test message
```

Subscribing **adds** to a channel instead of replacing its selection, so two
people routing different events can't clobber each other. Events are validated
against the subject: an Edge site is offered `edge.*`, a function
`serverless.*`, and a `server.*` event on a site is refused.

### Uptime monitors

`dply uptime` (alias `dply monitor`) is the workspace Monitor tab, for every kind
of site. Reading needs **`sites.read`**, probing needs **`sites.write`**.

```sh
dply uptime                        # status, HTTP code, latency, region per monitor
dply sites:uptime acme             # by name, any kind of site
dply sites:uptime:history acme     # 24h / 7d / 30d uptime + recent incidents
dply uptime history --monitor <id> # one monitor's rollup
dply uptime check <id>             # probe now · --all for every monitor
dply uptime --watch                # print each status change (--interval ms)
dply uptime --json
```

It **exits 1 while any monitor is down** (a monitor that has never been probed is
`unchecked`, not down, so a fresh site doesn't fail the gate):

```sh
dply deploy --wait && dply uptime --no-prompt
```

Leave the monitor id off on a TTY and you get a picker; when something is down,
the list offers to re-check it. Probes are unique per monitor for two minutes —
asking twice in that window probes once.

The **Platform** surface — what is actually running on the functions host:

```sh
dply serverless platform checkout             # action doc + namespace inventory
dply serverless platform checkout --schedules # cron triggers
dply serverless platform checkout --json

dply serverless invoke checkout                              # a real GET /
dply serverless invoke checkout --method POST --path /health --body '{"ping":1}'
dply serverless invoke checkout --header 'X-Token: secret'

dply serverless credentials checkout                  # which key dply holds
dply serverless credentials checkout --set <id>:<secret>   # store a rotated one
```

`invoke` runs your code and is recorded as a `source=test` invocation (it shows
up in `dply serverless invocations --source test`), so it needs the
**`serverless.invoke`** scope rather than `serverless.read`. It exits 1 when the
function answers with a failure.

```bash
dply serverless workers checkout                    # engine + worker table
dply serverless workers checkout --enable           # process queue jobs
dply serverless workers checkout --tick             # one queue tick, now
dply serverless workers checkout --add queue-default --command 'php artisan queue:work'
dply serverless workers checkout --stop queue-default
dply serverless workers checkout --remove queue-default
```

```bash
dply serverless schedule checkout                   # switch + recent ticks
dply serverless schedule checkout --enable          # run the scheduler tick
dply serverless schedule checkout --tick            # fire one now
dply serverless schedule checkout --failed --limit 50
```

```bash
dply serverless env checkout list                   # keys (values are write-only)
dply serverless env checkout set STRIPE_KEY=sk_live_x
dply serverless env checkout rm OLD_KEY
dply serverless env checkout push --file .env       # upsert each key
dply serverless env checkout pull --values > .env   # needs sites.write

dply serverless runtime checkout                    # limits, HTTP, toggles
dply serverless runtime checkout --memory 512 --timeout 60000
dply serverless runtime checkout --web-mode web --secure --cors on --cors-origins https://app.test
dply serverless runtime checkout --param STRIPE_MODE=live --rm-param OLD
dply serverless runtime checkout --maintenance on --keep-warm on
dply serverless runtime checkout --rotate-secret
```

`env` edits the same store as the Environment tab; new values reach the
function on its next deploy. `runtime` is the Runtime tab — every flag maps to
one field of a single PATCH, so several settings change in one call. Resource
limits apply on the next deploy; HTTP settings are pushed to the live function.

`schedule` is dply's own minute-cadence scheduler tick and its firing history;
it exits 1 when a listed tick failed. The functions host's cron triggers are a
different surface — `dply serverless platform <site> --schedules`.

`workers` is the Workers tab: the queue engine (one switch driving the
minute-cadence tick) and the named worker definitions. Workers are addressed by
name or id. Writes need **`serverless.write`**; `--tick` runs your code, so it
needs **`serverless.invoke`** and exits 1 when the tick reports a failure.

`credentials` reads the namespace key dply stores for the function and checks the
host still accepts it (only the key id is ever shown). `--set` replaces it after
you rotate on the host — the new key is verified before it sticks, and the old
one stays in place if the host rejects it. Writing needs **`serverless.write`**.

### Errors

Open error events for a site — **all four kinds**, since an error event belongs
to the site whatever the site runs on. Newest first. Requires the
**`sites.read`** scope.

```sh
dply errors                        # linked site — or pick one from a list on a TTY
dply errors acme                   # by name or ID (prefix is enough)
dply sites:errors acme             # same thing — any `a b` route also takes `a:b`
dply errors --full                 # detail, remediation code, deep link
dply errors --category ssl,deploy  # filter by category (comma-separated)
dply errors --category function_invocation   # just the broken-function events
dply errors --watch                # poll for new events (--interval ms)
dply errors --json                 # raw payload
```

With no site to go on, `dply errors` and `dply serverless errors` list what your
token can see and ask which one — the same picker every site-scoped command now
falls through to. Off a TTY (CI, pipes) they keep failing with exit 2 instead of
blocking, so `--site` stays required in scripts.

Reading is half of it — the same three verbs the workspace Errors view offers
work from the terminal:

```sh
dply errors dismiss <id>           # clear one · --all clears every open error
dply errors retry <id>             # re-run the operation that failed
dply errors fix <id>               # apply the catalogued remediation (--action <key>)
```

Leave the id off and you pick from a list. On a TTY, plain `dply errors` goes
one better: pick an error from the list it just printed and it offers exactly
the actions that error supports — detail, retry (only when the category is
retryable), the known fix (only when there is one), open in the dashboard,
dismiss. `--no-prompt` / `$DPLY_NO_PROMPT` turns that off.

`dismiss` needs **`sites.write`**; `retry` and `fix` run work on the box, so
they need **`commands.run`**.

`dply errors` **exits 1 when any error is open** and 0 when the site is clean, so
it works as a post-deploy gate:

```sh
dply deploy --wait && dply errors
```

### Run a command on a server

Requires the **`commands.run`** scope (included in admin CLI presets; refresh with `dply auth refresh`):

```sh
dply server run --server <id> php artisan migrate --force
dply server run --server <id> --command "df -h"
```

### Firewall (UFW)

Read rules with **`network.read`**; apply with **`network.write`** (org admin CLI preset):

```sh
dply server firewall show --server <id>
dply server firewall apply-bundled laravel_web --server <id>
dply server firewall apply --server <id> --ack-ssh-lockout
```

## Commands

See **Profile → CLI** in the web app. Run `dply help`, `dply ls site`, or `dply site help`.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Success |
| 1 | API or runtime error · `dply errors` found open errors |
| 2 | Bad arguments / not logged in |
