import { defaultBaseUrl, readGlobalConfig, readSiteLink } from './config.mjs';
import { requireClient } from './server-context.mjs';
import { pickRow } from './pick.mjs';
import { fetchAllSites, matchSites } from './site-index.mjs';

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {Record<string, unknown>} flags
 * @param {string|undefined} positional
 */
export async function resolveSiteId(client, flags, positional) {
  const fromFlag = flags.site || flags.s;
  let candidate = String(fromFlag || positional || process.env.DPLY_SITE || '').trim();

  if (!candidate) {
    const link = await readSiteLink();
    if (link?.link?.product === 'byo' && link.link.siteId) {
      candidate = link.link.siteId;
    }
  }

  if (!candidate) {
    // Nothing to go on — on a TTY that is a question, not an error.
    const picked = await pickRow(await listSites(client), {
      title: 'Which site?',
      hint: (row) => [row.server_name, row.status].filter(Boolean).join(' \u00b7 '),
    });

    if (picked?.id) {
      return String(picked.id);
    }

    const err = new Error(
      'No BYO site specified. Pass --site <id>, set DPLY_SITE, run `dply link --byo <id>`, or link this repo first.',
    );
    err.exitCode = 2;

    throw err;
  }

  if (/^[0-9A-Za-z]{26}$/.test(candidate)) {
    return candidate;
  }

  const rows = await listSites(client);
  const exact = rows.find((row) => String(row.name).toLowerCase() === candidate.toLowerCase());
  if (exact?.id) {
    return exact.id;
  }

  const partial = rows.filter((row) => String(row.name).toLowerCase().includes(candidate.toLowerCase()));
  if (partial.length === 1) {
    return partial[0].id;
  }

  if (partial.length > 1) {
    const picked = await pickRow(partial, {
      title: `Sites matching "${candidate}"`,
      hint: (row) => [row.server_name, row.status].filter(Boolean).join(' \u00b7 '),
    });

    if (picked?.id) {
      return String(picked.id);
    }
  }

  throw await wrongKindError(client, candidate, partial.length);
}

/**
 * A name that matches nothing here may still be a real site of another kind —
 * `dply site logs checkout-fn` when checkout-fn is a function. Say which.
 *
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} candidate
 * @param {number} partialCount
 */
async function wrongKindError(client, candidate, partialCount) {
  if (partialCount === 0) {
    const elsewhere = matchSites(await fetchAllSites(client), candidate).filter((row) => row.kind !== 'vm');

    if (elsewhere.length === 1) {
      const site = elsewhere[0];
      const noun = {
        edge: 'an Edge site',
        serverless: 'a serverless function',
        cloud: 'a Cloud container app',
      }[site.kind];
      const hint = {
        edge: `\`dply edge status --site ${site.name}\``,
        serverless: `\`dply serverless status ${site.name}\``,
        // Cloud has no CLI namespace of its own yet — errors is what works.
        cloud: `\`dply errors ${site.name}\``,
      }[site.kind];

      return cliError(
        `"${site.name}" is ${noun}, not a VM site — try ${hint}, or \`dply errors ${site.name}\`.`,
      );
    }
  }

  return cliError(
    partialCount > 1
      ? `Multiple BYO sites match "${candidate}". Pass the full site ID instead.`
      : `No BYO site matched "${candidate}". Run \`dply sites\` to see every kind.`,
  );
}

/**
 * Resolve a site of ANY kind — VM, Edge, or serverless. What `dply errors`
 * uses, because an error event belongs to a site regardless of where it runs.
 *
 * @param {import('./api.mjs').ApiClient} client
 * @param {Record<string, unknown>} flags
 * @param {string|undefined} positional
 * @returns {Promise<string>}
 */
export async function resolveAnySiteId(client, flags, positional) {
  const candidate = String(flags.site || flags.s || positional || process.env.DPLY_SITE || '').trim()
    || (await readSiteLink())?.link?.siteId
    || '';

  if (/^[0-9A-Za-z]{26}$/.test(String(candidate))) {
    return String(candidate);
  }

  const rows = await fetchAllSites(client, { kind: flags.kind });

  if (! candidate) {
    const picked = await pickRow(rows, {
      title: 'Which site?',
      hint: (row) => [row.kind, row.status].filter(Boolean).join(' \u00b7 '),
    });

    if (picked?.id) {
      return String(picked.id);
    }

    throw cliError('No site specified. Pass --site <id-or-name>, set DPLY_SITE, or link this repo with `dply link`.', 2);
  }

  const matches = matchSites(rows, String(candidate));

  if (matches.length === 1) {
    return matches[0].id;
  }

  if (matches.length > 1) {
    const picked = await pickRow(matches, {
      title: `Sites matching "${candidate}"`,
      hint: (row) => [row.kind, row.status].filter(Boolean).join(' \u00b7 '),
    });

    if (picked?.id) {
      return String(picked.id);
    }
  }

  throw cliError(`No site matched "${candidate}". Run \`dply sites\`.`, 2);
}

/**
 * @param {string} message
 * @param {number} [exitCode]
 */
function cliError(message, exitCode = 2) {
  const err = new Error(message);
  err.exitCode = exitCode;

  return err;
}

/**
 * @param {Record<string, unknown>} flags
 * @param {string|undefined} [positional]
 */
export async function requireSiteId(flags, positional) {
  const client = await requireClient(flags);

  return resolveSiteId(client, flags, positional);
}

/**
 * @param {Record<string, unknown>} flags
 * @param {string|undefined} [positional]
 */
export async function requireByoSiteContext(flags, positional) {
  const client = await requireClient(flags);
  const siteId = await resolveSiteId(client, flags, positional);
  const global = await readGlobalConfig();
  let baseUrl = String(flags['base-url'] || flags.b || global?.baseUrl || defaultBaseUrl()).replace(/\/+$/, '');
  const link = await readSiteLink();

  if (link?.link?.baseUrl) {
    baseUrl = link.link.baseUrl.replace(/\/+$/, '');
  }

  return { client, siteId, baseUrl };
}

/**
 * @returns {Promise<'byo' | 'edge' | null>}
 */
export async function linkedSiteProduct() {
  const link = await readSiteLink();

  if (!link?.link?.siteId) {
    return null;
  }

  if (link.link.product === 'byo') {
    return 'byo';
  }

  return 'edge';
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @returns {Promise<Array<Record<string, any>>>}
 */
async function listSites(client) {
  return (await client.get('/sites'))?.data ?? [];
}
