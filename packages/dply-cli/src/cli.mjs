import * as commands from './commands.mjs';
import * as billingCommands from './billing-commands.mjs';
import * as accountCommands from './account-commands.mjs';
import * as serverCommands from './server-commands.mjs';
import { c, info } from './print.mjs';

const TOP_LEVEL = {
  login: { handler: commands.login, summary: 'Browser login, then drop into interactive shell.' },
  logout: { handler: accountCommands.accountLogout, summary: 'Remove the saved token (alias for account logout).' },
  menu: { handler: commands.menu, summary: 'Browse commands with numbered menus (no memorization required).' },
  shell: { handler: commands.shell, summary: 'Interactive mode (same as bare `dply` on a TTY).' },
  whoami: { handler: commands.whoami, summary: 'Show account + session (alias for account show).' },
  account: { handler: runAccount, summary: 'Profile, orgs, CLI sessions (show, orgs, sessions, revoke).' },
  billing: { handler: runBilling, summary: 'Plan estimate, breakdown, invoices (org admin).' },
  link: { handler: commands.link, summary: 'Link the current repo to an Edge site.' },
  sites: { handler: commands.sites, summary: 'List Edge sites visible to your token.' },
  server: { handler: runServer, summary: 'BYO server commands (list, system-users, …).' },
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

const SERVER_COMMANDS = {
  list: { handler: serverCommands.serverList, summary: 'List servers in your organization.' },
  'system-users': { handler: serverCommands.serverSystemUsers, summary: 'list | sync | add | update | remove' },
};

const ACCOUNT_SUBCOMMANDS = ['show', 'orgs', 'sessions', 'revoke', 'logout', 'help'];

/**
 * @param {string[]} argv
 * @returns {Promise<number | void>}
 */
export async function run(argv) {
  if (argv.length === 0) {
    if (process.stdin.isTTY && process.stdout.isTTY) {
      const { enterInteractiveShell } = await import('./shell.mjs');

      return enterInteractiveShell();
    }

    return printTopLevelHelp();
  }

  if (argv[0] === '--help' || argv[0] === '-h' || argv[0] === 'help') {
    return printTopLevelHelp();
  }
  if (argv[0] === 'ls') {
    return printCommandList(argv[1]);
  }
  if (argv[0] === '--version' || argv[0] === '-V') {
    info('dply CLI 0.1.0');

    return 0;
  }

  const [command, ...rest] = argv;

  if (command === 'edge') {
    return runEdge(rest);
  }

  if (command === 'server') {
    return runServer(rest);
  }

  if (command === 'account') {
    return runAccount(rest);
  }

  if (command === 'billing') {
    return runBilling(rest);
  }

  const entry = TOP_LEVEL[command];
  if (!entry) {
    throw unknown(command);
  }

  const { args, flags } = parse(rest);

  return entry.handler(args, flags);
}

async function runAccount(argv) {
  const { args, flags } = parse(argv);

  return accountCommands.accountCommand(args.length ? args : ['help'], flags);
}

async function runBilling(argv) {
  const { args, flags } = parse(argv);

  return billingCommands.billingCommand(args.length ? args : ['help'], flags);
}

async function runServer(argv) {
  if (argv.length === 0 || argv[0] === '--help' || argv[0] === '-h' || argv[0] === 'help') {
    info(`${c.bold('dply server')} — BYO server commands`);
    info('');
    for (const [name, { summary }] of Object.entries(SERVER_COMMANDS)) {
      info(`  ${name.padEnd(14)} ${c.dim(summary)}`);
    }

    return 0;
  }

  const [sub, ...rest] = argv;
  const entry = SERVER_COMMANDS[sub];
  if (!entry) {
    throw unknown(`server ${sub}`);
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
  info(c.bold('Account:'));
  info(`  ${'account show'.padEnd(18)} ${c.dim('Profile + org + CLI session')}`);
  info(`  ${'account orgs'.padEnd(18)} ${c.dim('List organizations')}`);
  info(`  ${'account sessions'.padEnd(18)} ${c.dim('List CLI sessions · revoke with account revoke')}`);
  info('');
  info(c.bold('Billing (org admin):'));
  info(`  ${'billing show'.padEnd(18)} ${c.dim('Plan + monthly estimate')}`);
  info(`  ${'billing breakdown'.padEnd(18)} ${c.dim('Line-item estimate')}`);
  info(`  ${'billing invoices'.padEnd(18)} ${c.dim('Stripe invoice history')}`);
  info('');
  info(c.bold('Server (BYO):'));
  info(`  ${'server list'.padEnd(18)} ${c.dim('List VM servers')}`);
  info(`  ${'server system-users'.padEnd(18)} ${c.dim('Manage Linux accounts (see `dply server system-users help`)')}`);
  info('');
  info(c.bold('Edge:'));
  for (const [name, { summary }] of Object.entries(EDGE_COMMANDS)) {
    info(`  edge ${name.padEnd(12)} ${c.dim(summary)}`);
  }
  info('');
  info(c.dim('Site context resolves from --site, $DPLY_EDGE_SITE, or .dply/site.json (run `dply link`).'));
  info(c.dim('Interactive mode: run `dply` with no args · `dply menu` · `dply ls` · `dply help`'));

  return 0;
}

/**
 * All invokable command lines (for tab completion).
 *
 * @returns {string[]}
 */
export function allCommandLines() {
  /** @type {string[]} */
  const lines = [
    'login',
    'logout',
    'menu',
    'shell',
    'whoami',
    'ls',
    'help',
    'guide',
    'link',
    'sites',
    'account',
    'billing',
    'server',
    'edge',
  ];

  for (const sub of ACCOUNT_SUBCOMMANDS) {
    lines.push(`account ${sub}`);
  }

  lines.push('billing show', 'billing breakdown', 'billing invoices', 'billing help');

  for (const name of Object.keys(SERVER_COMMANDS)) {
    lines.push(`server ${name}`);
  }

  lines.push('server system-users help');

  for (const name of Object.keys(EDGE_COMMANDS)) {
    lines.push(`edge ${name}`);
  }

  return lines;
}

/**
 * readline tab completer.
 *
 * @param {string} line
 * @returns {[string[], string]}
 */
export function completeCommandLine(line) {
  const prefix = line.trimStart();
  const all = allCommandLines();
  const matches = all.filter((cmd) => cmd.startsWith(prefix));

  return [matches.length > 0 ? matches : all, prefix];
}

/**
 * Compact command index (like `ls`).
 *
 * @param {string | undefined} scope  account | server | edge
 */
function printCommandList(scope) {
  /** @type {string[]} */
  const lines = [];

  const normalized = scope?.toLowerCase();

  if (!normalized || normalized === 'billing') {
    lines.push('billing show', 'billing breakdown', 'billing invoices', 'billing help');
  }

  if (!normalized || normalized === 'top') {
    lines.push('login', 'logout', 'menu', 'shell', 'whoami', 'ls', 'help', 'guide', 'link', 'sites', 'account', 'server', 'edge');
  }

  if (!normalized || normalized === 'account') {
    for (const sub of ACCOUNT_SUBCOMMANDS) {
      lines.push(`account ${sub}`);
    }
  }

  if (!normalized || normalized === 'server') {
    for (const name of Object.keys(SERVER_COMMANDS)) {
      lines.push(`server ${name}`);
    }
    lines.push('server system-users help');
  }

  if (!normalized || normalized === 'edge') {
    for (const name of Object.keys(EDGE_COMMANDS)) {
      lines.push(`edge ${name}`);
    }
  }

  if (normalized && !['top', 'account', 'billing', 'server', 'edge'].includes(normalized)) {
    throw unknown(`ls ${scope}`);
  }

  info(c.bold('dply commands'));
  info('');
  for (const line of lines) {
    info(`  ${line}`);
  }
  info('');
  info(c.dim('Scoped: dply ls account · dply ls billing · dply ls server · dply ls edge'));
  info(c.dim('Details: dply help'));

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
  const err = new Error(`Unknown command: ${command}. Run \`dply ls\` or \`dply help\`.`);
  err.exitCode = 2;

  return err;
}
