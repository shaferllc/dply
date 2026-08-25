/**
 * `dply uptime` (alias `dply monitor`) — the workspace Monitor tab from a
 * terminal, for every kind of site: VM, Cloud app, or Edge site.
 *
 * Backed by GET /v1/sites/{site}/uptime (current state per monitor),
 * GET …/uptime/history (24h/7d/30d percentages + recent incidents) and
 * POST …/uptime/check (probe now — the same job the "Check now" button runs).
 *
 * Like `dply errors`, the list exits 1 when anything is down, so it works as a
 * post-deploy gate: `dply deploy --wait && dply uptime --no-prompt`.
 */
import { requireClient } from './server-context.mjs';
import { resolveAnySiteId } from './site-context.mjs';
import { isInteractive, pickRow } from './pick.mjs';
import { c, info, ok, printJson, printTable, warn } from './print.mjs';

const SUBCOMMANDS = new Set(['history', 'check', 'list', 'ls']);

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function uptimeCommand(args, flags) {
  const sub = args[0]?.toLowerCase();

  if (sub === 'help' || flags.help || flags.h) {
    return printUptimeHelp();
  }

  if (sub === 'history') {
    return uptimeHistory(args.slice(1), flags);
  }

  if (sub === 'check') {
    return uptimeCheck(args.slice(1), flags);
  }

  const positional = sub === 'list' || sub === 'ls' ? args[1] : args[0];
  const client = await requireClient(flags);
  const siteId = await resolveAnySiteId(client, flags, positional);

  if (watchRequested(flags)) {
    return watchMonitors(client, siteId, flags);
  }

  const monitors = await fetchMonitors(client, siteId);

  if (flags.json) {
    printJson(monitors);

    return 0;
  }

  printMonitors(monitors);

  const down = monitors.filter(isDown);

  if (down.length > 0 && promptAllowed(flags)) {
    await offerCheck(client, siteId, monitors);
  }

  return down.length === 0 ? 0 : 1;
}

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
async function uptimeHistory(args, flags) {
  const client = await requireClient(flags);
  const siteId = await resolveAnySiteId(client, flags, args[0]);
  const query = flags.monitor ? `?monitor=${encodeURIComponent(String(flags.monitor))}` : '';
  const rows = (await client.get(`/sites/${encodeURIComponent(siteId)}/uptime/history${query}`))?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return 0;
  }

  if (rows.length === 0) {
    info(c.dim('No monitors on this site yet.'));

    return 0;
  }

  printTable(
    ['monitor', '24h', '7d', '30d', 'incidents'],
    rows.map((row) => ({
      monitor: String(row.label ?? row.id),
      '24h': percent(row.uptime?.['24h']),
      '7d': percent(row.uptime?.['7d']),
      '30d': percent(row.uptime?.['30d']),
      incidents: String((row.incidents ?? []).length || '—'),
    })),
  );

  const ongoing = rows.flatMap((row) => (row.incidents ?? [])
    .filter((incident) => incident.ongoing)
    .map((incident) => ({ ...incident, monitor: row.label ?? row.id })));

  if (ongoing.length > 0) {
    info('');
    info(c.bold('Open incidents'));
    for (const incident of ongoing) {
      info(`  ${c.red('●')} ${incident.monitor} — ${incident.cause ?? 'down'} ${c.dim(`since ${formatTime(incident.started_at)}`)}`);
    }
  }

  info('');
  info(c.dim('One monitor: --monitor <id> · raw: --json'));

  return ongoing.length === 0 ? 0 : 1;
}

/**
 * `dply uptime check [id] [--all]` — probe now.
 *
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
async function uptimeCheck(args, flags) {
  const client = await requireClient(flags);
  const siteId = await resolveAnySiteId(client, flags, undefined);
  const all = Boolean(flags.all);
  let id = args[0];

  if (! id && ! all) {
    const monitors = await fetchMonitors(client, siteId);

    if (monitors.length === 0) {
      info(c.dim('No monitors on this site yet.'));

      return 0;
    }

    const picked = await pickMonitor(monitors, 'Check which monitor?');
    if (! picked) {
      throw cliError('Which monitor? Pass an id (see `dply uptime`), or --all.', 2);
    }

    id = String(picked.id);
  }

  return runCheck(client, siteId, { id, all });
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {{ id?: string, all?: boolean }} target
 */
