import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { writeSiteLink } from './config.mjs';
import { c, info, ok, warn } from './print.mjs';

/**
 * @param {import('./api.mjs').ApiClient} api
 * @param {{ baseUrl: string }} ctx
 * @param {'byo' | 'edge' | null} productFilter
 * @returns {Promise<boolean>}
 */
export async function interactiveLinkSite(api, ctx, productFilter = null) {
  if (!input.isTTY || !output.isTTY) {
    return false;
  }

  const [byoSites, edgeSites] = await Promise.all([
    productFilter === 'edge' ? Promise.resolve([]) : api.get('/sites').then((r) => r?.data ?? []).catch(() => []),
    productFilter === 'byo' ? Promise.resolve([]) : api.get('/edge/sites').then((r) => r?.data ?? []).catch(() => []),
  ]);

  /** @type {Array<{ index: number, product: 'byo' | 'edge', id: string, label: string, hint: string, row: Record<string, unknown> }>} */
  const choices = [];
  let index = 1;

  for (const row of byoSites) {
    choices.push({
      index,
      product: 'byo',
      id: String(row.id),
      label: String(row.name ?? row.id),
      hint: [row.server_name, row.status].filter(Boolean).join(' · '),
      row,
    });
    index++;
  }

  for (const row of edgeSites) {
    choices.push({
      index,
      product: 'edge',
      id: String(row.id),
      label: String(row.name ?? row.id),
      hint: [row.hostname, row.status].filter(Boolean).join(' · '),
      row,
    });
    index++;
  }

  if (choices.length === 0) {
    return false;
  }

  info('');
  info(c.bold('Link this repo to a site'));
  info(c.dim('Pick a number · or cancel with Enter'));
  info('');

  for (const choice of choices) {
    const tag = choice.product === 'byo' ? c.cyan('BYO') : c.magenta('Edge');
    const hint = choice.hint ? c.dim(` — ${choice.hint}`) : '';
    info(`  ${c.cyan(String(choice.index).padStart(2, ' '))}  ${tag}  ${choice.label}${hint}`);
  }

  info('');

  const rl = readline.createInterface({ input, output, terminal: true });

  try {
    const answer = (await rl.question(`${c.bold('Choose')}› `)).trim();

    if (answer === '') {
      info(c.dim('Cancelled.'));

      return true;
    }

    const picked = choices.find((choice) => String(choice.index) === answer);
    if (!picked) {
      warn(`Enter a number 1–${choices.length}, or press Enter to cancel.`);

      return true;
    }

    await writeLinkRecord(ctx, picked.product, picked.row);
  } finally {
    rl.close();
  }

  return true;
}

/**
 * @param {{ baseUrl: string }} ctx
 * @param {'byo' | 'edge'} product
 * @param {Record<string, unknown>} site
 */
export async function writeLinkRecord(ctx, product, site) {
  if (product === 'byo') {
    const path = await writeSiteLink({
      siteId: String(site.id),
      siteName: String(site.name ?? site.id),
      baseUrl: ctx.baseUrl,
      product: 'byo',
      serverId: site.server_id != null ? String(site.server_id) : undefined,
      serverName: site.server_name != null ? String(site.server_name) : undefined,
    });
    ok(`Linked BYO site ${c.cyan(String(site.name ?? site.id))} (${site.id}) → ${c.dim(path)}`);
    info(c.dim('Deploy: `dply deploy --follow` · CI: `dply deploy --sync --wait`'));

    return path;
  }

  const path = await writeSiteLink({
    siteId: String(site.id),
    siteName: String(site.name ?? site.id),
    baseUrl: ctx.baseUrl,
    organizationId: site.organization_id != null ? String(site.organization_id) : undefined,
    product: 'edge',
  });
  ok(`Linked Edge site ${c.cyan(String(site.name ?? site.id))} (${site.id}) → ${c.dim(path)}`);
  info(c.dim('Deploy: `dply deploy` · Edge: `dply edge deploy`'));

  return path;
}
