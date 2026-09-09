/**
 * `dply errors` — open error events for a site, any kind of site.
 *
 * Backed by GET /v1/sites/{site}/errors (ErrorEvent rows, dismissed ones
 * excluded server-side). The endpoint has no `since` cursor, so `--watch`
 * re-reads the window each tick and dedupes on event id.
 *
 * This is the triage stream: failed deploys, failed operations, and swept 5xx.
 */
import { requireClient } from './server-context.mjs';
import { resolveAnySiteId } from './site-context.mjs';
import { isInteractive, pickRow } from './pick.mjs';
import { c, info, ok, printJson, printKeyValues, printTable, warn } from './print.mjs';

/** The API clamps `limit` to this — mirror it so `--limit 500` fails loudly here. */
const MAX_LIMIT = 50;

/** Subcommands that act on an error instead of selecting a site. */
const ACTIONS = new Set(['dismiss', 'retry', 'fix', 'remediate']);

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function errorsCommand(args, flags) {
  const sub = args[0];

  if (sub === 'help' || flags.help || flags.h) {
    return printErrorsHelp();
  }

  if (sub && ACTIONS.has(sub.toLowerCase())) {
    const action = sub.toLowerCase() === 'remediate' ? 'fix' : sub.toLowerCase();

    return runAction(action, args.slice(1), flags);
  }

  // `dply errors [site]` — the only positional is an optional site selector,
  // so anything in args[0] that is not a subcommand is that selector.
  const positional = sub === 'list' || sub === 'ls' ? args[1] : sub;

  const client = await requireClient(flags);
  const siteId = await resolveAnySiteId(client, flags, positional);

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
  } else {
    printSummary(events);
  }

  if (events.length === 0) {
    return 0;
  }

  // On a TTY the list is the start of triage, not the end of it: pick an error
  // and act on it without hunting for its id. `--no-prompt` (or a pipe, or
  // $DPLY_NO_PROMPT) keeps the old print-and-exit shape for scripts.
  if (promptAllowed(flags)) {
    await triage(client, siteId, events, flags);
  }

  return 1;
}

/**
 * `dply errors dismiss <id|--all>` · `retry <id>` · `fix <id> [--action key]`
 *
 * @param {string} action
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
async function runAction(action, args, flags) {
  const client = await requireClient(flags);
  const siteId = await resolveAnySiteId(client, flags, undefined);
  const all = Boolean(flags.all);
  let id = args[0];

  if (! id && ! all) {
    const events = filterEvents(await fetchErrors(client, siteId, flags), flags);

    if (events.length === 0) {
      info(`${c.green('✓')} No open errors.`);

      return 0;
    }

    const picked = await pickErrorRow(events, actionTitle(action));
    if (! picked) {
      throw cliError(`Which error? Pass an id (see \`dply errors\`)${action === 'dismiss' ? ', or --all' : ''}.`, 2);
    }

    id = String(picked.id);
  }

  return applyAction(client, siteId, action, { id, all, actionKey: flags.action });
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {string} action
 * @param {{ id?: string, all?: boolean, actionKey?: unknown }} options
 */
async function applyAction(client, siteId, action, { id, all = false, actionKey }) {
  const base = `/sites/${encodeURIComponent(siteId)}/errors`;

  if (action === 'dismiss') {
    const response = await client.post(`${base}/dismiss`, all ? { all: true } : { id });
    const count = response?.data?.dismissed ?? 0;

    ok(all ? `Dismissed ${count} ${count === 1 ? 'error' : 'errors'}.` : 'Dismissed.');

    return 0;
  }

  if (action === 'retry') {
    await client.post(`${base}/${encodeURIComponent(String(id))}/retry`, {});
    ok('Retry queued — follow it with `dply errors --watch` or the site workspace.');

    return 0;
  }

  await client.post(
    `${base}/${encodeURIComponent(String(id))}/remediate`,
    actionKey ? { action: String(actionKey) } : {},
  );
  ok('Fix queued — the error clears itself when it finishes.');

  return 0;
}

