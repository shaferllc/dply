import { readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { mkdir, readFile, writeFile, stat } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const GLOBAL_CONFIG_DIR = join(homedir(), '.dply');
const GLOBAL_CONFIG_PATH = join(GLOBAL_CONFIG_DIR, 'config.json');
const LOCAL_LINK_FILE = '.dply/site.json';
const PUBLIC_CLOUD_URL = 'https://dply.dev';

/**
 * Baked in when the CLI is packaged from a dply instance (/cli/dply-cli.tgz).
 * Local monorepo installs ship https://dplyi.test; production tarballs use APP_URL.
 *
 * @returns {string | null}
 */
function bundledInstanceBaseUrl() {
  try {
    const here = dirname(fileURLToPath(import.meta.url));
    const raw = readFileSync(join(here, 'instance-defaults.json'), 'utf8');
    const parsed = JSON.parse(raw);
    if (typeof parsed?.baseUrl === 'string' && parsed.baseUrl.trim() !== '') {
      return parsed.baseUrl.replace(/\/+$/, '');
    }
  } catch {
    // Optional file — npm registry installs may omit it.
  }

  return null;
}

/**
 * @returns {string}
 */
function fallbackBaseUrl() {
  return bundledInstanceBaseUrl() ?? PUBLIC_CLOUD_URL;
}

/**
 * Production default — sync. Prefer {@link resolveLoginBaseUrl} for login.
 *
 * @returns {string}
 */
export function defaultBaseUrl() {
  return (
    process.env.DPLY_API_BASE_URL ??
    process.env.DPLY_BASE_URL ??
    fallbackBaseUrl()
  ).replace(/\/+$/, '');
}

/**
 * Instance URL for `dply login`: flag → env → saved config → bundled → public cloud.
 *
 * @param {Record<string, unknown>} [flags]
 * @returns {Promise<string>}
 */
export async function resolveLoginBaseUrl(flags = {}) {
  const fromFlag = flags['base-url'] || flags.b;
  if (fromFlag) {
    return String(fromFlag).replace(/\/+$/, '');
  }

  const fromEnv = process.env.DPLY_API_BASE_URL || process.env.DPLY_BASE_URL;
  if (fromEnv) {
    return fromEnv.replace(/\/+$/, '');
  }

  const global = await readGlobalConfig();
  if (global?.baseUrl) {
    return global.baseUrl.replace(/\/+$/, '');
  }

  return fallbackBaseUrl();
}

/**
 * @typedef GlobalConfig
 * @property {string} token
 * @property {string} baseUrl
 * @property {string} [organizationId]
 * @property {string} [userEmail]
 * @property {string} [savedAt]
 */

/**
 * Read the global ~/.dply/config.json (token + base URL). Returns null
 * when no config has been written yet.
 *
 * @returns {Promise<GlobalConfig | null>}
 */
export async function readGlobalConfig() {
  try {
    const raw = await readFile(GLOBAL_CONFIG_PATH, 'utf8');

    return JSON.parse(raw);
  } catch (err) {
    if (err?.code === 'ENOENT') return null;
    throw err;
  }
}

/**
 * @param {GlobalConfig} cfg
 */
export async function writeGlobalConfig(cfg) {
  await mkdir(GLOBAL_CONFIG_DIR, { recursive: true, mode: 0o700 });
  const payload = { ...cfg, savedAt: new Date().toISOString() };
  await writeFile(GLOBAL_CONFIG_PATH, JSON.stringify(payload, null, 2), { mode: 0o600 });
}

export async function deleteGlobalConfig() {
  try {
    await writeFile(GLOBAL_CONFIG_PATH, JSON.stringify({}, null, 2), { mode: 0o600 });
  } catch (err) {
    if (err?.code !== 'ENOENT') throw err;
  }
}

/**
 * Walks upward from cwd looking for `.dply/site.json`. Returns the
 * parsed link plus the directory it was found in, or null when this
 * is not a linked repo.
 *
 * @returns {Promise<{ link: { siteId: string, baseUrl?: string, siteName?: string, organizationId?: string }, rootDir: string } | null>}
 */
export async function readSiteLink(startDir = process.cwd()) {
  let current = resolve(startDir);

  while (true) {
    const candidate = join(current, LOCAL_LINK_FILE);
    try {
      await stat(candidate);
      const raw = await readFile(candidate, 'utf8');
      const link = JSON.parse(raw);

      return { link, rootDir: current };
    } catch (err) {
      if (err?.code !== 'ENOENT') throw err;
    }

    const parent = dirname(current);
    if (parent === current) return null;
    current = parent;
  }
}

/**
 * @param {{ siteId: string, baseUrl?: string, siteName?: string, organizationId?: string }} link
 */
export async function writeSiteLink(link, rootDir = process.cwd()) {
  const path = join(rootDir, LOCAL_LINK_FILE);
  await mkdir(dirname(path), { recursive: true });
  await writeFile(
    path,
    JSON.stringify({ ...link, linkedAt: new Date().toISOString() }, null, 2),
  );

  return path;
}

/**
 * Resolve the (token, base URL, site ID) the current command should use.
 * Site ID resolution order: --site flag > DPLY_EDGE_SITE env > linked
 * repo. Base URL: link wins (so a linked repo is portable across
 * instances), then global config.
 *
 * @param {{ siteFlag?: string }} [opts]
 */
export async function resolveContext(opts = {}) {
  const global = await readGlobalConfig();
  if (!global?.token) {
    throw withCode(new Error('Not logged in. Run `dply login --token <token>` first.'), 'EAUTH', 2);
  }

  let siteId = opts.siteFlag || process.env.DPLY_EDGE_SITE || null;
  let baseUrl = global.baseUrl || defaultBaseUrl();

  const linkResult = await readSiteLink();
  if (linkResult) {
    siteId ??= linkResult.link.siteId;
    if (linkResult.link.baseUrl) baseUrl = linkResult.link.baseUrl;
  }

  return {
    token: global.token,
    baseUrl,
    siteId,
    link: linkResult,
    global,
  };
}

function withCode(err, code, exitCode) {
  err.code = code;
  err.exitCode = exitCode;

  return err;
}
