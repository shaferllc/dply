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
dply serverless errors --site checkout        # failed invocations
dply serverless errors --site checkout --watch
dply serverless invocations --site checkout --source web --limit 20
dply serverless invocation <id> --site checkout   # + captured log lines
dply serverless logs --site checkout --level error --follow
```

`serverless errors` reads **failed invocations**, which is where a function's
failures actually live — the platform's activations API returns nothing, so
dply's own invocation table is the only record. It exits 1 when anything failed.

Two log surfaces, deliberately separate:

| Command | Shows |
| --- | --- |
| `serverless logs` | the site's application log drain (searchable, level-filtered, 30-day) |
| `serverless invocation <id>` | stdout/stderr captured from that one activation |

### Errors

Open error events for a site (any kind — BYO, Edge, Serverless), newest first.
Requires the **`sites.read`** scope.

```sh
dply errors                        # linked site, or --site <id> / $DPLY_SITE
dply errors --full                 # detail, remediation code, deep link
dply errors --category ssl,deploy  # filter by category (comma-separated)
dply errors --watch                # poll for new events (--interval ms)
dply errors --json                 # raw payload
```

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
