import * as readline from 'node:readline/promises';
import { stdin, stdout } from 'node:process';
import { c, info, ok, warn } from './print.mjs';

/**
 * @param {{ rl?: import('node:readline/promises').Interface, run?: (argv: string[]) => Promise<number | void>, skipConfirm?: boolean }} [options]
 * @returns {Promise<boolean>}
 */
export async function promptCreateProjectInteractive(options = {}) {
  const { rl: providedRl, run, skipConfirm = false } = options;

  let rl = providedRl;
  let ownsRl = false;

  if (!rl && stdin.isTTY && stdout.isTTY) {
    rl = readline.createInterface({ input: stdin, output: stdout, terminal: true });
    ownsRl = true;
  }

  if (!rl) {
    return false;
  }

  try {
    if (!skipConfirm) {
      const choice = (await rl.question(`${c.bold('Create one now?')} ${c.dim('[Y/n]')} `)).trim().toLowerCase();

      if (choice !== '' && choice !== 'y' && choice !== 'yes') {
        if (choice === 'r' || choice === 'refresh') {
          if (run) {
            await run(['auth', 'refresh']);
          } else {
            const { refreshAuth } = await import('./commands.mjs');
            await refreshAuth([], {});
          }
        }

        return false;
      }
    }

    let name;
    try {
      name = (await rl.question(`${c.bold('Project name')}› `)).trim();
    } catch {
      return false;
    }

    if (!name) {
      warn('Name is required.');

      return false;
    }

    if (run) {
      await run(['project', 'create', '--name', name]);
    } else {
      const { projectCommand } = await import('./project-commands.mjs');
      await projectCommand(['create', '--name', name], {});
    }

    ok('Run `projects` to see it.');

    return true;
  } finally {
    if (ownsRl && rl) {
      rl.close();
    }
  }
}

/**
 * @param {{ rl?: import('node:readline/promises').Interface, run?: (argv: string[]) => Promise<number | void> }} [options]
 * @returns {Promise<boolean>}
 */
export async function handleEmptyProjectList(options = {}) {
  warn('No projects yet.');

  if (stdin.isTTY && stdout.isTTY) {
    info(c.dim('Tip: type `create` in a menu · or `projects create --name "…"`'));

    return promptCreateProjectInteractive(options);
  }

  info(c.dim('Create one: `dply project create --name "My project"` · shortcut: `dply projects create --name "…"`'));
  info(c.dim('In the menu: Account → Create project · or open Projects'));
  info(c.dim('Missing permissions? Try `dply auth refresh` or `dply r`.'));

  return false;
}
