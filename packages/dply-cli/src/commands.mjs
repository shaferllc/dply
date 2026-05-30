import { ApiClient } from './api.mjs';
import { execFile } from 'node:child_process';
import { access, readFile } from 'node:fs/promises';
import { promisify } from 'node:util';
import {
  resolveLoginBaseUrl,
  defaultBaseUrl,
  deleteGlobalConfig,
  readGlobalConfig,
  readSiteLink,
  resolveContext,
  writeGlobalConfig,
  writeSiteLink,
} from './config.mjs';
import { c, info, ok, printJson, printKeyValues, printTable, warn } from './print.mjs';

const execFileAsync = promisify(execFile);
const CONFIG_CANDIDATES = ['dply.yaml', 'dply.yml', 'dply.json'];

/**
 * Saves a token + base URL to ~/.dply/config.json.
 *
 * Two flows:
 *
 * 1. Device flow (default — no flags). Calls POST /auth/device/start,
 *    prints the short user_code, opens the verification URL in the
 *    user's default browser, and polls /auth/device/poll until the
 *    user approves on the web. Matches the GitHub CLI / Vercel CLI /
 *    Stripe CLI UX.
 *
 * 2. Token paste (--token <plaintext>). Still supported for CI and
 *    headless setups where opening a browser isn't possible.
 *
 * Flags:
 *   --token    API token plaintext (skip the browser-approval flow)
 *   --base-url Instance URL (defaults to env DPLY_API_BASE_URL or the public cloud)
 *   --no-open  Don't try to open the browser, just print the URL
 */
export async function login(args, flags) {
  const baseUrl = await resolveLoginBaseUrl(flags);
  const explicitToken = flags.token || flags.t;
  const enterShell = flags['no-shell'] !== true;

  if (explicitToken) {
    await loginWithToken({ baseUrl, token: explicitToken, enterShell });

    return;
  }

  await loginWithDeviceFlow({ baseUrl, openBrowser: flags['no-open'] !== true, enterShell });
}

async function loginWithToken({ baseUrl, token, enterShell = true }) {
  const probe = new ApiClient({ baseUrl, token });

  try {
    await probe.get('/servers');
  } catch {
    try {
      await probe.get('/edge/sites');
    } catch (err) {
      throw fail(`Token verification failed: ${err.message}`, err.status ?? 1);
    }
  }

  await writeGlobalConfig({ token, baseUrl });
  await finalizeLogin({ baseUrl, enterShell });
}

async function loginWithDeviceFlow({ baseUrl, openBrowser, enterShell = true }) {
  // /auth/device/start is unauthenticated — pass an empty bearer.
  const anonymous = new ApiClient({ baseUrl, token: '' });
  let started;
  try {
    started = await anonymous.post('/auth/device/start', {});
  } catch (err) {
    const hint =
      baseUrl === defaultBaseUrl()
        ? ' Check APP_URL / reinstall the CLI from your instance if this host is wrong.'
        : ' Check the URL is reachable and APP_URL matches your browser host.';
    throw fail(`Could not start device login at ${baseUrl}: ${err.message}.${hint}`, err.status ?? 1);
  }

  const {
    device_code,
    user_code,
    verification_uri,
    verification_uri_complete,
    expires_in,
    interval,
  } = started ?? {};

  if (!device_code || !user_code) {
    throw fail('Device-login response was missing device_code / user_code.', 1);
  }

  const pollIntervalMs = Math.max(1000, Number.parseInt(interval ?? '2', 10) * 1000);
  const expiresAt = Date.now() + Math.max(60, Number.parseInt(expires_in ?? '900', 10)) * 1000;

  info('');
  info(`${c.bold('Open this URL to approve the CLI:')}`);
  info(`  ${c.cyan(verification_uri_complete || verification_uri)}`);
  info('');
  info(`${c.bold('Confirm the code shown matches:')}`);
  info(`  ${c.bold(c.cyan(user_code))}`);
  info('');
  info(c.dim(`Waiting for approval… (expires in ${Math.round((expiresAt - Date.now()) / 1000)}s)`));

  if (openBrowser) {
    try {
      await openInBrowser(verification_uri_complete || verification_uri);
    } catch {
      // If the browser fails to open we still have the printed URL —
      // don't fail the login flow over a display-only convenience.
    }
  }

  // Best-effort cleanup: if the user hits ^C, ask the server to drop
  // the row instead of waiting for the 15-minute TTL.
  const onSigint = () => {
    info('');
    warn('Login cancelled. Re-run `dply login` to start over.');
    process.exit(2);
  };
  process.on('SIGINT', onSigint);

  try {
    while (Date.now() < expiresAt) {
      let response;
      try {
        response = await anonymous.post('/auth/device/poll', { device_code });
      } catch (err) {
        // 429 / transient network — back off one interval and retry.
        warn(`Poll error: ${err.message} — retrying`);
        await sleep(pollIntervalMs);
        continue;
      }

      const status = response?.status ?? 'pending';
      if (status === 'authorized') {
        const token = response.token;
        if (!token) {
          throw fail('Server marked the code authorized but returned no token.', 1);
        }
        await writeGlobalConfig({ token, baseUrl });
        await finalizeLogin({ baseUrl, enterShell });

        return;
      }
      if (status === 'denied') {
        throw fail('Login denied in the browser. Re-run `dply login` to try again.', 2);
      }
      if (status === 'expired') {
        throw fail('Login code expired. Re-run `dply login` to start over.', 2);
      }

      await sleep(pollIntervalMs);
    }

    throw fail('Login timed out before approval. Re-run `dply login` to start over.', 2);
  } finally {
    process.off('SIGINT', onSigint);
  }
}

