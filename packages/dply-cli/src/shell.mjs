import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { readGlobalConfig } from './config.mjs';
import { completeCommandLine } from './cli.mjs';
import { runMenuSession } from './menus.mjs';
import { c, info, ok, warn } from './print.mjs';

/**
 * Interactive REPL — default when you run bare `dply` on a TTY.
 */
export async function enterInteractiveShell() {
  if (!input.isTTY || !output.isTTY) {
    return;
  }

  const { run } = await import('./cli.mjs');
  const rl = readline.createInterface({
    input,
    output,
    terminal: true,
    completer: completeCommandLine,
  });

  await printShellGuide();

  try {
    // eslint-disable-next-line no-constant-condition
    while (true) {
      let line;
      try {
        line = (await rl.question(`${c.bold('dply')}› `)).trim();
      } catch {
        break;
      }

      if (line === '') {
        await runMenuSession(rl, run, 'root');
        continue;
      }

      if (line === 'menu' || line === 'm') {
        await runMenuSession(rl, run, 'root');
        continue;
      }

      if (line === 'exit' || line === 'quit' || line === 'q') {
        info(c.dim('Bye.'));
        break;
      }

      if (line === 'guide') {
        await printShellGuide();
        continue;
      }

      if (line === 'help' || line === '?') {
        await run(['help']);
        continue;
      }

      if (line === 'ls') {
        await run(['ls']);
        continue;
      }

      if (line.startsWith('help ')) {
        const topic = line.slice(5).trim().toLowerCase();
        if (topic === 'account') {
          await run(['account', 'help']);
        } else if (topic === 'billing') {
          await run(['billing', 'help']);
        } else if (topic === 'server') {
          await run(['server', 'help']);
        } else if (topic === 'edge') {
          await run(['edge', '--help']);
        } else {
          warn(`No help topic "${topic}". Try: help account · help billing · help server · help edge`);
        }

        continue;
      }

      const tokens = tokenizeLine(line);
      if (tokens.length === 0) {
        continue;
      }

      try {
        await run(tokens);
      } catch (err) {
        const message = err?.message ?? String(err);
        warn(message);
        printCommandHint(tokens[0]);
      }
    }
  } finally {
    rl.close();
  }
}

export async function printShellGuide() {
  const cfg = await readGlobalConfig();

  info('');
  info(`${c.bold('dply')} ${c.dim('interactive CLI')}`);
  info(c.dim('  Enter / menu   browse actions without memorizing commands'));
  info(c.dim('  Tab            complete commands'));
  info(c.dim('  ls             command index'));
  info(c.dim('  help           detailed help · help account/server/edge'));
  info(c.dim('  guide          show this screen again'));
  info(c.dim('  exit           leave interactive mode'));
  info('');

  if (!cfg?.token) {
    warn('Not signed in on this machine.');
    info('');
    info(c.bold('Start here'));
    info(`  ${c.cyan('Press Enter')}     ${c.dim('open the menu → Sign in')}`);
    info(`  ${c.cyan('login')}           ${c.dim('browser device-flow sign-in')}`);
    info(`  ${c.cyan('login --base-url URL')}  ${c.dim('if auto-detect picks the wrong host')}`);
  } else {
    ok(`Signed in · ${cfg.baseUrl}`);
    info('');
    info(c.bold('Browse or type'));
    info(`  ${c.cyan('menu')}             ${c.dim('Account · Billing · Servers · Edge')}`);
    info(`  ${c.cyan('account show')}     ${c.dim('profile, org, token, abilities')}`);
    info(`  ${c.cyan('server list')}      ${c.dim('VM servers in this org')}`);
    info(`  ${c.cyan('sites')}            ${c.dim('Edge sites')}`);
    info('');
    info(c.dim('Type the start of a command and press Tab to complete.'));
  }

  info('');
}

/**
 * @param {string | undefined} command
 */
function printCommandHint(command) {
  if (!command) {
    return;
  }

  const hints = {
    login: 'login',
    menu: 'menu · or press Enter in the shell',
    server: 'server list · server system-users help',
    account: 'account show · account orgs · account sessions',
    billing: 'billing show · billing breakdown · billing invoices',
    edge: 'edge deploy · edge deployments · edge --help',
  };

  const hint = hints[command];
  if (hint) {
    info(c.dim(`Try: ${hint} · or run ls`));
  }
}

/**
 * @param {string} line
 * @returns {string[]}
 */
function tokenizeLine(line) {
  const tokens = [];
  const re = /"([^"\\]*(?:\\.[^"\\]*)*)"|'([^'\\]*(?:\\.[^'\\]*)*)'|(\S+)/g;
  let match;
  while ((match = re.exec(line)) !== null) {
    tokens.push(match[1] ?? match[2] ?? match[3]);
  }

  return tokens;
}
