import { defaultBaseUrl, readGlobalConfig, readSiteLink } from './config.mjs';
import { requireClient } from './server-context.mjs';

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
    const err = new Error(
      'No BYO site specified. Pass --site <id>, set DPLY_SITE, run `dply link --byo <id>`, or link this repo first.',
    );
    err.exitCode = 2;

    throw err;
  }

  if (/^[0-9A-Za-z]{26}$/.test(candidate)) {
    return candidate;
  }

  const response = await client.get('/sites');
  const rows = response?.data ?? [];
  const exact = rows.find((row) => String(row.name).toLowerCase() === candidate.toLowerCase());
  if (exact?.id) {
    return exact.id;
  }

  const partial = rows.filter((row) => String(row.name).toLowerCase().includes(candidate.toLowerCase()));
  if (partial.length === 1) {
    return partial[0].id;
  }

  const err = new Error(
    partial.length > 1
      ? `Multiple BYO sites match "${candidate}". Pass the full site ID instead.`
      : `No BYO site matched "${candidate}". Run \`dply site list\`.`,
  );
  err.exitCode = 2;

  throw err;
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
