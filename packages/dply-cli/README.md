# `dply` — command-line interface for dply

Zero-dependency Node CLI for the dply REST API. Script Edge deploys,
BYO server operations, and more from your terminal.

## Install

```sh
npm install -g @dply/cli
```

Requires Node 18+. Uses built-in `fetch` — no native modules.

## Sign in (seamless device flow)

```sh
dply login --base-url https://your-dply.example
```

1. The CLI prints a short code and opens your browser to the dply instance.
2. Sign in if needed, confirm the code, pick your organization and scopes, click **Approve**.
3. The terminal polls automatically and saves the token to `~/.dply/config.json` (mode 0600).

Flags:

| Flag | Purpose |
| --- | --- |
| `--base-url URL` | Self-hosted instance (defaults to env `DPLY_API_BASE_URL` or the public cloud) |
| `--no-open` | Print the URL only — don't launch a browser |
| `--token PLAINTEXT` | CI / headless: skip the browser and save a token from Settings → API keys |

Revoke CLI sessions anytime from **Profile → CLI** in the web app.

## Verify

```sh
dply whoami
dply server list
dply sites          # Edge sites, when your token includes edge scopes
```

## Commands

### Top-level

| Command | Purpose |
| --- | --- |
| `dply login` | Browser device-flow login |
| `dply logout` | Remove saved credentials |
| `dply whoami` | Show active base URL + linked Edge repo |
| `dply link [site-id]` | Link cwd to an Edge site |
| `dply sites` | List Edge sites |
| `dply server …` | BYO server commands (see below) |
| `dply edge …` | Edge platform commands (see below) |

### Server (BYO)

| Command | Purpose |
| --- | --- |
| `dply server list` | List servers in your organization |
| `dply server system-users list --server ID` | List Linux accounts (dply snapshot) |
| `dply server system-users sync --server ID` | SSH-sync `/etc/passwd` into dply |
| `dply server system-users add USER --server ID` | Queue user creation (`--sudo`, `--shell`, `--no-web-group`) |
| `dply server system-users update USER --server ID` | Queue shell / sudo / web-group changes |
| `dply server system-users remove USER --server ID` | Queue user removal |

Pass `--server` with a server ULID or a unique server name. Mutations queue over SSH — same as the server workspace UI.

### Edge

| Command | Purpose |
| --- | --- |
| `dply edge deploy [--commit X] [--branch Y] [--prod]` | Queue a deploy |
| `dply edge deployments [--limit N]` | List recent deployments |
| `dply edge lint [--path dply.yaml]` | Validate repo config |
| `dply edge open [--dashboard]` | Open live URL or workspace |
| `dply edge rollback <deployment-id>` | Roll production back |
| `dply edge promote <preview-site-id>` | Promote preview to production |
| `dply edge previews list \| create \| rm` | Preview management |
| `dply edge domains list \| add \| verify \| rm` | Custom domains |
| `dply edge aliases` | Per-deploy stable URLs |
| `dply edge purge --tag X` | Purge cache by tag |
| `dply edge usage [--days N]` | Traffic / billing usage |
| `dply edge logs --tail [--interval ms] [--window s] [--once]` | Request logs |
| `dply edge env list \| set \| rm \| push \| pull` | Environment variables |

## Config files

| Path | Purpose |
| --- | --- |
| `~/.dply/config.json` | Global token + base URL |
| `.dply/site.json` | Per-repo Edge site link |

## Scopes

Device login offers scopes based on your org role (Edge, servers, sites, system users, …). Manage active CLI tokens under **Profile → CLI**.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Success |
| 1 | API or runtime error |
| 2 | Bad arguments / not logged in |
