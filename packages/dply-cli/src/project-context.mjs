import { requireClient } from './server-context.mjs';

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {Record<string, unknown>} flags
 * @param {string|undefined} positional
 */
export async function resolveProjectId(client, flags, positional) {
  const fromFlag = flags.project || flags.p;
  const candidate = String(fromFlag || positional || process.env.DPLY_PROJECT || '').trim();

  if (!candidate) {
    const err = new Error('Pass --project <id-or-slug> (or set DPLY_PROJECT).');
    err.exitCode = 2;

    throw err;
  }

  if (/^[0-9A-Za-z]{26}$/.test(candidate)) {
    return candidate;
  }

  const response = await client.get('/projects');
  const rows = response?.data ?? [];
  const exact = rows.find((row) => String(row.slug).toLowerCase() === candidate.toLowerCase()
    || String(row.name).toLowerCase() === candidate.toLowerCase());
  if (exact?.id) {
    return exact.id;
  }

  const partial = rows.filter((row) => String(row.name).toLowerCase().includes(candidate.toLowerCase())
    || String(row.slug).toLowerCase().includes(candidate.toLowerCase()));
  if (partial.length === 1) {
    return partial[0].id;
  }

  const err = new Error(
    partial.length > 1
      ? `Multiple projects match "${candidate}". Pass the full project ID instead.`
      : `No project matched "${candidate}". Run \`dply project list\`.`,
  );
  err.exitCode = 2;

  throw err;
}

/**
 * @param {Record<string, unknown>} flags
 * @param {string|undefined} positional
 */
export async function requireProjectId(flags, positional) {
  const client = await requireClient(flags);

  return resolveProjectId(client, flags, positional);
}
