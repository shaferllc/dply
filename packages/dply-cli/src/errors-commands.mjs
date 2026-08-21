/**
 * `dply errors` — open error events for a site, any kind of site.
 *
 * Backed by GET /v1/sites/{site}/errors (ErrorEvent rows, dismissed ones
 * excluded server-side). The endpoint has no `since` cursor, so `--watch`
 * re-reads the window each tick and dedupes on event id.
 *
 * This is the triage stream: failed deploys, failed operations, swept 5xx, and
 * — folded to one open event per site — broken serverless functions. The raw
 * per-invocation record behind that last one is `dply serverless errors`.
 */
import { requireClient } from './server-context.mjs';
import { resolveSiteId } from './site-context.mjs';
import { c, info, printJson, printKeyValues, printTable, warn } from './print.mjs';

/** The API clamps `limit` to this — mirror it so `--limit 500` fails loudly here. */
const MAX_LIMIT = 50;

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function errorsCommand(args, flags) {
  const sub = args[0];

  if (sub === 'help' || flags.help || flags.h) {
    return printErrorsHelp();
  }

  // `dply errors [site]` — the only positional is an optional site selector,
  // so anything in args[0] that is not a subcommand is that selector.
  const positional = sub === 'list' || sub === 'ls' ? args[1] : sub;

  const client = await requireClient(flags);
  const siteId = await resolveSiteId(client, flags, positional);

  if (watchRequested(flags)) {
    return watchErrors(client, siteId, flags);
  }

  const events = filterEvents(await fetchErrors(client, siteId, flags), flags);

  if (flags.json) {
    printJson(events);

    return 0;
  }

  if (flags.full) {
    printDetailed(events);

    return events.length === 0 ? 0 : 1;
  }

  printSummary(events);

  return events.length === 0 ? 0 : 1;
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {Record<string, unknown>} flags
 */
async function fetchErrors(client, siteId, flags) {
  const limit = parseLimit(flags.limit);
  const response = await client.get(`/sites/${encodeURIComponent(siteId)}/errors?limit=${limit}`);

  return response?.data ?? [];
}

/**
 * Category filtering is client-side — the endpoint takes no category param.
 *
 * @param {Array<Record<string, unknown>>} events
 * @param {Record<string, unknown>} flags
 */
function filterEvents(events, flags) {
  const category = String(flags.category ?? '').trim().toLowerCase();

  if (! category) {
    return events;
  }

  const wanted = new Set(category.split(',').map((part) => part.trim()).filter(Boolean));

  return events.filter((event) => wanted.has(String(event.category ?? '').toLowerCase()));
}

/**
 * @param {Array<Record<string, unknown>>} events
 */
function printSummary(events) {
  if (events.length === 0) {
    info(`${c.green('✓')} No open errors.`);

    return;
  }

  printTable(
    ['time', 'category', 'title'],
    events.map((event) => ({
      time: formatTime(event.occurred_at),
      category: String(event.category ?? '—'),
      title: truncate(String(event.title ?? '—'), 68),
    })),
  );

  info('');
  info(c.dim(`${events.length} open ${events.length === 1 ? 'error' : 'errors'} · --full for detail · --json for raw`));
}

/**
 * @param {Array<Record<string, unknown>>} events
 */
function printDetailed(events) {
  if (events.length === 0) {
    info(`${c.green('✓')} No open errors.`);

    return;
  }

  events.forEach((event, index) => {
    if (index > 0) {
      info('');
    }

    info(`${c.red('●')} ${c.bold(String(event.title ?? '—'))}`);

    const pairs = [
      ['occurred', formatTime(event.occurred_at)],
      ['category', String(event.category ?? '—')],
      ['id', String(event.id ?? '—')],
    ];

    if (event.remediation_code) {
      pairs.push(['remediation', String(event.remediation_code)]);
    }

    if (event.link_url) {
      pairs.push(['link', String(event.link_url)]);
    }

    printKeyValues(pairs);

    if (event.detail) {
      info('');
      info(indent(String(event.detail)));
    }
  });
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {Record<string, unknown>} flags
 */
async function watchErrors(client, siteId, flags) {
  const intervalMs = parseInterval(flags.interval);
  const seen = new Set();
  let printedHeader = false;
  let aborted = false;

  process.on('SIGINT', () => {
    if (aborted) return;
    aborted = true;
    info(c.dim('\n— watch stopped —'));
    process.exit(0);
  });

  while (! aborted) {
    let events;
    try {
      events = filterEvents(await fetchErrors(client, siteId, flags), flags);
    } catch (err) {
      warn(`watch: ${err.message} — retrying in ${intervalMs}ms`);
      await sleep(intervalMs);
      continue;
    }

    if (! printedHeader) {
      info(c.dim('time      category              title'));
      printedHeader = true;
    }

    // The endpoint returns newest-first; replay oldest-first so the tail reads
    // in chronological order like `edge logs`.
    for (const event of [...events].reverse()) {
      const id = String(event.id ?? '');
      if (id && seen.has(id)) continue;
      if (id) seen.add(id);

      const time = formatTime(event.occurred_at);
      const category = String(event.category ?? '—').padEnd(20);
      process.stdout.write(`${c.dim(time.padEnd(8))}  ${c.yellow(category)}  ${String(event.title ?? '—')}\n`);
    }

    await sleep(intervalMs);
  }

  return 0;
}

/**
 * `--watch` is the documented spelling; `--follow`/`--tail` are accepted
 * because that is what `site logs` and `edge status` already use.
 *
 * @param {Record<string, unknown>} flags
 */
function watchRequested(flags) {
  return Boolean(flags.watch || flags.follow || flags.tail);
}

/**
 * @param {unknown} value
 */
function parseLimit(value) {
  if (value == null) {
    return 20;
  }

  const parsed = Number.parseInt(String(value), 10);

  if (! Number.isFinite(parsed) || parsed < 1) {
    throw cliError('--limit must be a positive integer.', 2);
  }

  if (parsed > MAX_LIMIT) {
    throw cliError(`--limit cannot exceed ${MAX_LIMIT} (server cap).`, 2);
  }

  return parsed;
}

/**
 * @param {unknown} value
 */
function parseInterval(value) {
  const parsed = Number.parseInt(String(value ?? '5000'), 10) || 5000;

  return Math.max(1000, Math.min(300000, parsed));
}

/**
 * @param {unknown} iso
 */
function formatTime(iso) {
  const text = String(iso ?? '');

  return text.slice(11, 19) || '--:--:--';
}

/**
 * @param {string} text
 * @param {number} max
 */
function truncate(text, max) {
  const flat = text.replace(/\s+/g, ' ').trim();

  return flat.length > max ? `${flat.slice(0, max - 1)}…` : flat;
}

/**
 * @param {string} text
 */
function indent(text) {
  return text
    .split('\n')
    .map((line) => `  ${c.dim(line)}`)
    .join('\n');
}

function printErrorsHelp() {
  info(`${c.bold('dply errors')} — open error events for a site`);
  info('');
  info(`  ${'errors [site]'.padEnd(18)} ${c.dim('Newest open errors (linked site, --site, or DPLY_SITE)')}`);
  info('');
  info(c.dim(`Flags: --full · --json · --limit N (max ${MAX_LIMIT}) · --category a,b · --watch [--interval ms]`));
  info(c.dim('Exit code is 1 when any open error is reported — usable as a CI gate.'));
  info(c.dim('Serverless functions fold to one event here; `dply serverless errors` lists each.'));

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

export const __testing = { filterEvents, parseLimit, parseInterval, truncate, watchRequested };
