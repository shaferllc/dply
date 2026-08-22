/**
 * `dply serverless` — managed functions (DO Functions / AWS Lambda web actions).
 *
 * Against /api/v1/serverless/*. Two things worth knowing about the shape here:
 *
 *  - `serverless errors` reads *failed invocations* — every one, raw. It is the
 *    drill-down under `dply errors`, not a rival: a failing streak also folds
 *    into one ErrorEvent row per site, so `dply errors` reports that the
 *    function is broken and this reports which requests broke.
 *    (The platform's activations API returns nothing, so dply's own invocation
 *    table is the only record of a failure.)
 *  - `serverless logs` reads the site's *application* log drain. Per-invocation
 *    stdout/stderr lives on the invocation — `serverless invocation <id>`.
 */
import { requireClient } from './server-context.mjs';
import { readSiteLink } from './config.mjs';
import { pickRow } from './pick.mjs';
import { c, info, ok, printJson, printKeyValues, printTable, warn } from './print.mjs';

const SUBCOMMANDS = {
  list: 'List functions in your organization.',
  status: '[site] — function detail + 24h health.',
  invocations: '[site] — recent invocations (--failed, --source, --limit).',
  errors: '[site] — every failed invocation (--watch to poll).',
  logs: '[site] — application logs (--follow, --level error).',
  invocation: '<id> [--site …] — one invocation with its log lines.',
  platform: '[site] — what is deployed on the host (--schedules).',
  invoke: '[site] — send a test request (--method, --path, --body).',
  credentials: '[site] — namespace key dply uses (--set <id:secret>).',
  workers: '[site] — queue engine + worker definitions (--enable, --tick, --add).',
  schedule: '[site] — scheduler switch + firing history (--enable, --tick).',
  env: '[site] list | set KEY=v | rm KEY | push --file .env | pull.',
  runtime: '[site] — limits, HTTP exposure, parameters, maintenance, warm start.',
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
    case 'platform':
      return serverlessPlatform(rest, flags);
    case 'invoke':
    case 'test':
      return serverlessInvoke(rest, flags);
    case 'credentials':
    case 'creds':
      return serverlessCredentials(rest, flags);
    case 'workers':
    case 'worker':
      return serverlessWorkers(rest, flags);
    case 'schedule':
    case 'scheduler':
      return serverlessSchedule(rest, flags);
    case 'env':
      return serverlessEnv(rest, flags);
    case 'runtime':
    case 'config':
      return serverlessRuntime(rest, flags);
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
 * `dply errors` does — and unlike `dply errors`, which folds a streak to one
 * open event, this lists every failure.
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
 * `dply serverless platform [site]` — the workspace Platform tab's Inspector:
 * the action actually deployed on the functions host, plus what else lives in
 * its namespace. `--schedules` swaps in the cron triggers panel.
 *
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverlessPlatform(args, flags) {
  const { client, siteId } = await context(args[0], flags);

  if (flags.schedules) {
    return platformSchedules(client, siteId, flags);
  }

  const data = (await client.get(`/serverless/sites/${encodeURIComponent(siteId)}/platform`))?.data ?? {};

  if (flags.json) {
    printJson(data);

    return 0;
  }

  const action = data.action;

  info(c.bold(String(data.action_name || siteId)));

  if (! action) {
    warn(data.error ?? 'No action deployed on the host yet.');

    return 1;
  }

  printKeyValues([
    ['version', action.version ?? '—'],
    ['runtime', action.runtime ?? '—'],
    ['entry', action.entry ?? '—'],
    ['memory', action.memory_mb ? `${action.memory_mb} MB` : '—'],
    ['timeout', action.timeout_ms ? `${action.timeout_ms} ms` : '—'],
    ['concurrency', action.concurrency != null ? String(action.concurrency) : '—'],
    ['log limit', action.log_limit_mb ? `${action.log_limit_mb} MB` : '—'],
    ['web export', action.web_export ? 'yes' : 'no'],
    ['published', action.published ? 'yes' : 'no'],
    ['code size', action.code_bytes ? `${Math.round(action.code_bytes / 1024)} KB` : '—'],
  ]);

  const ns = data.namespace ?? {};

  info('');
  info(c.bold('Namespace'));
  printKeyValues([
    ['actions', countAndNames(ns.actions)],
    ['packages', countAndNames(ns.packages)],
    ['triggers', countAndNames(ns.triggers)],
    ['rules', countAndNames(ns.rules)],
  ]);

  info('');
  info(c.dim('Schedules: `dply serverless platform --schedules` · test it: `dply serverless invoke`'));

  return 0;
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {Record<string, unknown>} flags
 */
async function platformSchedules(client, siteId, flags) {
  const data = (await client.get(`/serverless/sites/${encodeURIComponent(siteId)}/platform/schedules`))?.data ?? {};

  if (flags.json) {
    printJson(data);

    return 0;
  }

  if (data.error) {
    warn(String(data.error));
  }

  const schedules = data.schedules ?? [];

  if (schedules.length === 0) {
    info(c.dim('No schedules on this function.'));
  } else {
    printTable(
      ['name', 'cron', 'enabled'],
      schedules.map((row) => ({
        name: String(row.name ?? '—'),
        cron: String(row.cron ?? row.scheduled_details?.cron ?? '—'),
        enabled: row.is_enabled === false ? c.dim('off') : c.green('on'),
      })),
    );
  }

  info('');
  info(c.dim(`triggers: ${(data.triggers ?? []).length} · rules: ${(data.rules ?? []).length} — add or remove them on the Platform tab`));

  return 0;
}

/**
 * `dply serverless invoke [site]` — the Console panel. Recorded as a test
 * invocation, so it shows up in `dply serverless invocations` afterwards.
 *
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverlessInvoke(args, flags) {
  const { client, siteId } = await context(args[0], flags);

  const body = {
    method: String(flags.method ?? flags.X ?? 'GET').toUpperCase(),
    path: String(flags.path ?? '/'),
    body: flags.body != null ? String(flags.body) : '',
    query: flags.query != null ? String(flags.query) : '',
    headers: parseHeaders(flags.header ?? flags.H),
  };

  const data = (await client.post(`/serverless/sites/${encodeURIComponent(siteId)}/invoke`, body))?.data ?? {};

  if (flags.json) {
    printJson(data);

    return data.success ? 0 : 1;
  }

  const status = data.status_code != null ? String(data.status_code) : (data.success ? 'ok' : 'fail');

  info(`${data.success ? c.green('●') : c.red('●')} ${body.method} ${body.path} ${c.bold(status)} ${c.dim(`${data.duration_ms ?? 0}ms`)}`);

  if (data.error) {
    warn(String(data.error));
  }

  if (data.excerpt) {
    info('');
    info(String(data.excerpt));
  }

  for (const line of data.logs ?? []) {
    info(c.dim(typeof line === 'string' ? line : JSON.stringify(line)));
  }

  if (data.id) {
    info('');
    info(c.dim(`Recorded as invocation ${data.id} — \`dply serverless invocation ${data.id}\``));
  }

  return data.success ? 0 : 1;
}

/**
 * `dply serverless credentials [site]` — the Credentials tab: which namespace
 * key dply holds for this function and whether the host still accepts it.
 * `--set <key-id:secret>` stores a rotated key (verified before it sticks).
 *
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverlessCredentials(args, flags) {
  const { client, siteId } = await context(args[0], flags);
  const path = `/serverless/sites/${encodeURIComponent(siteId)}/credentials`;

  if (flags.set) {
    const data = (await client.put(path, { access_key: String(flags.set) }))?.data ?? {};

    ok(`Key ${data.key_id ?? ''} stored — the host answered with ${data.actions ?? 0} action(s).`);

    return 0;
  }

  const data = (await client.get(path))?.data ?? {};

  if (flags.json) {
    printJson(data);

    return data.ok ? 0 : 1;
  }

  printKeyValues([
    ['namespace', data.namespace || '—'],
    ['api host', data.api_host || '—'],
    ['key id', data.key_id || '—'],
    ['status', data.ok ? c.green('accepted') : c.red('rejected')],
    ['actions seen', data.ok ? String(data.actions ?? 0) : '—'],
  ]);

  if (! data.ok && data.error) {
    info('');
    warn(String(data.error));
    info(c.dim('Rotate the key on the functions host, then store it: `dply serverless credentials --set <key-id:secret>`'));
  }

  return data.ok ? 0 : 1;
}

/**
 * `dply serverless workers [site]` — the Workers tab: the queue engine (one
 * boolean driving the minute-cadence tick) and the named worker definitions.
 *
 * Reads with no flags. Writes are one flag each, so a change is a single
 * command: `--enable` / `--disable` the engine, `--tick` to fire one now,
 * `--add <name> --command <cmd>`, `--start` / `--stop` / `--remove <name>`.
 * A worker is addressed by name or id — names are what the operator typed.
 *
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverlessWorkers(args, flags) {
  const { client, siteId } = await context(args[0], flags);
  const path = `/serverless/sites/${encodeURIComponent(siteId)}/workers`;

  if (flags.tick) {
    const data = (await client.post(`${path}/tick`))?.data ?? {};
    const line = `HTTP ${data.status_code ?? '—'}, ${data.duration_ms ?? 0} ms`;

    if (data.success) {
      ok(`Queue tick fired — ${line}.`);
    } else {
      warn(`Queue tick reported a failure — ${line}.`);
    }

    return data.success ? 0 : 1;
  }

  if (flags.enable || flags.disable) {
    const enabled = Boolean(flags.enable);
    await client.put(path, { enabled });
    ok(enabled ? 'Queue engine enabled — dply ticks the function every minute.' : 'Queue engine disabled.');

    return 0;
  }

  if (flags.add) {
    if (! flags.command) {
      throw cliError('`--add <name>` needs `--command "<command or function-ref>"`.', 2);
    }

    const worker = (await client.post(path, {
      name: String(flags.add),
      command: String(flags.command),
      concurrency: flags.concurrency == null ? undefined : Number(flags.concurrency),
      restart_policy: flags.restart == null ? undefined : String(flags.restart),
    }))?.data ?? {};

    ok(`Worker "${worker.name}" added (${worker.id}).`);

    return 0;
  }

  if (flags.start || flags.stop) {
    const target = String(flags.start || flags.stop);
    const enabled = Boolean(flags.start);
    const worker = (await client.patch(`${path}/${encodeURIComponent(target)}`, { enabled }))?.data ?? {};

    ok(`Worker "${worker.name ?? target}" ${enabled ? 'enabled' : 'disabled'}.`);

    return 0;
  }

  if (flags.remove) {
    await client.delete(`${path}/${encodeURIComponent(String(flags.remove))}`);
    ok(`Worker "${flags.remove}" removed.`);

    return 0;
  }

  const data = (await client.get(path))?.data ?? {};

  if (flags.json) {
    printJson(data);

    return 0;
  }

  const lastTick = data.last_tick;

  printKeyValues([
    ['queue engine', data.engine_enabled ? c.green('on') : c.dim('off')],
    ['last tick', lastTick
      ? `${formatTime(lastTick.at)} ${lastTick.status === 'ok' ? c.green('ok') : c.red('failed')} ${c.dim(`HTTP ${lastTick.status_code ?? '—'} · ${lastTick.duration_ms ?? 0} ms`)}`
      : '—'],
  ]);

  const workers = Array.isArray(data.workers) ? data.workers : [];

  info('');

  if (workers.length === 0) {
    info(c.dim('No workers defined. Add one: `dply serverless workers --add queue-default --command "php artisan queue:work"`.'));

    return 0;
  }

  printTable(
    ['name', 'command', 'replicas', 'restart', 'status'],
    workers.map((worker) => ({
      name: worker.name,
      command: worker.command || '—',
      replicas: String(worker.concurrency ?? 1),
      restart: worker.restart_policy ?? '—',
      status: workerStatusCell(worker),
    })),
  );

  return 0;
}

/**
 * @param {Record<string, unknown>} worker
 */
function workerStatusCell(worker) {
  const label = String(worker.status_label ?? worker.status ?? '—');

  switch (worker.status) {
    case 'running':
      return c.green(label);
    case 'erroring':
      return c.red(label);
    case 'stopped':
      return c.dim(label);
    default:
      return c.yellow(label);
  }
}

/**
 * `dply serverless schedule [site]` — the Schedule tab: dply's minute-cadence
 * scheduler tick, and the history of what it fired.
 *
 * `--enable` / `--disable` flips the switch, `--tick` fires one now. Reads
 * take `--limit` and `--failed` (exits 1 when any listed tick failed), so
 * `schedule --failed` works as a check in a script.
 *
 * The host's own cron triggers are a different thing entirely — those are
 * `dply serverless platform --schedules`.
 *
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverlessSchedule(args, flags) {
  const { client, siteId } = await context(args[0], flags);
  const path = `/serverless/sites/${encodeURIComponent(siteId)}/schedule`;

  if (flags.tick) {
    const data = (await client.post(`${path}/tick`))?.data ?? {};
    const line = `HTTP ${data.status_code ?? '—'}, ${data.duration_ms ?? 0} ms`;

    if (data.success) {
      ok(`Scheduler tick fired — ${line}.`);
    } else {
      warn(`Scheduler tick reported a failure — ${line}.`);
    }

    return data.success ? 0 : 1;
  }

  if (flags.enable || flags.disable) {
    const enabled = Boolean(flags.enable);
    await client.put(path, { enabled });
    ok(enabled ? 'Scheduler enabled — dply ticks the function every minute.' : 'Scheduler disabled.');

    return 0;
  }

  const query = queryString({ limit: flags.limit, failed: flags.failed ? '1' : '' });
  const data = (await client.get(query ? `${path}?${query}` : path))?.data ?? {};
  const ticks = Array.isArray(data.ticks) ? data.ticks : [];

  if (flags.json) {
    printJson(data);

    return ticks.some((tick) => tick.status !== 'ok') ? 1 : 0;
  }

  printKeyValues([
    ['scheduler', data.enabled ? c.green('on') : c.dim('off')],
    ['ticks recorded', String(data.total_ticks ?? 0)],
  ]);

  info('');

  if (ticks.length === 0) {
    info(c.dim(flags.failed
      ? 'No failed scheduler ticks.'
      : 'No scheduler ticks recorded yet. Enable it with `--enable`, or fire one with `--tick`.'));

    return 0;
  }

  printTable(
    ['when', 'status', 'http', 'duration', 'detail'],
    ticks.map((tick) => ({
      when: formatTime(tick.at),
      status: tick.status === 'ok' ? c.green('ok') : c.red('failed'),
      http: tick.http_status == null ? '—' : String(tick.http_status),
      duration: `${tick.duration_ms ?? 0}ms`,
      detail: excerpt(tick.error || tick.body_preview),
    })),
  );

  return ticks.some((tick) => tick.status !== 'ok') ? 1 : 0;
}

/**
 * One line of a tick's captured output, trimmed to fit a table cell.
 *
 * @param {unknown} value
 */
function excerpt(value) {
  const text = String(value ?? '').replace(/\s+/g, ' ').trim();

  if (text === '') {
    return '—';
  }

  return text.length > 60 ? `${text.slice(0, 59)}…` : text;
}

/**
 * `dply serverless env [site] <sub>` — the Environment tab.
 *
 * Same store the page edits (the site's encrypted env cache), reached through
 * the shared `/sites/{site}/env` endpoints. Values are write-only there, so
 * `list` and `pull` report keys; `pull --values` reads the full .env body and
 * needs the **sites.write** scope, the same posture as revealing a value in
 * the UI. Changes reach the function on its next deploy.
 *
 * @param {string[]} args  [site?, sub, ...rest]
 * @param {Record<string, unknown>} flags
 */
export async function serverlessEnv(args, flags) {
  // `env set FOO=1` and `env checkout set FOO=1` both work: a first arg that
  // names a subcommand is not a site.
  const positional = ENV_SUBCOMMANDS.includes(String(args[0] ?? '')) ? undefined : args[0];
  const rest = positional === undefined ? args : args.slice(1);
  const { client, siteId } = await context(positional, flags);
  const base = `/sites/${encodeURIComponent(siteId)}/env`;
  const sub = rest[0] ?? 'list';

  if (sub === 'list' || sub === 'pull') {
    if (sub === 'pull' && flags.values) {
      const data = (await client.get(`${base}/content`))?.data ?? {};
      process.stdout.write(String(data.content ?? ''));

      return 0;
    }

    const rows = (await client.get(base))?.data ?? [];

    if (flags.json) {
      printJson(rows);

      return 0;
    }

    if (sub === 'pull') {
      info(c.dim('# Values are write-only — print them with `--values`, set them with `env set KEY=value`.'));
      for (const row of rows) {
        info(`${row.key}=`);
      }

      return 0;
    }

    if (rows.length === 0) {
      info(c.dim('No environment variables set. Add one: `dply serverless env set KEY=value`.'));

      return 0;
    }

    printTable(
      ['key', 'source'],
      // Binding-injected keys (DB_*, REDIS_*, …) are composed at deploy time
      // and cannot be edited here — worth saying so rather than listing them
      // as if `env set` would stick.
      rows.map((row) => ({ key: row.key, source: row.managed ? c.dim('binding') : 'set here' })),
    );

    return 0;
  }

  if (sub === 'set') {
    const pairs = rest.slice(1);

    if (pairs.length === 0) {
      throw cliError('Usage: dply serverless env [site] set KEY=value [KEY=value …]', 2);
    }

    for (const pair of pairs) {
      const eq = String(pair).indexOf('=');

      if (eq <= 0) {
        throw cliError(`Invalid pair "${pair}" — expected KEY=value.`, 2);
      }

      const key = String(pair).slice(0, eq);
      await client.patch(`${base}/${encodeURIComponent(key)}`, { value: String(pair).slice(eq + 1) });
      ok(`Set ${c.cyan(key)}`);
    }

    info(c.dim('Deploy to push the new values into the function: `dply deploy`.'));

    return 0;
  }

  if (sub === 'rm' || sub === 'remove' || sub === 'unset') {
    const keys = rest.slice(1);

    if (keys.length === 0) {
      throw cliError('Usage: dply serverless env [site] rm KEY [KEY …]', 2);
    }

    for (const key of keys) {
      await client.delete(`${base}/${encodeURIComponent(String(key))}`);
      ok(`Removed ${c.cyan(String(key))}`);
    }

    return 0;
  }

  if (sub === 'push') {
    const file = flags.file || flags.f;

    if (! file) {
      throw cliError('Usage: dply serverless env [site] push --file PATH', 2);
    }

    const { readFile } = await import('node:fs/promises');
    const parsed = parseDotenvBody(await readFile(String(file), 'utf8'));
    const keys = Object.keys(parsed);

    if (keys.length === 0) {
      warn(`${file} produced no KEY=value pairs — nothing pushed.`);

      return 0;
    }

    // `--replace` mirrors the tab's "Edit all" — the file becomes the whole
    // env. Without it each key is upserted and anything absent is kept.
    if (flags.replace) {
      await client.put(`${base}/content`, { content: await readFile(String(file), 'utf8') });
      ok(`Replaced the environment with ${keys.length} key(s) from ${c.dim(String(file))}.`);

      return 0;
    }

    for (const key of keys) {
      await client.patch(`${base}/${encodeURIComponent(key)}`, { value: parsed[key] });
    }

    ok(`Pushed ${keys.length} key(s) from ${c.dim(String(file))}.`);

    return 0;
  }

  throw cliError(`Unknown env subcommand "${sub}". Use list, set, rm, push, pull.`, 2);
}

/** Subcommands of `serverless env`, so a leading site name stays optional. */
const ENV_SUBCOMMANDS = ['list', 'pull', 'set', 'rm', 'remove', 'unset', 'push'];

/**
 * Minimal KEY=value dotenv reader: strips matching surrounding quotes and
 * #-comments, no variable expansion.
 *
 * @param {string} raw
 * @returns {Record<string, string>}
 */
export function parseDotenvBody(raw) {
  /** @type {Record<string, string>} */
  const out = {};

  for (const line of String(raw).split(/\r?\n/)) {
    const trimmed = line.trim();

    if (trimmed === '' || trimmed.startsWith('#')) {
      continue;
    }

    const eq = trimmed.indexOf('=');

    if (eq <= 0) {
      continue;
    }

    const key = trimmed.slice(0, eq).trim().replace(/^export\s+/, '');

    if (! /^[A-Za-z_][A-Za-z0-9_]*$/.test(key)) {
      continue;
    }

    let value = trimmed.slice(eq + 1).trim();

    if ((value.startsWith('"') && value.endsWith('"') && value.length >= 2)
      || (value.startsWith("'") && value.endsWith("'") && value.length >= 2)) {
      value = value.slice(1, -1);
    }

    out[key] = value;
  }

  return out;
}

/**
 * `dply serverless runtime [site]` — the Runtime tab: resource limits, how the
 * function is exposed over HTTP, its bound parameters, log forwarding,
 * maintenance, and warm start.
 *
 * Reads with no flags. Every write flag maps onto one field of a single PATCH,
 * so `--memory 512 --maintenance on` is one call and one round of validation.
 * Limits land on the next deploy; HTTP metadata is pushed to the live action.
 *
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverlessRuntime(args, flags) {
  const { client, siteId } = await context(args[0], flags);
  const path = `/serverless/sites/${encodeURIComponent(siteId)}/runtime`;

  if (flags['rotate-secret']) {
    const data = (await client.post(`${path}/rotate-secret`))?.data ?? {};
    ok(data.applied
      ? 'Endpoint secret rotated — callers must use the new value now.'
      : 'Endpoint secret rotated — it applies on the next deploy.');

    return 0;
  }

  const current = (await client.get(path))?.data ?? {};
  const body = runtimePatch(flags, current);

  if (Object.keys(body).length > 0) {
    const data = (await client.patch(path, body))?.data ?? {};

    ok(data.applied
      ? 'Runtime settings saved and applied to the live function.'
      : 'Runtime settings saved — they apply on the next deploy.');

    if (flags.json) {
      printJson(data);
    }

    return 0;
  }

  if (flags.json) {
    printJson(current);

    return 0;
  }

  printRuntime(current);

  return 0;
}

/**
 * Turn runtime write flags into a PATCH body. Absent flags are absent from the
 * body — the API leaves those fields alone. `--param` merges into the stored
 * map (the endpoint replaces it whole), so `current` is read first.
 *
 * @param {Record<string, unknown>} flags
 * @param {Record<string, any>} current
 * @returns {Record<string, any>}
 */
export function runtimePatch(flags, current) {
  /** @type {Record<string, any>} */
  const body = {};

  if (flags.memory != null) body.memory_mb = Number(flags.memory);
  if (flags.timeout != null) body.timeout_ms = Number(flags.timeout);
  if (flags.concurrency != null) body.concurrency = Number(flags.concurrency);
  if (flags.logs != null) body.logs_kb = Number(flags.logs);

  if (flags['web-mode'] != null) body.web_mode = String(flags['web-mode']);
  if (flags.secure) body.secured = true;
  if (flags.unsecure) body.secured = false;
  if (flags['api-key'] != null) body.provide_api_key = onOff(flags['api-key'], 'api-key');

  /** @type {Record<string, any>} */
  const cors = {};
  if (flags.cors != null) cors.enabled = onOff(flags.cors, 'cors');
  if (flags['cors-origins'] != null) cors.allow_origins = splitList(flags['cors-origins']);
  if (flags['cors-methods'] != null) cors.allow_methods = splitList(flags['cors-methods']).map((m) => m.toUpperCase());
  if (flags['cors-headers'] != null) cors.allow_headers = splitList(flags['cors-headers']);
  if (flags['cors-credentials'] != null) cors.allow_credentials = onOff(flags['cors-credentials'], 'cors-credentials');
  if (flags['cors-max-age'] != null) cors.max_age = Number(flags['cors-max-age']);
  if (Object.keys(cors).length > 0) body.cors = cors;

  const added = listOf(flags.param);
  const removed = listOf(flags['rm-param']).map(String);

  if (added.length > 0 || removed.length > 0) {
    const parameters = { ...(current.parameters ?? {}) };

    for (const pair of added) {
      const eq = String(pair).indexOf('=');

      if (eq <= 0) {
        throw cliError(`Invalid --param "${pair}" — expected KEY=value.`, 2);
      }

      parameters[String(pair).slice(0, eq)] = String(pair).slice(eq + 1);
    }

    for (const key of removed) {
      delete parameters[key];
    }

    body.parameters = parameters;
  }

  if (flags['params-final'] != null) body.parameters_final = onOff(flags['params-final'], 'params-final');

  /** @type {Record<string, any>} */
  const forwarding = {};
  if (flags['log-provider'] != null) forwarding.provider = flags['log-provider'] === true ? '' : String(flags['log-provider']);
  if (flags['log-token'] != null) forwarding.token = String(flags['log-token']);
  if (flags['log-endpoint'] != null) forwarding.endpoint = String(flags['log-endpoint']);
  if (Object.keys(forwarding).length > 0) body.log_forwarding = forwarding;

  if (flags.maintenance != null) body.maintenance = onOff(flags.maintenance, 'maintenance');
  if (flags['keep-warm'] != null) body.keep_warm = onOff(flags['keep-warm'], 'keep-warm');

  return body;
}

/**
 * @param {Record<string, any>} data
 */
function printRuntime(data) {
  const limits = data.limits ?? {};
  const http = data.http ?? {};
  const cors = http.cors ?? {};
  const forwarding = data.log_forwarding ?? {};

  printKeyValues([
    ['memory', `${limits.memory_mb ?? '—'} MB`],
    ['timeout', `${limits.timeout_ms ?? '—'} ms`],
    ['concurrency', String(limits.concurrency ?? '—')],
    ['log capture', `${limits.logs_kb ?? '—'} KB`],
    ['limits', limits.pending_redeploy ? c.yellow('pending redeploy') : c.green('live')],
    ['http access', String(http.web_mode ?? '—')],
    ['endpoint', http.secured ? c.yellow('secured') : 'public'],
    ['api key header', http.provide_api_key ? 'sent' : 'not sent'],
    ['cors', cors.enabled
      ? `${c.green('on')} ${c.dim(`(${(cors.allow_origins ?? []).join(', ') || '*'})`)}`
      : c.dim('off')],
    ['parameters', Object.keys(data.parameters ?? {}).length === 0
      ? c.dim('none')
      : `${Object.keys(data.parameters).join(', ')}${data.parameters_final ? c.dim(' · final') : ''}`],
    ['log forwarding', forwarding.provider
      ? `${forwarding.provider}${forwarding.token_set ? '' : c.red(' (no token)')}`
      : c.dim('off')],
    ['maintenance', data.maintenance ? c.yellow('on') : c.dim('off')],
    ['warm start', data.keep_warm ? c.green('on') : c.dim('off')],
  ]);
}

/**
 * @param {unknown} value
 * @param {string} flag
 */
function onOff(value, flag) {
  if (value === true) {
    return true;
  }

  const text = String(value).toLowerCase();

  if (['on', 'true', '1', 'yes', 'enable', 'enabled'].includes(text)) {
    return true;
  }

  if (['off', 'false', '0', 'no', 'disable', 'disabled'].includes(text)) {
    return false;
  }

  throw cliError(`--${flag} takes on or off (got "${value}").`, 2);
}

/**
 * @param {unknown} value
 * @returns {string[]}
 */
function splitList(value) {
  return String(value)
    .split(',')
    .map((part) => part.trim())
    .filter((part) => part !== '');
}

/**
 * A repeatable flag, always as an array.
 *
 * @param {unknown} value
 * @returns {unknown[]}
 */
function listOf(value) {
  if (value == null || value === true) {
    return [];
  }

  return Array.isArray(value) ? value : [value];
}

/**
 * `--header 'K: V'` (repeatable) → an object the API accepts.
 *
 * @param {unknown} value
 * @returns {Record<string, string>}
 */
export function parseHeaders(value) {
  const raw = value == null ? [] : (Array.isArray(value) ? value : [value]);
  /** @type {Record<string, string>} */
  const headers = {};

  for (const entry of raw) {
    const text = String(entry);
    const colon = text.indexOf(':');

    if (colon < 1) {
      continue;
    }

    headers[text.slice(0, colon).trim()] = text.slice(colon + 1).trim();
  }

  return headers;
}

/**
 * @param {unknown} names
 */
function countAndNames(names) {
  const list = Array.isArray(names) ? names : [];

  if (list.length === 0) {
    return '0';
  }

  const shown = list.slice(0, 4).join(', ');

  return `${list.length} ${c.dim(`(${shown}${list.length > 4 ? ', …' : ''})`)}`;
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
    // On a TTY, "which function?" is a prompt, not an error.
    const picked = await pickRow(await listFunctions(client), {
      title: 'Which function?',
      hint: (row) => [row.runtime, row.is_live ? 'live' : row.status].filter(Boolean).join(' \u00b7 '),
    });

    if (picked?.id) {
      return { client, siteId: String(picked.id) };
    }

    throw cliError(
      'No function specified. Pass --site <id-or-name>, set DPLY_SERVERLESS_SITE, or run `dply serverless list`.',
      2,
    );
  }

  if (/^[0-9A-Za-z]{26}$/.test(candidate)) {
    return { client, siteId: candidate };
  }

  const rows = await listFunctions(client);
  const needle = candidate.toLowerCase();

  const exact = rows.find((row) => String(row.name).toLowerCase() === needle);
  if (exact?.id) {
    return { client, siteId: exact.id };
  }

  const partial = rows.filter((row) => String(row.name).toLowerCase().includes(needle));
  if (partial.length === 1) {
    return { client, siteId: partial[0].id };
  }

  if (partial.length > 1) {
    const picked = await pickRow(partial, {
      title: `Functions matching "${candidate}"`,
      hint: (row) => [row.runtime, row.is_live ? 'live' : row.status].filter(Boolean).join(' \u00b7 '),
    });

    if (picked?.id) {
      return { client, siteId: String(picked.id) };
    }
  }

  throw cliError(
    partial.length > 1
      ? `Multiple functions match "${candidate}". Pass the full site ID instead.`
      : `No function matched "${candidate}". Run \`dply serverless list\`.`,
    2,
  );
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @returns {Promise<Array<Record<string, any>>>}
 */
async function listFunctions(client) {
  return (await client.get('/serverless/sites'))?.data ?? [];
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
  info(c.dim('`serverless errors` lists every failed invocation and exits 1 when any failed.'));
  info(c.dim('`dply errors` is the folded view — one open event per broken function.'));

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

export const __testing = { excerpt, onOff, parseDotenvBody, parseHeaders, runtimePatch, splitList };
