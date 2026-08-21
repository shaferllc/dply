/**
 * `dply serverless` — managed functions (DO Functions / AWS Lambda web actions).
 *
 * Against /api/v1/serverless/*. Two things worth knowing about the shape here:
 *
 *  - `serverless errors` reads *failed invocations*, not ErrorEvent rows. The
 *    platform's activations API returns nothing, so a failed invocation is only
 *    readable from dply's own invocation table. `dply errors` (site-level, all
 *    kinds) is the ErrorEvent view and stays separate.
 *  - `serverless logs` reads the site's *application* log drain. Per-invocation
 *    stdout/stderr lives on the invocation — `serverless invocation <id>`.
 */
import { requireClient } from './server-context.mjs';
import { readSiteLink } from './config.mjs';
import { c, info, printJson, printKeyValues, printTable, warn } from './print.mjs';

const SUBCOMMANDS = {
  list: 'List functions in your organization.',
  status: '[site] — function detail + 24h health.',
  invocations: '[site] — recent invocations (--failed, --source, --limit).',
  errors: '[site] — failed invocations (--watch to poll).',
  logs: '[site] — application logs (--follow, --level error).',
  invocation: '<id> [--site …] — one invocation with its log lines.',
};

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverlessCommand(args, flags) {
  const sub = args[0];

  if (! sub || sub === 'help' || flags.help || flags.h) {
    return printServerlessHelp();
  }

  const rest = args.slice(1);

  switch (sub) {
    case 'list':
    case 'ls':
      return serverlessList(flags);
    case 'status':
    case 'show':
      return serverlessStatus(rest, flags);
    case 'invocations':
      return serverlessInvocations(rest, flags);
    case 'errors':
      return serverlessErrors(rest, flags);
    case 'logs':
      return serverlessLogs(rest, flags);
    case 'invocation':
      return serverlessInvocation(rest, flags);
    default:
      throw cliError(`Unknown serverless command: ${sub}. Run \`dply serverless help\`.`, 2);
  }
}

/**
 * @param {Record<string, unknown>} flags
 */
export async function serverlessList(flags) {
  const client = await requireClient(flags);
  const rows = (await client.get('/serverless/sites'))?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return 0;
  }

  if (rows.length === 0) {
    info(c.dim('No serverless functions in this organization.'));

    return 0;
  }

  printTable(
    ['name', 'status', 'runtime', 'url'],
    rows.map((row) => ({
      name: row.name,
      status: row.is_live ? c.green('live') : c.yellow(String(row.status ?? '—')),
      runtime: row.runtime ?? '—',
      url: row.url ?? '—',
    })),
  );

  return 0;
}

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverlessStatus(args, flags) {
  const { client, siteId } = await context(args[0], flags);
  const data = (await client.get(`/serverless/sites/${encodeURIComponent(siteId)}`))?.data ?? {};

  if (flags.json) {
    printJson(data);

    return 0;
  }

  const limits = data.limits ?? {};
  const health = data.health ?? {};

  info(c.bold(String(data.name ?? siteId)));
  printKeyValues([
    ['status', data.is_live ? c.green('live') : String(data.status ?? '—')],
    ['url', data.url ?? '—'],
    ['runtime', data.runtime ?? '—'],
    ['action', data.action_name ?? '—'],
    ['namespace', data.namespace ?? '—'],
    ['memory', limits.memory != null ? `${limits.memory} MB` : '—'],
    ['timeout', limits.timeout != null ? `${limits.timeout} ms` : '—'],
    ['concurrency', limits.concurrency != null ? String(limits.concurrency) : '—'],
    ['keep warm', data.keep_warm ? 'on' : 'off'],
    ['background', data.background_processing ? 'on' : 'off'],
    ['repository', data.git_repository_url ?? '—'],
    ['last deploy', data.last_deploy_at ?? data.last_deployed_at ?? '—'],
  ]);

  info('');
  info(c.bold(`Last ${health.window_hours ?? 24}h`));
  printKeyValues([
    ['invocations', String(health.invocations ?? 0)],
    ['failed', health.failed ? c.red(String(health.failed)) : '0'],
    ['error rate', health.error_rate != null ? `${(health.error_rate * 100).toFixed(1)}%` : '—'],
    ['cold starts', String(health.cold_starts ?? 0)],
    ['avg duration', health.avg_duration_ms != null ? `${health.avg_duration_ms} ms` : '—'],
  ]);

  return 0;
}

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverlessInvocations(args, flags) {
  const { client, siteId } = await context(args[0], flags);
  const rows = (await client.get(`/serverless/sites/${encodeURIComponent(siteId)}/invocations${invocationQuery(flags)}`))?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return 0;
  }

  if (rows.length === 0) {
    info(c.dim(flags.failed ? 'No failed invocations.' : 'No invocations recorded.'));

    return 0;
  }

  printTable(
    ['time', 'src', 'status', 'ms', 'cold', 'path', 'id'],
    rows.map((row) => ({
      time: formatTime(row.created_at),
      src: row.source ?? '—',
      status: statusCell(row),
      ms: String(row.duration_ms ?? 0),
      cold: row.cold ? 'yes' : '',
      path: row.path ?? row.task ?? '—',
      id: row.id,
    })),
  );

  return 0;
}

