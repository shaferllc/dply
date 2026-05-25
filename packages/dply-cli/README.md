# `dply` — command-line interface for the dply Edge platform

Zero-dependency Node CLI for the public Edge REST API. Lets you script
everything the dashboard does: deploy, roll back, promote previews,
manage custom domains, list per-deploy aliases, purge cache, pull usage
stats.

## Install

```sh
npm install -g @dply/cli
```

Requires Node 18+. The CLI uses the built-in `fetch` — no native
modules, no transitive deps.

## First-time setup

```sh
dply login                                     # opens browser, approve in dply
dply sites                                     # confirm the token sees your sites
cd path/to/your-edge-repo
dply link <site-id>                             # writes .dply/site.json
```

`dply login` uses the **OAuth device flow** — the same pattern as
GitHub CLI, Vercel CLI, and Stripe CLI:

1. The CLI prints a short code (e.g. `WXYZ-ABCD`) and a verification URL.
2. Your default browser opens to that URL on `https://dply.dev` (or
   your self-hosted instance). Confirm the code matches what's in your
   terminal, pick the organization, click **Approve**.
3. The CLI polls the API, picks up the freshly-minted token, and
   writes it to `~/.dply/config.json` (mode 0600).

Flags:

- `--base-url https://your-dply.example` — point at a self-hosted instance.
- `--no-open` — don't try to open a browser; just print the URL.
- `--token <plaintext>` — **headless / CI fallback**: skip the browser
  approval flow and save a token you generated manually in
  Settings → API tokens. Useful for CI scripts where there's no
  browser. Token must have `edge.read` + `edge.deploy` + `edge.write`
  for full CLI functionality.

After `dply link`, every command in that repo (or any child directory)
defaults to that site. Override with `--site <id>` or `DPLY_EDGE_SITE`.

## Commands

### Top-level

| Command | Purpose |
| --- | --- |
| `dply login [--base-url …] [--no-open]` | Browser-approval (device-flow) login |
| `dply login --token … [--base-url …]` | Headless / CI fallback: save + verify a pre-generated token |
| `dply logout` | Forget the saved token |
| `dply whoami` | Show the active token + linked repo |
| `dply link [site-id]` | Link cwd to an Edge site (no arg: lists sites) |
| `dply sites` | List Edge sites visible to your token |

### Edge

| Command | Purpose |
| --- | --- |
| `dply edge deploy [--commit X] [--branch Y] [--prod]` | Queue a deploy (`--prod` prints the production URL) |
| `dply edge lint [--path dply.yaml]` | Validate repo config (same rules as deploy) |
| `dply edge open [--dashboard]` | Open the live site or dashboard in your browser |
| `dply edge deployments [--limit N]` | List recent deployments |
| `dply edge rollback <deployment-id>` | Re-point production at a prior deployment |
| `dply edge promote <preview-site-id>` | Copy a preview into prod and flip the host map |
| `dply edge previews list` | List active preview sites |
| `dply edge previews create --commit X [--branch Y]` | Create an ad-hoc preview from a commit |
| `dply edge previews rm <preview-id>` | Tear down a preview |
| `dply edge domains list` | List custom domains + verification state |
| `dply edge domains add <hostname>` | Attach a custom hostname (returns CNAME target) |
| `dply edge domains verify <hostname>` | Re-check DNS for an attached hostname |
| `dply edge domains rm <hostname>` | Detach a custom hostname |
| `dply edge aliases` | List per-deploy stable URLs (commit + deploy-id aliases) |
| `dply edge purge --tag <tag>` | Purge edge cache entries by `Cache-Tag` |
| `dply edge usage [--days N]` | Show traffic / billing usage (default 30 days) |
| `dply edge logs --tail [--interval ms] [--window s] [--once]` | Poll request logs (live tail via dashboard Echo in Wave B) |

## Config files

| Path | Purpose |
| --- | --- |
| `~/.dply/config.json` | Global token + default base URL (0600 mode) |
| `.dply/site.json` | Per-repo site link — checked in or `.gitignore`d, your call |

Commands resolve the active site in this order: `--site` flag,
`DPLY_EDGE_SITE` env var, nearest `.dply/site.json` walking up from cwd.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Success |
| 1 | API or runtime error |
| 2 | Bad arguments / not logged in / no site context |

Useful for scripting:

```sh
if ! dply edge deploy --commit "$GIT_SHA"; then
  echo "deploy failed — check dply dashboard"
  exit 1
fi
```
