import { ACCOUNT_SUBCOMMANDS, EDGE_COMMANDS, SERVER_COMMANDS, TOP_LEVEL } from './cli.mjs';
import { SINGLE_TOKEN_SHORTCUTS } from './shortcuts.mjs';

/**
 * The CLI's canonical command surface, derived from the live dispatch tables
 * rather than written out by hand.
 *
 * Exists because two lists of commands drifted with nothing to catch it: the
 * PHP catalog (app/Support/Cli/DplyCliCommandCatalog.php) advertised commands
 * for a CLI it cannot see, `dply site artisan` shipped with no row at all, and
 * `dply guide` was advertised in three places while dispatching nowhere.
 *
 * Hand-maintaining this file would just move the drift here, so anything that
 * can be read from a real table is read from it. Only two things are listed by
 * hand, both because they are switch statements rather than tables: the site
 * subcommands, and the commands run() reaches by an explicit branch.
 */

/** Reached by an explicit `if (command === …)` / argv check in run(), not TOP_LEVEL. */
const BRANCHED = ['edge', 'help', 'ls'];

/** site-commands.mjs dispatches with a switch, so its arms are listed here. */
const SITE_SUBCOMMANDS = [
  'list', 'ls', 'show', 'deploy', 'status', 'logs',
  'deployments', 'deploys', 'deployment', 'env', 'artisan', 'help',
];

function subcommandsOf(id) {
  switch (id) {
    case 'site': return SITE_SUBCOMMANDS;
    case 'server': return [...Object.keys(SERVER_COMMANDS), 'help'];
    case 'account': return [...ACCOUNT_SUBCOMMANDS];
    case 'edge': return [...Object.keys(EDGE_COMMANDS), 'help'];
    default: return [];
  }
}

/** Single-token shortcuts that expand to exactly one command are aliases of it. */
function aliasesOf(id) {
  return Object.entries(SINGLE_TOKEN_SHORTCUTS)
    .filter(([name, argv]) => argv.length === 1 && argv[0] === id && name !== id)
    .map(([name]) => name)
    .sort();
}

export const COMMANDS = [...Object.keys(TOP_LEVEL), ...BRANCHED]
  .sort()
  .map((id) => ({
    id,
    aliases: aliasesOf(id),
    subcommands: subcommandsOf(id),
  }));

/**
 * Shortcut spellings that expand to more than one token — `dply servers` is
 * `server list`, `dply p` is `project list`. They are valid input, so a catalog
 * row may legitimately name one.
 */
export const MULTI_TOKEN_SHORTCUTS = Object.entries(SINGLE_TOKEN_SHORTCUTS)
  .filter(([, argv]) => argv.length > 1)
  .map(([name]) => name)
  .sort();

/** Every accepted spelling of a top-level command, shortcuts included. */
export function knownCommandNames() {
  return [...COMMANDS.flatMap((c) => [c.id, ...c.aliases]), ...MULTI_TOKEN_SHORTCUTS];
}

/** Canonical id for a spelling, or null when it is not ours. */
export function canonicalNameFor(name) {
  const hit = COMMANDS.find((c) => c.id === name || c.aliases.includes(name));
  if (hit) {
    return hit.id;
  }

  const expansion = SINGLE_TOKEN_SHORTCUTS[name];

  return expansion ? expansion[0] : null;
}

/** Accepted subcommands for a top-level command; empty when it takes none. */
export function subcommandsFor(id) {
  return COMMANDS.find((c) => c.id === id)?.subcommands ?? [];
}