/**
 * @param {{ baseUrl: string, enterShell?: boolean }} opts
 */
async function finalizeLogin({ baseUrl, enterShell = true }) {
  const cfg = await readGlobalConfig();
  ok(`Logged in to ${c.cyan(baseUrl)}.`);

  if (cfg?.token) {
    await printLoginSummary(baseUrl, cfg.token);
  }

  if (enterShell) {
    const { enterInteractiveShell } = await import('./shell.mjs');
    await enterInteractiveShell();
  }
}

/**
 * @param {string} baseUrl
 * @param {string} token
 */
async function printLoginSummary(baseUrl, token) {
  const client = new ApiClient({ baseUrl, token });
  const hints = [];

  info('');

  try {
    const servers = (await client.get('/servers'))?.data ?? [];
    info(`${c.bold(String(servers.length))} server(s) visible`);
    if (servers.length > 0) {
      hints.push('server list');
      hints.push('server system-users list --server <id>');
    }
  } catch {
    // token may not include servers.read
  }

  try {
    const sites = (await client.get('/edge/sites'))?.data ?? [];
    if (sites.length > 0) {
      info(`${c.bold(String(sites.length))} edge site(s) visible`);
      hints.push('sites', 'edge deploy');
    }
  } catch {
    // token may not include edge.read
  }

  if (hints.length > 0) {
    info('');
    info(c.dim(`Try: ${hints.slice(0, 3).join(' · ')} · account show`));
  } else {
    info('');
    info(c.dim('Try: account show · account sessions · server list'));
  }
}

export async function shell() {
  const { enterInteractiveShell } = await import('./shell.mjs');
  await enterInteractiveShell();
}

export async function menu() {
  const { enterInteractiveMenu } = await import('./menus.mjs');
  await enterInteractiveMenu();
}

export async function whoami() {
  const cfg = await readGlobalConfig();
  if (!cfg?.token) {
    info(c.dim('Not logged in. Run `dply login`.'));

    return 1;
  }

  try {
    const { accountShow } = await import('./account-commands.mjs');

    return accountShow({});
  } catch (err) {
    if (err?.status !== 403 && err?.status !== 401) {
      throw err;
    }

    warn('Could not load account from API — showing local config only.');
    printKeyValues([
      ['Base URL', cfg.baseUrl],
      ['Token', `${cfg.token.slice(0, 6)}…${cfg.token.slice(-4)}`],
      ['Saved at', cfg.savedAt ?? '—'],
    ]);

    return 0;
  }
}

/** @deprecated Use accountLogout via `dply logout` / `dply account logout`. */
export async function logout() {
  const { accountLogout } = await import('./account-commands.mjs');

  return accountLogout();
}

/**
 * `dply link <site-id>` — write .dply/site.json so future commands
 * default to that site. Without an explicit id, prints the list of
 * available sites so the user can pick one.
 */
