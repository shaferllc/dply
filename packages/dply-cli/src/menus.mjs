import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { ApiClient } from './api.mjs';
import { readGlobalConfig, resolveContext } from './config.mjs';
import { requireClient } from './server-context.mjs';
import { c, info, warn } from './print.mjs';

/**
 * @typedef {object} MenuItem
 * @property {string} label
 * @property {string} [hint]
 * @property {string} [value]
 * @property {() => Promise<'back' | 'exit' | void>} [action]
 * @property {string} [submenu]
 * @property {string[]} [argv]
 */

/**
 * Standalone menu session (`dply menu`).
 */
export async function enterInteractiveMenu() {
  if (!input.isTTY || !output.isTTY) {
    throw new Error('Interactive menu requires a TTY. Run `dply menu` in a terminal.');
  }

  const rl = readline.createInterface({ input, output, terminal: true });
  const { run } = await import('./cli.mjs');

  try {
    await runMenuSession(rl, run, 'root');
  } finally {
    rl.close();
  }
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {(argv: string[]) => Promise<number | void>} run
 * @param {string} [startMenu]
 */
export async function runMenuSession(rl, run, startMenu = 'root') {
  /** @type {string[]} */
  const stack = [startMenu];

  while (stack.length > 0) {
    const menuId = stack[stack.length - 1];
    const menu = await buildMenu(menuId, { rl, run });

    if (!menu) {
      stack.pop();
      continue;
    }

    const selected = await promptMenu(rl, menu);

    if (!selected) {
      if (stack.length === 1) {
        return;
      }

      stack.pop();
      continue;
    }

    if (selected.submenu) {
      stack.push(selected.submenu);
      continue;
    }

    if (selected.argv) {
      try {
        await run(selected.argv);
      } catch (err) {
        warn(err?.message ?? String(err));
      }

      await pauseForMenu(rl);
      continue;
    }

    if (selected.action) {
      const result = await selected.action();

      if (result === 'exit') {
        return;
      }

      if (result === 'back') {
        stack.pop();
        continue;
      }

      await pauseForMenu(rl);
    }
  }
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {{ title: string, subtitle?: string, items: MenuItem[], showBack?: boolean }} options
 * @returns {Promise<MenuItem | null>}
 */
export async function promptMenu(rl, options) {
  const { title, subtitle, items, showBack = true } = options;

  // eslint-disable-next-line no-constant-condition
  while (true) {
    info('');
    info(c.bold(title));
    if (subtitle) {
      info(c.dim(subtitle));
    }
    info('');

    items.forEach((item, index) => {
      const num = c.cyan(String(index + 1).padStart(2, ' '));
      const hint = item.hint ? c.dim(` — ${item.hint}`) : '';
      info(`  ${num}  ${item.label}${hint}`);
    });

    if (showBack) {
      info(`  ${c.dim(' b')}  ${c.dim('← Back')}`);
    }

    info(`  ${c.dim(' q')}  ${c.dim('Quit menu')}`);
    info('');

    let answer;
    try {
      answer = (await rl.question(`${c.bold('Choose')}› `)).trim().toLowerCase();
    } catch {
      return null;
    }

    if (answer === '' || answer === 'b' || answer === 'back') {
      return null;
    }

    if (answer === 'q' || answer === 'quit' || answer === 'exit') {
      return { label: 'quit', action: async () => 'exit' };
    }

    const index = Number.parseInt(answer, 10);
    if (Number.isFinite(index) && index >= 1 && index <= items.length) {
      return items[index - 1];
    }

    warn(`Enter a number 1–${items.length}, b to go back, or q to quit.`);
  }
}

/**
 * @param {import('node:readline/promises').Interface} rl
 */
async function pauseForMenu(rl) {
  info('');
  info(c.dim('Press Enter to continue…'));

  try {
    await rl.question('');
  } catch {
    // ignore
  }
}

/**
 * @param {string} menuId
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
async function buildMenu(menuId, ctx) {
  const cfg = await readGlobalConfig();
  const loggedIn = Boolean(cfg?.token);

  switch (menuId) {
    case 'root':
      return buildRootMenu(loggedIn, cfg, ctx);
    case 'account':
      return buildAccountMenu(ctx);
    case 'billing':
      return buildBillingMenu(ctx);
    case 'servers':
      return buildServersMenu(ctx);
    case 'edge':
      return buildEdgeMenu(ctx);
    default:
      return null;
  }
}

/**
 * @param {boolean} loggedIn
 * @param {Awaited<ReturnType<typeof readGlobalConfig>>} cfg
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
function buildRootMenu(loggedIn, cfg, ctx) {
  /** @type {MenuItem[]} */
  const items = [];

  if (loggedIn) {
    items.push(
      { label: 'Account', hint: 'profile, orgs, sessions', submenu: 'account' },
      { label: 'Billing', hint: 'plan, breakdown, invoices', submenu: 'billing' },
      { label: 'Servers', hint: 'VM list, Linux system users', submenu: 'servers' },
      { label: 'Edge', hint: 'sites, deploy, logs', submenu: 'edge' },
      { label: 'Command index', hint: 'full list of commands', argv: ['ls'] },
      { label: 'Help', hint: 'detailed command reference', argv: ['help'] },
      {
        label: 'Quick tips',
        hint: 'show the welcome screen again',
        action: async () => {
          const { printShellGuide } = await import('./shell.mjs');
          await printShellGuide();
        },
      },
    );
  } else {
    items.push(
      {
        label: 'Sign in',
        hint: 'browser device-flow login',
        action: async () => {
          await ctx.run(['login', '--no-shell']);
        },
      },
      { label: 'Help', hint: 'what you can do before signing in', argv: ['help'] },
      {
        label: 'Quick tips',
        hint: 'getting started',
        action: async () => {
          const { printShellGuide } = await import('./shell.mjs');
          await printShellGuide();
        },
      },
    );
  }

  const subtitle = loggedIn
    ? `Signed in · ${cfg?.baseUrl ?? '—'}`
    : 'Not signed in — start with Sign in';

  return {
    title: 'dply menu',
    subtitle,
    items,
    showBack: false,
  };
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
function buildAccountMenu(ctx) {
  return {
    title: 'Account',
    subtitle: 'Profile, organizations, and CLI sessions',
    items: [
      { label: 'Show profile', hint: 'user, org, token, abilities', argv: ['account', 'show'] },
      { label: 'Organizations', hint: 'orgs you belong to', argv: ['account', 'orgs'] },
      { label: 'CLI sessions', hint: 'active tokens in this org', argv: ['account', 'sessions'] },
      {
        label: 'Revoke a session',
        hint: 'pick from list',
        action: async () => revokeSessionMenu(ctx),
      },
      { label: 'Sign out', hint: 'remove token from this machine', argv: ['logout'] },
      { label: 'Account help', argv: ['account', 'help'] },
    ],
  };
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
function buildBillingMenu(ctx) {
  return {
    title: 'Billing',
    subtitle: 'Org admin · requires billing.read scope',
    items: [
      { label: 'Plan summary', hint: 'current plan + estimate', argv: ['billing', 'show'] },
      { label: 'Cost breakdown', hint: 'line items', argv: ['billing', 'breakdown'] },
      { label: 'Invoices', hint: 'Stripe history', argv: ['billing', 'invoices'] },
      { label: 'Billing help', argv: ['billing', 'help'] },
    ],
  };
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
function buildServersMenu(ctx) {
  return {
    title: 'Servers',
    subtitle: 'BYO VM servers in your organization',
    items: [
      { label: 'List servers', argv: ['server', 'list'] },
      {
        label: 'System users — list',
        hint: 'pick a server first',
        action: async () => runWithServer(ctx, ['server', 'system-users', 'list']),
      },
      {
        label: 'System users — sync from server',
        hint: 'refresh dply snapshot',
        action: async () => runWithServer(ctx, ['server', 'system-users', 'sync']),
      },
      { label: 'System users help', argv: ['server', 'system-users', 'help'] },
      { label: 'Server help', argv: ['server', 'help'] },
    ],
  };
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
function buildEdgeMenu(ctx) {
  return {
    title: 'Edge',
    subtitle: 'Sites, deploys, and delivery',
    items: [
      { label: 'List all sites', argv: ['sites'] },
      {
        label: 'Link this repo to a site',
        hint: 'pick from list',
        action: async () => linkSiteMenu(ctx),
      },
      {
        label: 'Deploy',
        hint: 'pick site · uses linked repo if set',
        action: async () => runWithEdgeSite(ctx, ['edge', 'deploy']),
      },
      {
        label: 'Recent deployments',
        action: async () => runWithEdgeSite(ctx, ['edge', 'deployments']),
      },
      {
        label: 'Tail request logs',
        action: async () => runWithEdgeSite(ctx, ['edge', 'logs', '--once']),
      },
      {
        label: 'Open live URL',
        action: async () => runWithEdgeSite(ctx, ['edge', 'open']),
      },
      { label: 'Edge command help', argv: ['edge', '--help'] },
    ],
  };
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 * @param {string[]} commandPrefix
 */
async function runWithServer(ctx, commandPrefix) {
  const serverId = await pickServer(ctx.rl);

  if (!serverId) {
    return;
  }

  await ctx.run([...commandPrefix, '--server', serverId]);
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 * @param {string[]} commandPrefix
 */
async function runWithEdgeSite(ctx, commandPrefix) {
  const siteId = await pickEdgeSite(ctx.rl);

  if (!siteId) {
    const linked = await tryLinkedSite();
    if (!linked) {
      warn('No site selected. Link a repo or pick a site from the list.');

      return;
    }

    await ctx.run([...commandPrefix, '--site', linked]);
    return;
  }

  await ctx.run([...commandPrefix, '--site', siteId]);
}

/**
 * @returns {Promise<string | null>}
 */
async function tryLinkedSite() {
  try {
    const ctx = await resolveContext();

    return ctx.siteId ?? null;
  } catch {
    return null;
  }
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @returns {Promise<string | null>}
 */
async function pickServer(rl) {
  /** @type {Array<{ id: string, name: string, provider?: string, ip_address?: string }>} */
  let rows;

  try {
    const client = await requireClient({});
    const response = await client.get('/servers');
    rows = response?.data ?? [];
  } catch (err) {
    warn(err?.message ?? String(err));

    return null;
  }

  if (rows.length === 0) {
    warn('No servers visible to this token.');

    return null;
  }

  const choice = await promptMenu(rl, {
    title: 'Select a server',
    items: rows.map((row) => ({
      label: row.name,
      hint: [row.provider, row.ip_address ?? row.id].filter(Boolean).join(' · '),
      value: row.id,
    })),
  });

  return choice?.value ?? null;
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @returns {Promise<string | null>}
 */
async function pickEdgeSite(rl) {
  /** @type {Array<{ id: string, name: string, hostname?: string, status?: string }>} */
  let rows;

  try {
    const ctx = await resolveContext();
    const api = new ApiClient(ctx);
    const response = await api.get('/edge/sites');
    rows = response?.data ?? [];
  } catch (err) {
    warn(err?.message ?? String(err));

    return null;
  }

  if (rows.length === 0) {
    warn('No Edge sites visible to this token.');

    return null;
  }

  const choice = await promptMenu(rl, {
    title: 'Select an Edge site',
    items: rows.map((row) => ({
      label: row.name,
      hint: [row.hostname, row.status].filter(Boolean).join(' · '),
      value: row.id,
    })),
  });

  return choice?.value ?? null;
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
async function linkSiteMenu(ctx) {
  const siteId = await pickEdgeSite(ctx.rl);

  if (!siteId) {
    return;
  }

  await ctx.run(['link', siteId]);
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
async function revokeSessionMenu(ctx) {
  /** @type {Array<{ id: string, user?: { email?: string, name?: string }, masked?: string, prefix?: string, is_current?: boolean }>} */
  let rows;

  try {
    const client = await requireClient({});
    rows = (await client.get('/account/sessions'))?.data ?? [];
  } catch (err) {
    warn(err?.message ?? String(err));

    return;
  }

  if (rows.length === 0) {
    warn('No CLI sessions to revoke.');

    return;
  }

  const choice = await promptMenu(ctx.rl, {
    title: 'Revoke a CLI session',
    subtitle: 'Revoking the current session signs you out on this machine',
    items: rows.map((row) => ({
      label: row.user?.email ?? row.user?.name ?? row.id,
      hint: [row.masked ?? row.prefix, row.is_current ? 'current' : ''].filter(Boolean).join(' · '),
      value: row.id,
    })),
  });

  if (!choice?.value) {
    return;
  }

  await ctx.run(['account', 'revoke', choice.value]);
}
