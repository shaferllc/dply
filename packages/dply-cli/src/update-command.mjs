/**
 * `dply update` — install the CLI build your instance is serving.
 *
 * The tarball at /cli/dply-cli.tgz is built on demand from the running app, so
 * "is there a newer CLI" is not a registry question — it is "does my binary
 * match the instance I talk to". That makes the check an equality, not a
 * greater-than: an instance that has rolled back should roll the CLI back too.
 *
 * The comparison is on the *build fingerprint* the packer stamps into
 * instance-defaults.json, not on package.json's version — that version is
 * hand-maintained and does not move when a command lands, so comparing it would
 * report "up to date" forever, which is the one way this command could quietly
 * fail. Version is the fallback for a CLI installed before builds were stamped.
 *
 * Downloads through {@link dplyFetch} rather than handing the URL straight to
 * npm, so a self-hosted instance behind a private CA (the case this whole
 * install path exists for) works the same here as it does in install.sh.
 */
import { execFile } from 'node:child_process';
import { access, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { resolveLoginBaseUrl } from './config.mjs';
import { dplyFetch, tlsFailureHint } from './http.mjs';
import { c, info, ok, printJson, warn } from './print.mjs';

const run = promisify(execFile);

/**
 * True when this CLI is running out of the source checkout — `npm link`, or
 * `npm install -g <path>`. The packed tarball ships bin/src/package.json/README
 * only (see CliPackageTarballBuilder::PACKAGE_PATHS), so a `test/` directory
 * next to us means we are the repo, not an install of it.
 *
 * @returns {Promise<string | null>} The package root, or null when installed.
 */
export async function linkedCheckoutPath() {
  const root = dirname(dirname(fileURLToPath(import.meta.url)));

  try {
    await access(join(root, 'test'));

    return root;
  } catch {
    return null;
  }
}

/** The version of the code actually executing — read, never hardcoded. */
export async function localVersion() {
  try {
    const raw = await readFile(new URL('../package.json', import.meta.url), 'utf8');

    return String(JSON.parse(raw).version ?? '0.0.0');
  } catch {
    return '0.0.0';
  }
}

/**
 * Build fingerprint stamped in at pack time. Null for a dev checkout, or for a
 * CLI installed before the packer stamped builds — both fall back to version.
 *
 * @returns {Promise<string | null>}
 */
export async function localBuild() {
  try {
    const raw = await readFile(new URL('./instance-defaults.json', import.meta.url), 'utf8');

    return JSON.parse(raw).build ?? null;
  } catch {
    return null;
  }
}

/**
 * True when the installed CLI is the build the instance is serving. Compares
 * fingerprints when both sides have one; otherwise falls back to version.
 *
 * @param {{version: string, build?: string | null}} local
 * @param {{version: string, build?: string | null}} remote
 */
export function isCurrent(local, remote) {
  if (local.build && remote.build) {
    return local.build === remote.build;
  }

  return local.version === remote.version;
}

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function updateCommand(args, flags) {
  if (args[0] === 'help' || flags.help || flags.h) {
    return printUpdateHelp();
  }

  // Updating a linked checkout would replace the symlink with a packed copy
  // and silently end dev mode — the edits stop being live and nothing says so.
  const checkout = await linkedCheckoutPath();
  if (checkout && !flags.force) {
    warn(`dply is linked to a source checkout — ${checkout}`);
    info('Your edits are already live. Update it with `git pull`, not `dply update`.');
    info(c.dim('`dply update --force` installs the packed build anyway and ends dev mode.'));

    return 0;
  }

  const baseUrl = await resolveLoginBaseUrl(flags);
  const [version, build, manifest] = await Promise.all([localVersion(), localBuild(), fetchManifest(baseUrl)]);
  const local = { version, build };
  const current = isCurrent(local, manifest);

  if (flags.json) {
    printJson({
      installed: version,
      installed_build: build,
      serving: manifest.version,
      serving_build: manifest.build ?? null,
      up_to_date: current,
      base_url: baseUrl,
    });

    return current ? 0 : 1;
  }

  if (current && !flags.force) {
    ok(`dply CLI ${version} — same build ${baseUrl} is serving.`);

    return 0;
  }

  if (flags.check) {
    warn(`dply CLI ${version} installed · a different build is available from ${baseUrl}. Run \`dply update\`.`);

    return 1;
  }

  info(`Updating from ${baseUrl}…`);
  await install(manifest.package_url, baseUrl);
  ok(`dply CLI ${manifest.version} installed. New commands are live — \`dply help\`.`);

  return 0;
}

/**
 * @param {string} baseUrl
 * @returns {Promise<{version: string, build?: string, package_url: string}>}
 */
async function fetchManifest(baseUrl) {
  let response;
  try {
    response = await dplyFetch(`${baseUrl}/cli/version.json`);
  } catch (err) {
    throw cliError(tlsFailureHint(err, baseUrl) || `Could not reach ${baseUrl}: ${err.message}`);
  }

  if (!response.ok) {
    throw cliError(`${baseUrl} did not serve a CLI manifest (HTTP ${response.status}).`);
  }

  const body = await response.json();
  if (!body?.version || !body?.package_url) {
    throw cliError(`${baseUrl}/cli/version.json is missing version/package_url.`);
  }

  return body;
}

/**
 * Download, then `npm install -g` the file. Global installs are the one step
 * that routinely fails on a permission wall, so that failure gets its own
 * message rather than a raw npm dump.
 *
 * @param {string} packageUrl
 * @param {string} baseUrl
 */
async function install(packageUrl, baseUrl) {
  const dir = await mkdtemp(join(tmpdir(), 'dply-cli-'));
  const tgz = join(dir, 'dply-cli.tgz');

  try {
    const response = await dplyFetch(packageUrl);
    if (!response.ok) {
      throw cliError(`Could not download ${packageUrl} (HTTP ${response.status}).`);
    }
    await writeFile(tgz, Buffer.from(await response.arrayBuffer()));

    await run('npm', ['install', '-g', tgz]);
  } catch (err) {
    if (err.exitCode) {
      throw err;
    }
    if (/EACCES|permission denied/i.test(String(err.stderr ?? err.message))) {
      throw cliError(
        `npm could not write the global install. Re-run with sudo, or reinstall:\n  curl -fsSL ${baseUrl}/cli/install.sh | bash`,
      );
    }
    throw cliError(`Update failed: ${String(err.stderr ?? err.message).trim()}`);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
}

function printUpdateHelp() {
  info(`${c.bold('dply update')} — install the CLI build your instance is serving`);
  info('');
  info(`  ${'update'.padEnd(14)} ${c.dim('Update if your build differs from the instance')}`);
  info(`  ${'update --check'.padEnd(14)} ${c.dim('Report only; exit 1 when a different build is available')}`);
  info('');
  info(c.dim('Flags: --check · --force (reinstall, or override the linked-checkout guard) · --json · --base-url URL'));
  info(c.dim('The instance is the source of truth — a rolled-back instance rolls the CLI back too.'));

  return 0;
}

/**
 * @param {string} message
 * @param {number} exitCode
 */
function cliError(message, exitCode = 1) {
  const err = new Error(message);
  // @ts-expect-error — exitCode is the CLI's own contract on thrown errors.
  err.exitCode = exitCode;

  return err;
}