/**
 * Failed invocations. Same feed as `invocations --failed`, given its own verb
 * because "why is my function broken" is the question people actually arrive
 * with. Exits 1 when anything failed, so it gates a deploy the same way
 * `dply errors` does.
 *
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverlessErrors(args, flags) {
  const { client, siteId } = await context(args[0], flags);
  const withFailed = { ...flags, failed: true };

  if (flags.watch || flags.follow || flags.tail) {
    return tail(
      client,
      `/serverless/sites/${encodeURIComponent(siteId)}/invocations`,
      { ...withFailed, since: isoSecondsAgo(windowSeconds(flags)) },
      (row) => {
        const status = row.status_code != null ? c.red(String(row.status_code)) : c.red('fail');
        process.stdout.write(
          `${c.dim(formatTime(row.created_at))}  ${status}  ${String(row.duration_ms ?? 0).padStart(5)}ms  ${row.path ?? row.task ?? '—'}  ${c.dim(row.id)}\n`,
        );
      },
      flags,
    );
  }

  const rows = (await client.get(`/serverless/sites/${encodeURIComponent(siteId)}/invocations${invocationQuery(withFailed)}`))?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return rows.length === 0 ? 0 : 1;
  }

  if (rows.length === 0) {
    info(`${c.green('✓')} No failed invocations.`);

    return 0;
  }

  printTable(
    ['time', 'src', 'status', 'ms', 'path', 'id'],
    rows.map((row) => ({
      time: formatTime(row.created_at),
      src: row.source ?? '—',
      status: row.status_code != null ? c.red(String(row.status_code)) : c.red('fail'),
      ms: String(row.duration_ms ?? 0),
      path: row.path ?? row.task ?? '—',
      id: row.id,
    })),
  );

  info('');
  info(c.dim(`${rows.length} failed · \`dply serverless invocation <id>\` for logs`));

  return 1;
}

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverlessInvocation(args, flags) {
  const id = String(args[0] ?? '').trim();
  if (! id) {
    throw cliError('Usage: dply serverless invocation <id> [--site <site>].', 2);
  }

  const { client, siteId } = await context(args[1], flags);
  const data = (await client.get(
    `/serverless/sites/${encodeURIComponent(siteId)}/invocations/${encodeURIComponent(id)}`,
  ))?.data ?? {};

  if (flags.json) {
    printJson(data);

    return 0;
  }

  printKeyValues([
    ['id', data.id ?? id],
    ['activation', data.activation_id ?? '—'],
    ['when', data.created_at ?? '—'],
    ['source', data.source ?? '—'],
    ['state', data.state ?? '—'],
    ['outcome', data.success ? c.green('success') : c.red('failed')],
    ['status', data.status_code != null ? String(data.status_code) : '—'],
    ['request', [data.method, data.path].filter(Boolean).join(' ') || data.task || '—'],
    ['duration', `${data.duration_ms ?? 0} ms`],
    ['wait', data.wait_time_ms != null ? `${data.wait_time_ms} ms` : '—'],
    ['init', data.init_time_ms != null ? `${data.init_time_ms} ms` : '—'],
    ['cold start', data.cold ? 'yes' : 'no'],
  ]);

  if (data.result_excerpt) {
    info('');
    info(c.bold('Result'));
    info(c.dim(String(data.result_excerpt)));
  }

  const lines = data.log_lines ?? [];
  info('');
  info(c.bold(`Logs (${lines.length})`));
  if (lines.length === 0) {
    info(c.dim('  (none captured)'));
  }
  for (const line of lines) {
    info(`  ${line}`);
  }

  return 0;
}

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverlessLogs(args, flags) {
  const { client, siteId } = await context(args[0], flags);
  const path = `/serverless/sites/${encodeURIComponent(siteId)}/logs`;
  const level = String(flags.level ?? '').trim();

  const render = (row) => {
    const tone = ['error', 'critical', 'alert', 'emergency'].includes(String(row.level ?? '').toLowerCase())
      ? c.red
      : String(row.level ?? '').toLowerCase() === 'warning' ? c.yellow : c.dim;
    process.stdout.write(
      `${c.dim(formatTime(row.logged_at ?? row.created_at))}  ${tone(String(row.level ?? '—').padEnd(9))}  ${row.message}\n`,
    );
  };

  const query = { since: isoSecondsAgo(windowSeconds(flags)), ...(level ? { level } : {}) };

  if (flags.follow || flags.watch || flags.tail) {
    return tail(client, path, query, render, flags);
  }

  const response = await client.get(`${path}?${queryString(query)}`);
  const rows = response?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return 0;
  }

  if (rows.length === 0) {
    info(c.dim('No log records in this window. Widen it with --window <seconds>.'));

    return 0;
  }

  rows.forEach(render);

  return 0;
}

/**
 * Shared poll loop for the two tailing commands. Follows the `meta.tail_cursor`
 * the API returns rather than a locally computed timestamp — an empty poll
 * echoes the caller's cursor back, so the tail never skips a row that lands
 * between two requests.
 *
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} path
 * @param {Record<string, unknown>} query
 * @param {(row: Record<string, unknown>) => void} render
 * @param {Record<string, unknown>} flags
 */
