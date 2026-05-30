import { ApiClient } from './api.mjs';
import { readGlobalConfig } from './config.mjs';

/**
 * @param {Record<string, unknown>} [flags]
 */
export function requireClient(flags = {}) {
  const cfg = readGlobalConfig();
  const baseUrl = (flags['base-url'] || flags.b || cfg?.baseUrl || '').replace(/\/+$/, '');
  const token = cfg?.token;

  if (!token) {
    const err = new Error('Not logged in. Run `dply login` first (or `dply login --token …` for CI).');
    err.exitCode = 2;

    throw err;
  }

  if (!baseUrl) {
    const err = new Error('No API base URL configured. Re-run `dply login --base-url https://your-instance`.');
    err.exitCode = 2;

    throw err;
  }

  return new ApiClient({ baseUrl, token });
}

/**
 * @param {ApiClient} client
 * @param {Record<string, unknown>} flags
 * @param {string|undefined} positional
 */
export async function resolveServerId(client, flags, positional) {
  const fromFlag = flags.server || flags.s;
  const candidate = String(fromFlag || positional || '').trim();

  if (!candidate) {
    const err = new Error('Pass --server <id-or-name> (or set DPLY_SERVER).');
    err.exitCode = 2;

    throw err;
  }

  if (/^[0-9A-Za-z]{26}$/.test(candidate)) {
    return candidate;
  }

  const response = await client.get('/servers');
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
      ? `Multiple servers match "${candidate}". Pass the full server ID instead.`
      : `No server matched "${candidate}". Run \`dply server list\`.`,
  );
  err.exitCode = 2;

  throw err;
}