/**
 * Pick an error, then pick what to do with it. Loops until the operator stops,
 * so a screen full of errors can be cleared in one sitting.
 *
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {Array<Record<string, any>>} events
 * @param {Record<string, unknown>} flags
 */
async function triage(client, siteId, events, flags) {
  let remaining = events;

  while (remaining.length > 0) {
    const event = await pickErrorRow(remaining, 'Act on an error');
    if (! event) {
      return;
    }

    const choice = await pickRow(actionsFor(event), {
      title: truncate(String(event.title ?? 'Error'), 68),
      label: (row) => row.label,
      hint: (row) => row.hint ?? '',
    });

    if (! choice) {
      return;
    }

    try {
      if (choice.key === 'open') {
        const { openInBrowser } = await import('./commands.mjs');
        await openInBrowser(String(event.link_url));
      } else if (choice.key === 'detail') {
        info('');
        printDetailed([event]);
      } else {
        await applyAction(client, siteId, choice.key, { id: String(event.id) });
      }
    } catch (err) {
      warn(err?.message ?? String(err));
    }

    // Re-read rather than splice locally: a queued fix clears its own event, so
    // the server's list is the only honest one.
    remaining = filterEvents(await fetchErrors(client, siteId, flags), flags);

    if (remaining.length === 0) {
      info('');
      info(`${c.green('✓')} No open errors left.`);
    }
  }
}

/**
 * @param {Array<Record<string, any>>} events
 * @param {string} title
 */
function pickErrorRow(events, title) {
  return pickRow(events, {
    title,
    label: (event) => truncate(String(event.title ?? event.id), 68),
    hint: (event) => [formatTime(event.occurred_at), event.category].filter(Boolean).join(' · '),
  });
}

/**
 * Only the verbs this event actually supports — the API says which.
 *
 * @param {Record<string, any>} event
 */
function actionsFor(event) {
  /** @type {Array<{ key: string, label: string, hint?: string }>} */
  const actions = [{ key: 'detail', label: 'Show detail', hint: 'full text + remediation code' }];

  if (event.retryable) {
    actions.push({ key: 'retry', label: 'Retry', hint: 're-run the operation that failed' });
  }

  if (event.remediation_code) {
    actions.push({ key: 'fix', label: 'Apply the known fix', hint: String(event.remediation_code) });
  }

  if (event.link_url) {
    actions.push({ key: 'open', label: 'Open in the dashboard' });
  }

  actions.push({ key: 'dismiss', label: 'Dismiss', hint: 'clear it from the stream' });

  return actions;
}

/**
 * @param {string} action
 */
function actionTitle(action) {
  return action === 'dismiss' ? 'Dismiss which error?' : action === 'retry' ? 'Retry which error?' : 'Fix which error?';
}

/**
 * Prompting is for humans at a terminal. Pipes, CI, --json and --watch keep the
 * old behaviour so `dply deploy --wait && dply errors` stays a clean gate.
 *
 * @param {Record<string, unknown>} flags
 */
function promptAllowed(flags) {
  if (flags['no-prompt'] || flags.quiet || flags.json || process.env.DPLY_NO_PROMPT) {
    return false;
  }

  return isInteractive();
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
  info(`  ${'errors [site]'.padEnd(24)} ${c.dim('Newest open errors (linked site, --site, or DPLY_SITE)')}`);
  info(`  ${'errors dismiss [id]'.padEnd(24)} ${c.dim('Clear one error · --all for every open one')}`);
  info(`  ${'errors retry [id]'.padEnd(24)} ${c.dim('Re-run the operation that failed (commands.run)')}`);
  info(`  ${'errors fix [id]'.padEnd(24)} ${c.dim('Apply the catalogued fix · --action <key> (commands.run)')}`);
  info('');
  info(c.dim('Leave the id off on a TTY and you get a picker · listing on a TTY offers the same actions'));
  info(c.dim(`Flags: --full · --json · --limit N (max ${MAX_LIMIT}) · --category a,b · --watch [--interval ms] · --no-prompt`));
  info(c.dim('Exit code is 1 when any open error is reported — usable as a CI gate.'));
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

export const __testing = { actionsFor, filterEvents, parseLimit, parseInterval, promptAllowed, truncate, watchRequested };