async function tail(client, path, query, render, flags) {
  const intervalMs = Math.max(500, Math.min(60000, Number.parseInt(String(flags.interval ?? '2000'), 10) || 2000));
  let cursor = String(query.since);
  let aborted = false;

  process.on('SIGINT', () => {
    if (aborted) return;
    aborted = true;
    info(c.dim('\n— tail stopped —'));
    process.exit(0);
  });

  while (! aborted) {
    let response;
    try {
      response = await client.get(`${path}?${queryString({ ...query, since: cursor })}`);
    } catch (err) {
      warn(`tail: ${err.message} — retrying in ${intervalMs}ms`);
      await sleep(intervalMs);
      continue;
    }

    for (const row of response?.data ?? []) {
      render(row);
    }

    cursor = response?.meta?.tail_cursor ?? cursor;
    await sleep(intervalMs);
  }

  return 0;
}

/**
 * Resolve a function by ID or name. Names are matched against the org's
 * function list, so `dply serverless status checkout` works without an ID.
 *
 * @param {string|undefined} positional
 * @param {Record<string, unknown>} flags
 */
async function context(positional, flags) {
  const client = await requireClient(flags);
  let candidate = String(flags.site || flags.s || positional || process.env.DPLY_SERVERLESS_SITE || process.env.DPLY_SITE || '').trim();

  if (! candidate) {
    const link = await readSiteLink();
    if (link?.link?.siteId) {
      candidate = link.link.siteId;
    }
  }

  if (! candidate) {
    throw cliError(
      'No function specified. Pass --site <id-or-name>, set DPLY_SERVERLESS_SITE, or run `dply serverless list`.',
      2,
    );
  }

  if (/^[0-9A-Za-z]{26}$/.test(candidate)) {
    return { client, siteId: candidate };
  }

  const rows = (await client.get('/serverless/sites'))?.data ?? [];
  const needle = candidate.toLowerCase();

  const exact = rows.find((row) => String(row.name).toLowerCase() === needle);
  if (exact?.id) {
    return { client, siteId: exact.id };
  }

  const partial = rows.filter((row) => String(row.name).toLowerCase().includes(needle));
  if (partial.length === 1) {
    return { client, siteId: partial[0].id };
  }

  throw cliError(
    partial.length > 1
      ? `Multiple functions match "${candidate}". Pass the full site ID instead.`
      : `No function matched "${candidate}". Run \`dply serverless list\`.`,
    2,
  );
}

