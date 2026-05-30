import * as commands from './commands.mjs';
import * as projectCommands from './project-commands.mjs';
import * as billingCommands from './billing-commands.mjs';
import * as accountCommands from './account-commands.mjs';
import * as serverCommands from './server-commands.mjs';
import * as siteCommands from './site-commands.mjs';
import { expandArgv, shortcutCommandLines } from './shortcuts.mjs';
import { readSiteLink } from './config.mjs';
import { linkedSiteProduct } from './site-context.mjs';
import { c, info } from './print.mjs';

const TOP_LEVEL = {
  login: { handler: commands.login, summary: 'Browser login, then drop into interactive shell.' },
  refresh: { handler: commands.refreshAuth, summary: 'Re-approve CLI scopes for more permissions (device flow).' },
  auth: { handler: runAuth, summary: 'CLI authentication (refresh scopes).' },
  logout: { handler: accountCommands.accountLogout, summary: 'Remove the saved token (alias for account logout).' },
  menu: { handler: commands.menu, summary: 'Browse commands with numbered menus (no memorization required).' },
  shell: { handler: commands.shell, summary: 'Interactive mode (same as bare `dply` on a TTY).' },
  whoami: { handler: commands.whoami, summary: 'Show account + session (alias for account show).' },
  account: { handler: runAccount, summary: 'Profile, orgs, CLI sessions (show, orgs, sessions, revoke).' },
  project: { handler: runProject, summary: 'Org projects — group servers/sites, deploy, health, members.' },
  billing: { handler: runBilling, summary: 'Plan estimate, breakdown, invoices (org admin).' },
  link: { handler: commands.link, summary: 'Link this repo to a BYO or Edge site (.dply/site.json).' },
  sites: { handler: commands.sites, summary: 'List Edge sites visible to your token.' },
  site: { handler: runSite, summary: 'BYO VM site commands (list, deploy, deployments).' },
  deploy: { handler: runLinkedDeploy, summary: 'Deploy linked repo (BYO or Edge, from .dply/site.json).' },
  server: { handler: runServer, summary: 'BYO server commands (list, system-users, …).' },
};

const EDGE_COMMANDS = {
  deploy: { handler: commands.deploy, summary: 'Queue a deploy (--commit / --branch / --prod).' },
  deployments: { handler: commands.deployments, summary: 'List recent deployments.' },
  status: { handler: commands.edgeStatus, summary: 'Edge site + latest deployment (--wait to block).' },
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
  show: { handler: serverCommands.serverShow, summary: 'Show one server and its BYO sites.' },
  health: { handler: serverCommands.serverHealth, summary: 'Server status + open insight findings.' },
  run: { handler: serverCommands.serverRun, summary: 'Run a command over SSH (--server <id> <command>).' },
  'system-users': { handler: serverCommands.serverSystemUsers, summary: 'list | sync | add | update | remove' },
};

const ACCOUNT_SUBCOMMANDS = ['show', 'orgs', 'projects', 'sessions', 'refresh', 'revoke', 'logout', 'help'];

/**
 * @param {string[]} argv
 * @returns {Promise<number | void>}
 */