export async function link(args) {
  const ctx = await resolveContext();
  const api = new ApiClient(ctx);

  if (args.length === 0) {
    const sites = (await api.get('/edge/sites'))?.data ?? [];
    info(c.dim('Pass one of these site IDs to `dply link`:'));
    printTable(['id', 'name', 'hostname', 'status'], sites.map((s) => ({
      id: s.id,
      name: s.name,
      hostname: s.hostname,
      status: s.status,
    })));

    return 0;
  }

  const siteId = args[0];
  const response = await api.get(`/edge/sites/${encodeURIComponent(siteId)}`);
  const site = response.data;
  const path = await writeSiteLink({
    siteId: site.id,
    siteName: site.name,
    baseUrl: ctx.baseUrl,
    organizationId: site.organization_id,
  });
  ok(`Linked ${c.cyan(site.name)} (${site.id}) → ${c.dim(path)}`);
}

export async function sites() {
  const ctx = await resolveContext();
  const api = new ApiClient(ctx);
  const response = await api.get('/edge/sites');
  printTable(
    ['id', 'name', 'hostname', 'status', 'runtime_mode', 'is_preview'],
    (response.data ?? []).map((s) => ({
      id: s.id,
      name: s.name,
      hostname: s.hostname,
      status: s.status,
      runtime_mode: s.runtime_mode,
      is_preview: s.is_preview ? 'yes' : 'no',
    })),
  );
}

export async function deploy(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const body = {};
  if (flags.commit) body.commit = String(flags.commit);
  if (flags.branch) body.branch = String(flags.branch);

  const response = await api.post(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/deployments`,
    body,
  );
  const d = response.data;
  ok(`Deployment queued: ${c.cyan(d.id)} (status ${d.status})`);
  printKeyValues([
    ['Commit', d.git_commit ?? '—'],
    ['Branch', d.git_branch ?? '—'],
    ['Storage prefix', d.storage_prefix ?? '—'],
  ]);

  if (flags.prod) {
    const site = (await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}`))?.data;
    const liveUrl = site?.live_url;
    if (liveUrl) {
      ok(`Production URL: ${c.cyan(liveUrl)}`);
    } else {
      warn('Deploy queued, but no production URL is published yet.');
    }
  }
}

export async function deployments(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const limit = flags.limit ?? 20;
  const response = await api.get(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/deployments?limit=${encodeURIComponent(limit)}`,
  );
  printTable(
    ['id', 'status', 'git_commit', 'git_branch', 'published_at', 'aliases'],
    (response.data ?? []).map((d) => ({
      id: d.id,
      status: d.status,
      git_commit: d.git_commit ? d.git_commit.slice(0, 7) : '—',
      git_branch: d.git_branch ?? '—',
      published_at: d.published_at ?? '—',
      aliases: (d.aliases ?? []).length,
    })),
  );
}

export async function rollback(args, flags) {
  const ctx = await requireSiteContext(flags);
  const deploymentId = args[0];
  if (!deploymentId) throw usageError('edge rollback <deployment-id>', 'Pass the deployment id to roll back to.');

  const api = new ApiClient(ctx);
  const response = await api.post(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/deployments/${encodeURIComponent(deploymentId)}/rollback`,
    {},
  );
  ok(`Rolled back. Deployment ${c.cyan(response.data.id)} is now live.`);
}

export async function promote(args, flags) {
  const ctx = await requireSiteContext(flags);
  const previewId = args[0];
  if (!previewId) throw usageError('edge promote <preview-site-id>', 'Pass the preview site id to promote.');

  const api = new ApiClient(ctx);
  const response = await api.post(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/previews/${encodeURIComponent(previewId)}/promote`,
    {},
  );
  ok(`Preview promoted to production. New deployment: ${c.cyan(response.data.id)}`);
}

export async function previews(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);

  const sub = args[0] ?? 'list';
  if (sub === 'list') {
    const response = await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}/previews`);
    printTable(
      ['id', 'name', 'hostname', 'status'],
      (response.data ?? []).map((p) => ({
        id: p.id,
        name: p.name,
        hostname: p.hostname,
        status: p.status,
      })),
    );

    return;
  }

  if (sub === 'create') {
    const commit = flags.commit;
    if (!commit) throw usageError('edge previews create --commit <sha>', '--commit is required.');
    const response = await api.post(
      `/edge/sites/${encodeURIComponent(ctx.siteId)}/previews`,
      {
        commit,
        branch: flags.branch,
      },
    );
    ok(`Preview created: ${c.cyan(response.data.hostname)} (${response.data.id})`);

    return;
  }

  if (sub === 'rm' || sub === 'destroy') {
    const id = args[1];
    if (!id) throw usageError('edge previews rm <preview-id>', 'Pass the preview site id to tear down.');
    await api.delete(`/edge/sites/${encodeURIComponent(ctx.siteId)}/previews/${encodeURIComponent(id)}`);
    ok('Teardown queued.');

    return;
  }

  throw usageError('edge previews', `Unknown subcommand "${sub}". Use list, create, or rm.`);
}

