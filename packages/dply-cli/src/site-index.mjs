/**
 * One list of sites, whatever kind they are.
 *
 * `Site` is a single table on the platform — a VM site, a Cloud container app,
 * an Edge site and a serverless function differ by attributes, not by model —
 * but the API exposes three list endpoints (`/sites` returns every
 * server-backed site and self-reports each row's `kind`; `/edge/sites` and
 * `/serverless/sites` are the scope-gated, product-specific views). The CLI's
 * `sites` noun should mean what the model means, so the union happens here and
 * every caller works in normalized rows.
 *
 * Cloud has no list endpoint of its own — it does not need one now that `/sites`
 * carries `kind`. That is also the fallback for the other three: a row is only
 * classified locally when the server did not say.
 *
 * Each endpoint is fetched independently and a failure yields no rows: a token
 * without `serverless.read` should still be able to list its VM and Cloud apps.
 */
import { matchRows } from './pick.mjs';

/** @typedef {'vm'|'cloud'|'edge'|'serverless'} SiteKind */
/** @typedef {{ id: string, name: string, kind: SiteKind, status: string, url: string, hint: string, raw: Record<string, any> }} SiteRow */

/** Display order: the two you run code on, then the two you publish to. */
export const SITE_KINDS = ['vm', 'cloud', 'edge', 'serverless'];

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {{ kind?: string }} [options]
 * @returns {Promise<SiteRow[]>}
 */
export async function fetchAllSites(client, options = {}) {
  const wanted = normalizeKind(options.kind);

  // /sites covers vm and cloud (and reports edge/serverless rows for what they
  // are), so it is skipped only when the wanted kind has its own endpoint.
  const [general, edge, serverless] = await Promise.all([
    wanted === 'edge' || wanted === 'serverless' ? [] : safeGet(client, '/sites'),
    wanted && wanted !== 'edge' ? [] : safeGet(client, '/edge/sites'),
    wanted && wanted !== 'serverless' ? [] : safeGet(client, '/serverless/sites'),
  ]);

  const rows = [
    ...general.map(toGeneralRow),
    ...edge.map(toEdgeRow),
    ...serverless.map(toServerlessRow),
  ];

  // Every kind is a server-backed Site row, so /sites returns Edge sites and
  // functions too. Both sources agree on `kind` now; when an old instance's
  // /sites omits it the row falls back to `vm`, and the product-specific
  // endpoint's row is the one that wins.
  const byId = new Map();
  for (const row of rows) {
    const existing = byId.get(row.id);
    if (! existing || (existing.kind === 'vm' && row.kind !== 'vm')) {
      byId.set(row.id, row);
    }
  }

  const all = [...byId.values()].sort((a, b) => a.name.localeCompare(b.name));

  // The kind filter is applied here too: /sites answers for both vm and cloud,
  // so narrowing the fetch alone cannot separate them.
  return wanted ? all.filter((row) => row.kind === wanted) : all;
}

/**
 * @param {SiteRow[]} rows
 * @param {string} needle
 * @returns {SiteRow[]}
 */
export function matchSites(rows, needle) {
  if (/^[0-9A-Za-z]{26}$/.test(needle.trim())) {
    return rows.filter((row) => row.id === needle.trim());
  }

  return matchRows(rows, needle, (row) => row.name);
}

/**
 * @param {unknown} value
 * @returns {'vm'|'edge'|'serverless'|null}
 */
export function normalizeKind(value) {
  const kind = String(value ?? '').trim().toLowerCase();

  if (kind === '' || kind === 'all' || kind === 'any') {
    return null;
  }

  if (['vm', 'byo', 'server', 'servers'].includes(kind)) {
    return 'vm';
  }

  if (['cloud', 'container', 'containers', 'paas', 'app', 'apps'].includes(kind)) {
    return 'cloud';
  }

  if (['edge', 'static', 'ssg'].includes(kind)) {
    return 'edge';
  }

  if (['serverless', 'fn', 'function', 'functions', 'faas'].includes(kind)) {
    return 'serverless';
  }

  const err = new Error(`Unknown --kind "${value}". Use ${SITE_KINDS.join(', ')}.`);
  err.exitCode = 2;

  throw err;
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} path
 */
async function safeGet(client, path) {
  try {
    return (await client.get(path))?.data ?? [];
  } catch {
    return [];
  }
}

/**
 * A row from `/sites` — any kind. It says which via `kind`; older instances
 * that predate that field are treated as VM sites.
 *
 * @param {Record<string, any>} row
 */
function toGeneralRow(row) {
  const kind = SITE_KINDS.includes(row.kind) ? row.kind : 'vm';

  return {
    id: String(row.id),
    name: String(row.name ?? row.id),
    kind: /** @type {SiteKind} */ (kind),
    status: String(row.status ?? '—'),
    url: String(row.visit_url ?? row.primary_hostname ?? '—'),
    // A Cloud app's server row is dply-managed plumbing, not somewhere you ssh.
    hint: kind === 'cloud'
      ? String(row.runtime_mode_label ?? row.runtime ?? '')
      : String(row.server_name ?? ''),
    raw: row,
  };
}

/** @param {Record<string, any>} row */
function toEdgeRow(row) {
  return {
    id: String(row.id),
    name: String(row.name ?? row.id),
    kind: /** @type {'edge'} */ ('edge'),
    status: String(row.status ?? '—'),
    url: String(row.live_url ?? row.hostname ?? '—'),
    hint: [row.runtime_mode, row.is_preview ? 'preview' : ''].filter(Boolean).join(' · '),
    raw: row,
  };
}

/** @param {Record<string, any>} row */
function toServerlessRow(row) {
  return {
    id: String(row.id),
    name: String(row.name ?? row.id),
    kind: /** @type {'serverless'} */ ('serverless'),
    status: row.is_live ? 'live' : String(row.status ?? '—'),
    url: String(row.url ?? '—'),
    hint: String(row.runtime ?? ''),
    raw: row,
  };
}