/**
 * @param {Record<string, unknown>} flags
 */
export function invocationQuery(flags) {
  const query = {};

  if (flags.failed) query.failed = '1';
  if (flags.source) query.source = String(flags.source);
  if (flags.limit != null) query.limit = String(flags.limit);
  if (flags.since) query.since = String(flags.since);

  const encoded = queryString(query);

  return encoded ? `?${encoded}` : '';
}

/**
 * @param {Record<string, unknown>} query
 */
export function queryString(query) {
  return Object.entries(query)
    .filter(([, value]) => value !== undefined && value !== null && value !== '' && value !== false)
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value === true ? '1' : value))}`)
    .join('&');
}

/**
 * @param {Record<string, unknown>} row
 */
function statusCell(row) {
  if (row.state === 'pending') {
    return c.dim('pending');
  }

  const status = row.status_code != null ? String(row.status_code) : (row.success ? 'ok' : 'fail');

  return row.success ? c.green(status) : c.red(status);
}

/**
 * @param {Record<string, unknown>} flags
 */
export function windowSeconds(flags) {
  const parsed = Number.parseInt(String(flags.window ?? '3600'), 10) || 3600;

  return Math.max(1, Math.min(604800, parsed));
}

/**
 * @param {number} seconds
 */
function isoSecondsAgo(seconds) {
  return new Date(Date.now() - seconds * 1000).toISOString();
}

/**
 * @param {unknown} iso
 */
function formatTime(iso) {
  const text = String(iso ?? '');

  return text.slice(11, 19) || '--:--:--';
}

function printServerlessHelp() {
  info(`${c.bold('dply serverless')} — managed functions`);
  info('');
  for (const [name, summary] of Object.entries(SUBCOMMANDS)) {
    info(`  ${name.padEnd(14)} ${c.dim(summary)}`);
  }
  info('');
  info(c.dim('Site: --site <id-or-name> · $DPLY_SERVERLESS_SITE · $DPLY_SITE · linked repo'));
  info(c.dim('Flags: --json · --limit N · --window s · --interval ms · --source web|tick|test'));
  info(c.dim('`serverless errors` reads failed invocations and exits 1 when any failed.'));
  info(c.dim('`dply errors` is the separate site-level ErrorEvent view (all site kinds).'));

  return 0;
}

/**
 * @param {number} ms
 */
function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * @param {string} message
 * @param {number} [exitCode]
 */
function cliError(message, exitCode = 1) {
  const err = new Error(message);
  err.exitCode = exitCode;

  return err;
}

/** name -> one-line summary, for `dply help` and `dply ls serverless`. */
export const SERVERLESS_COMMANDS = SUBCOMMANDS;

export const SERVERLESS_SUBCOMMANDS = Object.keys(SUBCOMMANDS);