export async function domains(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const sub = args[0] ?? 'list';

  if (sub === 'list') {
    const response = await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}/domains`);
    printTable(
      ['hostname', 'mode', 'dns_status', 'cname_target', 'verified_at'],
      response.data ?? [],
    );

    return;
  }

  if (sub === 'add') {
    const hostname = args[1];
    if (!hostname) throw usageError('edge domains add <hostname>', 'Pass the hostname to attach.');
    const response = await api.post(
      `/edge/sites/${encodeURIComponent(ctx.siteId)}/domains`,
      { hostname },
    );
    ok(`Domain attached. Add the CNAME below and run \`dply edge domains verify ${hostname}\`.`);
    printTable(['name', 'type', 'value', 'status'], response.data ?? []);

    return;
  }

  if (sub === 'verify') {
    const hostname = args[1];
    if (!hostname) throw usageError('edge domains verify <hostname>', 'Pass the hostname.');
    const response = await api.post(
      `/edge/sites/${encodeURIComponent(ctx.siteId)}/domains/${encodeURIComponent(hostname)}/verify`,
      {},
    );
    const status = response?.data?.dns_status ?? 'unknown';
    if (status === 'ready') ok(`${hostname} → ${c.green('ready')}`);
    else warn(`${hostname} → ${status} (${response?.data?.error ?? 'still propagating'})`);

    return;
  }

  if (sub === 'rm') {
    const hostname = args[1];
    if (!hostname) throw usageError('edge domains rm <hostname>', 'Pass the hostname to detach.');
    await api.delete(`/edge/sites/${encodeURIComponent(ctx.siteId)}/domains/${encodeURIComponent(hostname)}`);
    ok(`${hostname} detached.`);

    return;
  }

  throw usageError('edge domains', `Unknown subcommand "${sub}". Use list, add, verify, or rm.`);
}

export async function aliases(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const response = await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}/aliases`);
  printTable(
    ['hostname', 'deployment_id', 'git_commit', 'git_branch', 'published_at'],
    (response.data ?? []).map((a) => ({
      ...a,
      git_commit: a.git_commit ? a.git_commit.slice(0, 7) : '—',
    })),
  );
}

export async function purge(args, flags) {
  const ctx = await requireSiteContext(flags);
  const tag = flags.tag;
  if (!tag) throw usageError('edge purge --tag <tag>', '--tag is required.');
  const api = new ApiClient(ctx);
  const response = await api.post(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/cache/purge`,
    { tag },
  );
  if (response.data?.ok) ok(`Purged ${response.data.purged_keys?.length ?? 0} cache entr(ies) for tag ${c.cyan(tag)}.`);
  else warn(response.data?.message ?? 'Purge returned no entries.');
}

