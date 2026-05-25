import * as commands from './commands.mjs';
import { c, info } from './print.mjs';

const TOP_LEVEL = {
  login: { handler: commands.login, summary: 'Save an API token + base URL after verifying it.' },
  logout: { handler: commands.logout, summary: 'Remove the saved token.' },
  whoami: { handler: commands.whoami, summary: 'Show the active token + linked repo.' },
  link: { handler: commands.link, summary: 'Link the current repo to an Edge site.' },
  sites: { handler: commands.sites, summary: 'List Edge sites visible to your token.' },
};

const EDGE_COMMANDS = {
  deploy: { handler: commands.deploy, summary: 'Queue a deploy (--commit / --branch / --prod).' },
  deployments: { handler: commands.deployments, summary: 'List recent deployments.' },
  lint: { handler: commands.lint, summary: 'Validate dply.yaml in cwd (--path).' },
  open: { handler: commands.open, summary: 'Open live URL (--dashboard for workspace).' },
  rollback: { handler: commands.rollback, summary: 'Re-point production at a prior deployment.' },
  promote: { handler: commands.promote, summary: 'Promote a preview to production.' },
  previews: { handler: commands.previews, summary: 'list | create --commit X | rm <id>' },
  domains: { handler: commands.domains, summary: 'list | add <host> | verify <host> | rm <host>' },
  aliases: { handler: commands.aliases, summary: 'List per-deploy stable URLs.' },
  purge: { handler: commands.purge, summary: 'Purge edge cache by tag (--tag X).' },
  usage: { handler: commands.usage, summary: 'Show traffic / billing usage.' },
  logs: { handler: commands.logs, summary: 'Tail request logs (--interval ms, --window s, --once).' },
  env: { handler: commands.env, summary: 'list | set KEY=val | rm KEY | push --file .env | pull' },
};

/**
 * @param {string[]} argv
 * @returns {Promise<number | void>}
 */
export async function run(argv) {
  if (argv.length === 0 || argv[0] === '--help' || argv[0] === '-h' || argv[0] === 'help') {
    return printTopLevelHelp();
  }
  if (argv[0] === '--version' || argv[0] === '-V') {
    info('dply CLI 0.1.0');

    return 0;
  }

  const [command, ...rest] = argv;

  if (command === 'edge') {
    return runEdge(rest);
  }

  const entry = TOP_LEVEL[command];
  if (!entry) {
    throw unknown(command);
  }

  const { args, flags } = parse(rest);

  return entry.handler(args, flags);
}

async function runEdge(argv) {
  if (argv.length === 0 || argv[0] === '--help' || argv[0] === '-h') {
    return printEdgeHelp();
  }
  const [sub, ...rest] = argv;
  const entry = EDGE_COMMANDS[sub];
  if (!entry) {
    throw unknown(`edge ${sub}`);
  }
  const { args, flags } = parse(rest);

  return entry.handler(args, flags);
}

/**
 * Tiny argv parser. Supports:
 *   --flag value          → flags.flag = value
 *   --flag=value          → flags.flag = value
 *   --flag                → flags.flag = true
 *   -x value              → flags.x = value
 *   non-dash token        → args.push(token)
 *
 * No deps, no surprises. If you need richer parsing later, swap in
 * mri or minimist — the shape (args[], flags{}) stays the same.
 *
 * @param {string[]} tokens
 */
export function parse(tokens) {
  const args = [];
  const flags = {};

  for (let i = 0; i < tokens.length; i++) {
    const token = tokens[i];
    if (token.startsWith('--')) {
      const eq = token.indexOf('=');
      if (eq !== -1) {
        flags[token.slice(2, eq)] = token.slice(eq + 1);

        continue;
      }
      const name = token.slice(2);
      const next = tokens[i + 1];
      if (next !== undefined && !next.startsWith('-')) {
        flags[name] = next;
        i++;
      } else {
        flags[name] = true;
      }

      continue;
    }
    if (token.startsWith('-') && token.length > 1) {
      const name = token.slice(1);
      const next = tokens[i + 1];
      if (next !== undefined && !next.startsWith('-')) {
        flags[name] = next;
        i++;
      } else {
        flags[name] = true;
      }

      continue;
    }
    args.push(token);
  }

  return { args, flags };
}

function printTopLevelHelp() {
  info(`${c.bold('dply')} — command-line interface for the dply Edge platform`);
  info('');
  info(c.bold('Usage:'));
  info('  dply <command> [args] [flags]');
  info('  dply edge <subcommand> [args] [flags]');
  info('');
  info(c.bold('Top-level:'));
  for (const [name, { summary }] of Object.entries(TOP_LEVEL)) {
    info(`  ${name.padEnd(10)} ${c.dim(summary)}`);
  }
  info('');
  info(c.bold('Edge:'));
  for (const [name, { summary }] of Object.entries(EDGE_COMMANDS)) {
    info(`  edge ${name.padEnd(12)} ${c.dim(summary)}`);
  }
  info('');
  info(c.dim('Site context resolves from --site, $DPLY_EDGE_SITE, or .dply/site.json (run `dply link`).'));

  return 0;
}

function printEdgeHelp() {
  info(`${c.bold('dply edge')} — Edge platform subcommands`);
  info('');
  for (const [name, { summary }] of Object.entries(EDGE_COMMANDS)) {
    info(`  ${name.padEnd(12)} ${c.dim(summary)}`);
  }

  return 0;
}

function unknown(command) {
  const err = new Error(`Unknown command: ${command}. Run \`dply help\` for the full list.`);
  err.exitCode = 2;

  return err;
}
