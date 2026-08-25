import { requireClient } from './server-context.mjs';
import { expandArgv } from './shortcuts.mjs';
import { c, info, ok, warn } from './print.mjs';

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {(argv: string[]) => Promise<number | void>} run
 * @param {string[]} argv
 */
export async function runSmartShellCommand(rl, run, argv) {
  const expanded = expandArgv(argv);

  try {
    const handled = await maybeSmartPreflight(rl, run, expanded);

    if (!handled) {
      await run(expanded);
    }
  } catch (err) {
    await handleSmartShellError(rl, run, err, expanded);
  }
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {(argv: string[]) => Promise<number | void>} run
 * @param {string[]} argv
 * @returns {Promise<boolean>}
 */
async function maybeSmartPreflight(rl, run, argv) {
  const [cmd, sub] = argv;

  if (cmd === 'project' && (sub === 'list' || sub === 'ls' || argv.length === 1)) {
    const rows = await fetchProjectsSafe();

    if (rows.length === 0) {
      await offerEmptyProjects(rl, run);

      return true;
    }
  }

  if (cmd === 'account' && sub === 'projects') {
    const rows = await fetchProjectsSafe();

    if (rows.length === 0) {
      await offerEmptyProjects(rl, run);

      return true;
    }
  }

  if (cmd === 'site' && (sub === 'list' || sub === 'ls' || argv.length === 1)) {
    const rows = await fetchByoSitesSafe();

    if (rows.length === 0) {
      warn('No BYO sites visible to this token.');
      info(c.dim('Create a site on a VM in the web app · `dply link --byo <id>` to link this repo'));
      info(c.dim('Then deploy with bare `dply deploy`'));

      if (await confirm(rl, 'Refresh CLI permissions now?')) {
        await run(['auth', 'refresh']);
      }

      return true;
    }
  }

  if (cmd === 'deploy') {
    const { linkedSiteProduct } = await import('./site-context.mjs');
    const product = await linkedSiteProduct();

    if (!product) {
      warn('No linked site in this repo.');
      info(c.dim('Run `dply link --byo <id>` or `dply link --edge <id>` from your project root'));

      return true;
    }
  }

  if (cmd === 'server' && (sub === 'list' || argv.length === 1)) {
    const rows = await fetchServersSafe();

    if (rows.length === 0) {
      warn('No servers visible to this token.');
      info(c.dim('Add a server in the dply web app, or run `auth refresh` if you need servers.read.'));

      if (await confirm(rl, 'Refresh CLI permissions now?')) {
        await run(['auth', 'refresh']);
      }

      return true;
    }
  }

  if (cmd === 'sites') {
    const rows = await fetchEdgeSitesSafe();

    if (rows.length === 0) {
      warn('No Edge sites visible to this token.');
      info(c.dim('Create an Edge site in the web app, or run `edge --help` for deploy commands.'));

      if (await confirm(rl, 'Refresh CLI permissions now?')) {
        await run(['auth', 'refresh']);
      }

      return true;
    }
  }

  return false;
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {(argv: string[]) => Promise<number | void>} run
 */
export async function offerEmptyProjects(rl, run) {
  const { promptCreateProjectInteractive } = await import('./project-prompts.mjs');

  warn('No projects yet.');

  const created = await promptCreateProjectInteractive({ rl, run });

  if (!created) {
    info(c.dim('Tip: `projects create --name "…"` · menu: type `create` · `r` to refresh permissions'));
  }
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {(argv: string[]) => Promise<number | void>} run
 * @param {Error & { status?: number, exitCode?: number, message?: string }} err
 * @param {string[]} argv
 */
async function handleSmartShellError(rl, run, err, argv) {
  const message = err?.message ?? String(err);
  warn(message);

  if (err?.exitCode === 2 && /not logged in/i.test(message)) {
    if (await confirm(rl, 'Sign in with browser device flow?')) {
      await run(['login', '--no-shell']);
    }

    return;
  }

  if (err?.status === 403 || /forbidden|ability|scope|permission/i.test(message)) {
    info(c.dim('Try `r` or `auth refresh` to approve more scopes in the browser.'));

    if (await confirm(rl, 'Refresh permissions now?')) {
      await run(['auth', 'refresh']);
    }

    return;
  }

  if (argv[0] === 'project' && /Pass --project|No project matched/i.test(message)) {
    info(c.dim('Try `projects` to list · `projects create --name "…"` to add one'));

    return;
  }

  printCommandHint(argv[0]);
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {string} prompt
 */
async function confirm(rl, prompt) {
  try {
    const answer = (await rl.question(`${c.bold(prompt)} ${c.dim('[y/N]')} `)).trim().toLowerCase();

    return answer === 'y' || answer === 'yes';
  } catch {
    return false;
  }
}

/**
 * @param {string | undefined} command
 */
function printCommandHint(command) {
  if (!command) {
    return;
  }

  /** @type {Record<string, string>} */
  const hints = {
    login: 'login',
    menu: 'menu · or press Enter',
    auth: 'auth refresh · r',
    refresh: 'auth refresh · r',
    r: 'auth refresh',
    projects: 'projects · project create --name "…"',
    project: 'projects · project show <slug>',
    servers: 'servers · server system-users help',
    server: 'servers',
    sites: 'sites · edge deploy',
    account: 'me · account projects',
    billing: 'bill · billing breakdown',
    edge: 'edge deploy · edge --help',
  };

  const hint = hints[command];
  if (hint) {
    info(c.dim(`Try: ${hint} · shortcuts: run ls shortcuts`));
  }
}

/**
 * @returns {Promise<Array<Record<string, unknown>>>}
 */
export async function fetchProjectsSafe() {
  try {
    const client = await requireClient({});

    return (await client.get('/projects'))?.data ?? [];
  } catch {
    return [];
  }
}

export async function fetchByoSitesSafe() {
  try {
    const client = await requireClient({});

    return (await client.get('/sites'))?.data ?? [];
  } catch {
    return [];
  }
}

async function fetchServersSafe() {
  try {
    const client = await requireClient({});

    return (await client.get('/servers'))?.data ?? [];
  } catch {
    return [];
  }
}

export { fetchServersSafe };

async function fetchEdgeSitesSafe() {
  try {
    const client = await requireClient({});

    return (await client.get('/edge/sites'))?.data ?? [];
  } catch {
    return [];
  }
}

export { fetchEdgeSitesSafe };