export async function usage(args, flags) {
  const ctx = await requireSiteContext(flags);
  const days = flags.days ?? 30;
  const api = new ApiClient(ctx);
  const response = await api.get(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/usage?days=${encodeURIComponent(days)}`,
  );
  printJson(response.data);
}

export async function logs(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const intervalMs = Math.max(500, Math.min(60000, Number.parseInt(flags.interval ?? '1000', 10) || 1000));
  const sinceWindow = Math.max(1, Math.min(3600, Number.parseInt(flags.window ?? '60', 10) || 60));
  const oneShot = Boolean(flags.once);

  let cursor = new Date(Date.now() - sinceWindow * 1000).toISOString();
  let printedHeader = false;
  let aborted = false;

  const onExit = () => {
    if (aborted) return;
    aborted = true;
    info(c.dim('\n— tail stopped —'));
  };
  process.on('SIGINT', () => {
    onExit();
    process.exit(0);
  });

  while (! aborted) {
    let response;
    try {
      response = await api.get(
        `/edge/sites/${encodeURIComponent(ctx.siteId)}/logs?since=${encodeURIComponent(cursor)}&limit=200`,
      );
    } catch (err) {
      warn(`tail: ${err.message} — retrying in ${intervalMs}ms`);
      if (oneShot) return 1;
      await sleep(intervalMs);
      continue;
    }

    if (! printedHeader) {
      info(c.dim('time              method status   ms  cache         path'));
      printedHeader = true;
    }

    const rows = response.data ?? [];
    for (const row of rows) {
      const time = (row.occurred_at ?? '').slice(11, 19) || '--:--:--';
      const status = String(row.status ?? '—').padEnd(3);
      const statusColored = (row.status ?? 0) >= 500
        ? c.red(status)
        : (row.status ?? 0) >= 400 ? c.yellow(status) : c.green(status);
      const method = (row.method ?? 'GET').padEnd(6);
      const ms = String(row.duration_ms ?? 0).padStart(4);
      const cache = (row.cache_status ?? '—').padEnd(12);
      const path = row.path ?? '/';
      process.stdout.write(`${c.dim(time)}  ${method} ${statusColored}  ${c.dim(ms)}  ${c.dim(cache)}  ${path}\n`);
    }

    const meta = response.meta ?? {};
    if (meta.tail_cursor) cursor = meta.tail_cursor;

    if (oneShot) return 0;
    await sleep(intervalMs);
  }

  return 0;
}

/**
 * Validate dply.yaml / dply.json in cwd (or --path) against the same
 * rules the build runner uses on deploy.
 */
export async function lint(args, flags) {
  const ctx = await resolveContext();
  if (!ctx.token) {
    throw fail('Not logged in. Run `dply login --token …` first.', 2);
  }

  const configPath = flags.path ? String(flags.path) : await findRepoConfigFile();
  if (!configPath) {
    throw usageError(
      'edge lint',
      'No dply.yaml, dply.yml, or dply.json found in this directory. Pass --path to lint a specific file.',
    );
  }

  const content = await readFile(configPath, 'utf8');
  const api = new ApiClient(ctx);
  let result;
  try {
    const response = await api.post('/edge/lint', {
      path: configPath.split('/').pop(),
      content,
    });
    result = response.data;
  } catch (err) {
    if (err.status === 422 && err.body?.data) {
      result = err.body.data;
    } else {
      throw err;
    }
  }

  if (result.source_path) {
    info(`Linted ${c.cyan(result.source_path)}`);
  } else {
    info(c.dim('No config file (lint ok).'));
  }

  for (const warning of result.warnings ?? []) {
    warn(warning);
  }
  for (const error of result.errors ?? []) {
    warn(`${c.red('error')}: ${error}`);
  }

  if (result.summary) {
    printKeyValues([
      ['Redirects', String(result.summary.redirects ?? 0)],
      ['Rewrites', String(result.summary.rewrites ?? 0)],
      ['Header rules', String(result.summary.headers ?? 0)],
      ['Build keys', (result.summary.build_keys ?? []).join(', ') || '—'],
    ]);
  }

  if (!result.ok) {
    throw fail('Config lint failed.', 1);
  }

  ok('Config lint passed.');
}

/**
 * Open the linked site's live URL (or dashboard with --dashboard).
 */
export async function open(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const site = (await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}`))?.data;
  const url = flags.dashboard ? site?.dashboard_url : site?.live_url;

  if (!url) {
    throw fail(flags.dashboard
      ? 'Could not resolve a dashboard URL for this site.'
      : 'No live URL published yet — deploy first with `dply edge deploy --prod`.', 1);
  }

  await openInBrowser(url);
  ok(`Opened ${c.cyan(url)}`);
}

/**
 * `dply edge env <subcommand>` — manage encrypted env vars on an Edge
 * site. Values are write-only; GET returns keys + updated_at only.
 *
 * Subcommands:
 *   list                     print all keys + updated_at
 *   set KEY=val [KEY=val…]   upsert one or more keys
 *   rm KEY [KEY…]            remove keys
 *   push --file PATH         bulk replace from dotenv-format file
 *   pull                     print all keys as dotenv comments (no values — GET is keys-only)
 */