export async function run(argv) {
  argv = expandArgv(argv);

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

  if (command === 'deploy') {
    return runLinkedDeploy(rest);
  }

  if (command === 'site') {
    return runSite(rest);
  }

  if (command === 'edge') {
    return runEdge(rest);
  }

  if (command === 'server') {
    return runServer(rest);
  }

  if (command === 'auth') {
    return runAuth(rest);
  }

  if (command === 'refresh') {
    const { args, flags } = parse(rest);

    return commands.refreshAuth(args, flags);
  }

  if (command === 'project') {
    return runProject(rest);
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

async function runAuth(argv) {
  if (argv.length === 0 || argv[0] === '--help' || argv[0] === '-h' || argv[0] === 'help') {
    info(`${c.bold('dply auth')} — CLI authentication`);
    info('');
    info(`  ${'refresh'.padEnd(12)} ${c.dim('Re-approve scopes in the browser for more permissions')}`);
    info('');
    info(c.dim('Shortcut: `dply refresh` · `dply account refresh`'));

    return 0;
  }

  const [sub, ...rest] = argv;

  if (sub === 'refresh') {
    const { args, flags } = parse(rest);

    return commands.refreshAuth(args, flags);
  }

  throw unknown(`auth ${sub}`);
}

async function runSite(argv) {
  if (argv.length === 0) {
    const { flags } = parse([]);

    return siteCommands.siteList(flags);
  }

  if (argv[0] === '--help' || argv[0] === '-h' || argv[0] === 'help') {
    return siteCommands.siteCommand(['help'], parse(argv.slice(1)).flags);
  }

  const { args, flags } = parse(argv);

  return siteCommands.siteCommand(args.length ? args : ['list'], flags);
}

async function runLinkedDeploy(argv) {
  const { args, flags } = parse(argv);
  const product = await linkedSiteProduct();

  if (product === 'byo') {
    return siteCommands.siteCommand(['deploy', ...args], flags);
  }

  if (product === 'edge') {
    return commands.deploy(args, flags);
  }

  const link = await readSiteLink();
  if (flags.site) {
    return siteCommands.siteCommand(['deploy', ...args], flags);
  }

  throw linkedDeployError(link);
}

function linkedDeployError(link) {
  const err = new Error(
    link
      ? 'Linked site has no product type. Re-link with `dply link --byo <id>` or `dply link --edge <id>`.'
      : 'No linked site. Run `dply link` in your repo, or `dply site deploy --site <id>` / `dply edge deploy --site <id>`.',
  );
  err.exitCode = 2;

  return err;
}

async function runProject(argv) {
  const { args, flags } = parse(argv);

  return projectCommands.projectCommand(args.length ? args : ['list'], flags);
}

async function runAccount(argv) {
  const { args, flags } = parse(argv);

  return accountCommands.accountCommand(args.length ? args : ['show'], flags);
}

async function runBilling(argv) {
  const { args, flags } = parse(argv);

  return billingCommands.billingCommand(args.length ? args : ['help'], flags);
}

async function runServer(argv) {
  if (argv.length === 0) {
    const { flags } = parse([]);

    return serverCommands.serverList([], flags);
  }

  if (argv[0] === '--help' || argv[0] === '-h' || argv[0] === 'help') {
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
  info(`  ${'account projects'.padEnd(18)} ${c.dim('List projects in the current org')}`);
  info(`  ${'account sessions'.padEnd(18)} ${c.dim('List CLI sessions · revoke with account revoke')}`);
  info(`  ${'auth refresh'.padEnd(18)} ${c.dim('Re-approve scopes for more permissions')}`);
  info('');
  info(c.bold('Projects:'));
  info(`  ${'project list'.padEnd(18)} ${c.dim('Grouped servers + sites')}`);
  info(`  ${'project show'.padEnd(18)} ${c.dim('Project details and resources')}`);
  info(`  ${'project deploy'.padEnd(18)} ${c.dim('Deploy all or selected sites in a project')}`);
  info(`  ${'project health'.padEnd(18)} ${c.dim('Health summary')}`);
  info('');
  info(c.bold('Billing (org admin):'));
  info(`  ${'billing show'.padEnd(18)} ${c.dim('Plan + monthly estimate')}`);
  info(`  ${'billing breakdown'.padEnd(18)} ${c.dim('Line-item estimate')}`);
  info(`  ${'billing invoices'.padEnd(18)} ${c.dim('Stripe invoice history')}`);
  info('');
  info(c.bold('Server (BYO):'));
  info(`  ${'server list'.padEnd(18)} ${c.dim('List VM servers')}`);
  info(`  ${'server show'.padEnd(18)} ${c.dim('One server + BYO sites on it')}`);
  info(`  ${'server health'.padEnd(18)} ${c.dim('Status + insight findings')}`);
  info(`  ${'server system-users'.padEnd(18)} ${c.dim('Manage Linux accounts (see `dply server system-users help`)')}`);
  info('');
  info(c.bold('Sites (BYO):'));
  info(`  ${'site list'.padEnd(18)} ${c.dim('List VM-hosted sites')}`);
  info(`  ${'site deploy'.padEnd(18)} ${c.dim('Queue a deploy (--site or linked repo)')}`);
  info(`  ${'site logs'.padEnd(18)} ${c.dim('Latest deploy log · --follow to tail')}`);
  info(`  ${'site status'.padEnd(18)} ${c.dim('Site + latest deployment summary')}`);
  info(`  ${'deploy'.padEnd(18)} ${c.dim('Deploy linked repo (BYO or Edge via .dply/site.json)')}`);
  info(`  ${'link --byo <id>'.padEnd(18)} ${c.dim('Link repo for bare `dply deploy`')}`);
  info('');
  info(c.bold('Edge:'));
  for (const [name, { summary }] of Object.entries(EDGE_COMMANDS)) {
    info(`  edge ${name.padEnd(12)} ${c.dim(summary)}`);
  }
  info('');
  info(c.dim('Site context: BYO `--site` / $DPLY_SITE / link --byo · Edge `--site` / $DPLY_EDGE_SITE / link --edge'));
  info(c.dim('Shortcuts: projects · site · deploy · me · r · `dply ls shortcuts`'));
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
    'refresh',
    'auth',
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
    'project',
    'site',
    'deploy',
    'billing',
    'server',
    'edge',
  ];

  for (const sub of ACCOUNT_SUBCOMMANDS) {
    lines.push(`account ${sub}`);
  }

  lines.push('billing show', 'billing breakdown', 'billing invoices', 'billing help');

  lines.push('auth refresh', 'auth help', 'refresh');

  lines.push(
    'project list',
    'project show',
    'project create',
    'project deploy',
    'project deploys',
    'project health',
    'project help',
  );

  lines.push('site list', 'site show', 'site status', 'site logs', 'site deploy', 'site deployments', 'site help', 'deploy', 'link');

  for (const name of Object.keys(SERVER_COMMANDS)) {
    lines.push(`server ${name}`);
  }

  lines.push('server system-users help', 'server run');

  for (const name of Object.keys(EDGE_COMMANDS)) {
    lines.push(`edge ${name}`);
  }

  lines.push(...shortcutCommandLines());

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
    lines.push('login', 'refresh', 'auth', 'logout', 'menu', 'shell', 'whoami', 'ls', 'help', 'guide', 'link', 'deploy', 'sites', 'site', 'account', 'project', 'server', 'edge');
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

  if (!normalized || normalized === 'site' || normalized === 'byo') {
    lines.push('site list', 'site show', 'site status', 'site logs', 'site deploy', 'site deployments', 'site deployment', 'site help', 'deploy', 'link --byo');
  }

  if (!normalized || normalized === 'project' || normalized === 'projects') {
    lines.push('project list', 'project show', 'project create', 'project deploy', 'project health', 'project members list', 'project help');
  }

  if (!normalized || normalized === 'shortcuts') {
    lines.push(...shortcutCommandLines());
  }

  if (normalized && !['top', 'account', 'billing', 'server', 'edge', 'project', 'projects', 'shortcuts', 'site', 'byo'].includes(normalized)) {
    throw unknown(`ls ${scope}`);
  }

  info(c.bold('dply commands'));
  info('');
  for (const line of lines) {
    info(`  ${line}`);
  }
  info('');
  info(c.dim('Scoped: dply ls account · dply ls site · dply ls project · dply ls shortcuts · dply ls server · dply ls edge'));
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