async function runCheck(client, siteId, { id, all = false }) {
  const response = await client.post(
    `/sites/${encodeURIComponent(siteId)}/uptime/check`,
    all ? { all: true } : { id },
  );
  const queued = response?.data?.queued ?? 0;

  ok(`Checking ${queued} ${queued === 1 ? 'monitor' : 'monitors'} — re-run \`dply uptime\` in a moment for the result.`);

  return 0;
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 */
async function fetchMonitors(client, siteId) {
  return (await client.get(`/sites/${encodeURIComponent(siteId)}/uptime`))?.data ?? [];
}

/**
 * @param {Array<Record<string, any>>} monitors
 */
function printMonitors(monitors) {
  if (monitors.length === 0) {
    info(c.dim('No monitors on this site yet — add one on the Monitor tab.'));

    return;
  }

  printTable(
    ['monitor', 'status', 'code', 'latency', 'region', 'checked'],
    monitors.map((monitor) => ({
      monitor: String(monitor.label ?? monitor.id),
      status: statusCell(monitor),
      code: monitor.http_status != null ? String(monitor.http_status) : '—',
      latency: monitor.latency_ms != null ? `${monitor.latency_ms}ms` : '—',
      region: String(monitor.probe_region ?? '—'),
      checked: formatTime(monitor.last_checked_at),
    })),
  );

  const down = monitors.filter(isDown);

  if (down.length > 0) {
    info('');
    for (const monitor of down) {
      info(`${c.red('●')} ${c.bold(String(monitor.label ?? monitor.id))} ${c.dim(String(monitor.last_error ?? 'down'))}`);
    }
  }

  info('');
  info(c.dim('History: `dply uptime history` · probe now: `dply uptime check --all` · --watch to poll'));
}

/**
 * Offer the one action this tab has, for the monitors that need it.
 *
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {Array<Record<string, any>>} monitors
 */
async function offerCheck(client, siteId, monitors) {
  const picked = await pickMonitor(monitors.filter(isDown), 'Re-check a monitor?');

  if (! picked) {
    return;
  }

  try {
    await runCheck(client, siteId, { id: String(picked.id) });
  } catch (err) {
    warn(err?.message ?? String(err));
  }
}

/**
 * @param {Array<Record<string, any>>} monitors
 * @param {string} title
 */
function pickMonitor(monitors, title) {
  return pickRow(monitors, {
    title,
    label: (monitor) => String(monitor.label ?? monitor.id),
    hint: (monitor) => [monitor.path, monitor.probe_region].filter(Boolean).join(' · '),
  });
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {Record<string, unknown>} flags
 */
async function watchMonitors(client, siteId, flags) {
  const intervalMs = parseInterval(flags.interval);
  /** @type {Map<string, string>} */
  const seen = new Map();
  let aborted = false;

  process.on('SIGINT', () => {
    if (aborted) return;
    aborted = true;
    info(c.dim('\n— watch stopped —'));
    process.exit(0);
  });

  info(c.dim(`Watching ${monitorNoun(0)}— only changes print. Ctrl-C to stop.`));

  while (! aborted) {
    let monitors;
    try {
      monitors = await fetchMonitors(client, siteId);
    } catch (err) {
      warn(`watch: ${err.message} — retrying in ${intervalMs}ms`);
      await sleep(intervalMs);
      continue;
    }

    for (const monitor of monitors) {
      const id = String(monitor.id);
      const state = String(monitor.status ?? 'unchecked');

      if (seen.get(id) === state) {
        continue;
      }

      // First sighting prints too — otherwise a watch on an already-down site
      // looks identical to a watch on a healthy one.
      seen.set(id, state);
      const time = formatTime(monitor.last_checked_at);
      process.stdout.write(`${c.dim(time.padEnd(8))}  ${statusCell(monitor).padEnd(18)}  ${String(monitor.label ?? id)}\n`);
    }

    await sleep(intervalMs);
  }

  return 0;
}

/**
 * @param {Record<string, any>} monitor
 */
function isDown(monitor) {
  return String(monitor.status ?? '') === 'down';
}

/**
 * @param {Record<string, any>} monitor
 */
function statusCell(monitor) {
  const status = String(monitor.status ?? 'unchecked');

  if (status === 'up') {
    return c.green('up');
  }

  return status === 'down' ? c.red('down') : c.dim('unchecked');
}

/**
 * @param {unknown} value
 */
function percent(value) {
  return value == null ? '—' : `${Number(value).toFixed(2)}%`;
}

/**
 * @param {unknown} iso
 */
function formatTime(iso) {
  const text = String(iso ?? '');

  return text.slice(11, 19) || '—';
}

/**
 * @param {number} count
 */
function monitorNoun(count) {
  return count === 1 ? 'one monitor ' : 'monitors ';
}

/**
 * @param {Record<string, unknown>} flags
 */
function watchRequested(flags) {
  return Boolean(flags.watch || flags.follow || flags.tail);
}

/**
 * @param {Record<string, unknown>} flags
 */
function promptAllowed(flags) {
  if (flags['no-prompt'] || flags.quiet || flags.json || process.env.DPLY_NO_PROMPT) {
    return false;
  }

  return isInteractive();
}

/**
 * @param {unknown} value
 */
function parseInterval(value) {
  const parsed = Number.parseInt(String(value ?? '15000'), 10) || 15000;

  return Math.max(5000, Math.min(300000, parsed));
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

function printUptimeHelp() {
  info(`${c.bold('dply uptime')} — uptime monitors for a site (alias: ${c.bold('dply monitor')})`);
  info('');
  info(`  ${'uptime [site]'.padEnd(26)} ${c.dim('Every monitor: status, code, latency, region')}`);
  info(`  ${'uptime history [site]'.padEnd(26)} ${c.dim('24h / 7d / 30d uptime + recent incidents')}`);
  info(`  ${'uptime check [id]'.padEnd(26)} ${c.dim('Probe now · --all for every monitor (sites.write)')}`);
  info('');
  info(c.dim('Works for every kind of site — vm, cloud, edge.'));
  info(c.dim('Flags: --json · --watch [--interval ms] · --monitor <id> (history) · --no-prompt'));
  info(c.dim('Exit code is 1 while any monitor is down — usable as a CI gate.'));

  return 0;
}

export const SUBCOMMAND_NAMES = [...SUBCOMMANDS];
export const __testing = { isDown, percent, promptAllowed, parseInterval, watchRequested };