export async function env(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const sub = args[0] ?? 'list';

  if (sub === 'list' || sub === 'pull') {
    const response = await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}/env`);
    const rows = response.data ?? [];
    if (sub === 'pull') {
      info(c.dim(`# dply env vars for site ${ctx.siteId}`));
      info(c.dim('# Values are write-only via the API — set them with `dply edge env set KEY=value`.'));
      for (const row of rows) {
        info(`${row.key}=`);
      }

      return;
    }
    printTable(['key', 'updated_at'], rows.map((r) => ({
      key: r.key,
      updated_at: r.updated_at ?? '—',
    })));

    return;
  }

  if (sub === 'set') {
    const pairs = args.slice(1);
    if (pairs.length === 0) throw usageError('edge env set KEY=value [KEY=value …]', 'At least one KEY=value pair is required.');
    for (const pair of pairs) {
      const eq = pair.indexOf('=');
      if (eq <= 0) throw fail(`Invalid pair "${pair}" — expected KEY=value.`, 2);
      const key = pair.slice(0, eq);
      const value = pair.slice(eq + 1);
      await api.request(
        `/edge/sites/${encodeURIComponent(ctx.siteId)}/env/${encodeURIComponent(key)}`,
        { method: 'PATCH', body: { value } },
      );
      ok(`Set ${c.cyan(key)}`);
    }

    return;
  }

  if (sub === 'rm' || sub === 'remove' || sub === 'unset') {
    const keys = args.slice(1);
    if (keys.length === 0) throw usageError('edge env rm KEY [KEY …]', 'Pass at least one key.');
    for (const key of keys) {
      await api.delete(`/edge/sites/${encodeURIComponent(ctx.siteId)}/env/${encodeURIComponent(key)}`);
      ok(`Removed ${c.cyan(key)}`);
    }

    return;
  }

  if (sub === 'push') {
    const file = flags.file || flags.f;
    if (!file) throw usageError('edge env push --file PATH', 'Pass --file pointing at a dotenv-format file.');
    const raw = await readFile(file, 'utf8');
    const parsed = parseDotenv(raw);
    if (Object.keys(parsed).length === 0) {
      warn(`${file} produced no KEY=value pairs — nothing pushed.`);

      return;
    }
    await api.request(`/edge/sites/${encodeURIComponent(ctx.siteId)}/env`, {
      method: 'PUT',
      body: parsed,
    });
    ok(`Pushed ${Object.keys(parsed).length} key(s) from ${c.dim(file)}.`);

    return;
  }

  throw usageError('edge env', `Unknown subcommand "${sub}". Use list, set, rm, push, pull.`);
}

/**
 * Minimal dotenv parser. Honors `KEY=value`, double-quoted values
 * (with \\n / \\t escapes), single-quoted values (literal), and
 * #-prefixed comments. No variable expansion. Sufficient for env
 * files produced by `dply edge env pull` + standard `.env` workflows.
 */
function parseDotenv(raw) {
  const out = {};
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (trimmed === '' || trimmed.startsWith('#')) continue;
    const eq = trimmed.indexOf('=');
    if (eq <= 0) continue;
    const key = trimmed.slice(0, eq).trim().replace(/^export\s+/, '');
    if (!/^[A-Z_][A-Z0-9_]*$/.test(key)) continue;
    let value = trimmed.slice(eq + 1).trim();
    if (value.startsWith('"') && value.endsWith('"') && value.length >= 2) {
      value = value.slice(1, -1).replace(/\\n/g, '\n').replace(/\\t/g, '\t').replace(/\\"/g, '"');
    } else if (value.startsWith("'") && value.endsWith("'") && value.length >= 2) {
      value = value.slice(1, -1);
    } else {
      // Strip inline #-comments only when preceded by whitespace.
      const hashIdx = value.indexOf(' #');
      if (hashIdx > -1) value = value.slice(0, hashIdx).trim();
    }
    out[key] = value;
  }

  return out;
}

async function findRepoConfigFile() {
  for (const candidate of CONFIG_CANDIDATES) {
    try {
      await access(candidate);
      return candidate;
    } catch {
      // try next candidate
    }
  }

  return null;
}

async function openInBrowser(url) {
  const platform = process.platform;
  if (platform === 'darwin') {
    await execFileAsync('open', [url]);

    return;
  }
  if (platform === 'win32') {
    await execFileAsync('cmd', ['/c', 'start', '', url]);

    return;
  }
  await execFileAsync('xdg-open', [url]);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function requireSiteContext(flags) {
  const ctx = await resolveContext({ siteFlag: flags.site });
  if (!ctx.siteId) {
    throw fail(
      'No site specified. Pass --site <id>, set DPLY_EDGE_SITE, or run `dply link <id>` first.',
      2,
    );
  }

  return ctx;
}

function usageError(command, message) {
  return fail(`${message}\nusage: dply ${command}`, 2);
}

function fail(message, exitCode = 1) {
  const err = new Error(message);
  err.exitCode = exitCode;

  return err;
}
