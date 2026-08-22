/**
 * What `dply deploy` has to do *before* it queues anything, for a site created
 * by `dply init`.
 *
 * Two cases the older deploy path never had to think about:
 *
 *  - An upload-source site has no remote to push to, so "deploy" means "send
 *    this folder again". That is not a fallback — it is the whole mechanism.
 *  - A git-source site deploys `origin/<branch>`, which is not necessarily
 *    what is on screen. init says so on the first run; this says so on every
 *    run after, because that is the one people actually repeat.
 *
 * The two signals are not equally serious and are not treated alike:
 * uncommitted files are usually unrelated noise and get a dim note, while
 * unpushed commits mean dply will deploy work you have already finished and
 * cannot see — that gets a confirmation.
 */
import { spawnSync } from 'node:child_process';
import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';

import { c, info, warn } from './print.mjs';
import { isInteractive } from './pick.mjs';

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {Record<string, any>} flags
 * @returns {Promise<{ handled: boolean }>} handled = the deploy was already queued here
 */
export async function prepareServerlessSource(client, siteId, flags) {
  const site = (await client.get(`/serverless/sites/${encodeURIComponent(siteId)}`))?.data ?? {};

  if ((site.source_kind ?? 'git') === 'upload') {
    const { uploadCurrentFolder } = await import('./init-command.mjs');
    await uploadCurrentFolder(client, process.cwd(), flags, siteId);

    return { handled: true };
  }

  await confirmGitStateOrThrow(flags);

  return { handled: false };
}

/**
 * Blocks only on unpushed commits, and only on a terminal.
 *
 * @param {Record<string, any>} flags
 */
export async function confirmGitStateOrThrow(flags) {
  const noPrompt = Boolean(flags['no-prompt'] || flags.quiet || flags.json || flags.yes || process.env.DPLY_NO_PROMPT)
    || ! isInteractive();

  const git = (args) => {
    const result = spawnSync('git', args, { encoding: 'utf8' });

    return result.status === 0 ? String(result.stdout).trim() : null;
  };

  if (git(['rev-parse', '--is-inside-work-tree']) !== 'true') {
    return;
  }

  const branch = git(['rev-parse', '--abbrev-ref', 'HEAD']) ?? 'HEAD';
  const dirty = (git(['status', '--porcelain']) ?? '').split('\n').filter(Boolean).length;
  const upstream = git(['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);
  const ahead = upstream ? Number(git(['rev-list', '--count', '@{u}..HEAD']) ?? 0) : 0;

  if (dirty > 0) {
    info(c.dim(`  ${dirty} uncommitted file${dirty === 1 ? '' : 's'} — not included; dply deploys the remote.`));
  }

  if (ahead === 0) {
    return;
  }

  const sha = git(['rev-parse', `origin/${branch}`]) ?? '';
  warn(`${ahead} commit${ahead === 1 ? '' : 's'} not pushed. dply will deploy origin/${branch}${sha ? ` @ ${sha.slice(0, 7)}` : ''}.`);

  if (noPrompt) {
    return;
  }

  const rl = readline.createInterface({ input, output, terminal: true });
  try {
    const answer = (await rl.question(`${c.bold('Push first?')} [Y/n] `)).trim();
    if (! /^n(o)?$/i.test(answer)) {
      const push = spawnSync('git', ['push'], { stdio: 'inherit' });
      if (push.status !== 0) {
        const err = new Error('git push failed — nothing deployed.');
        err.exitCode = 1;

        throw err;
      }
    }
  } finally {
    rl.close();
  }
}
