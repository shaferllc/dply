import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { writeSiteLink } from './config.mjs';
import { fetchAllSites } from './site-index.mjs';
import { c, info, ok, warn } from './print.mjs';

/**
 * Tag shown against each row. `site-index.mjs` is the one list of sites
 * whatever kind they are — this used to hand-roll /sites + /edge/sites, which
 * meant a serverless or cloud site could not be linked to a folder at all.
 */
const KIND_TAGS = {
  vm: () => c.cyan('BYO'),
  cloud: () => c.yellow('Cloud'),
  edge: () => c.magenta('Edge'),
  serverless: () => c.green('Function'),
};

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

  const wanted = productFilter === 'byo' ? 'vm' : productFilter;
  const rows = await fetchAllSites(api, wanted ? { kind: wanted } : {}).catch(() => []);

  /** @type {Array<{ index: number, kind: string, id: string, label: string, hint: string, row: Record<string, unknown> }>} */
  const choices = rows.map((row, i) => ({
    index: i + 1,
    kind: String(row.kind ?? 'vm'),
    id: String(row.id),
    label: String(row.name ?? row.id),
    hint: [row.url, row.status].filter(Boolean).join(' · '),
    row: row.raw ?? row,
  }));

  if (choices.length === 0) {
    return false;
  }

  info('');
  info(c.bold('Link this repo to a site'));
  info(c.dim('Pick a number · or cancel with Enter'));
  info('');

  for (const choice of choices) {
    const tag = (KIND_TAGS[choice.kind] ?? KIND_TAGS.vm)();
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

    await writeLinkRecord(ctx, picked.kind, picked.row);
  } finally {
    rl.close();
  }

  return true;
}

/**
 * @param {{ baseUrl: string }} ctx
 * @param {'byo' | 'vm' | 'edge' | 'cloud' | 'serverless'} kind
 * @param {Record<string, unknown>} site
 */
export async function writeLinkRecord(ctx, kind, site) {
  if (kind === 'edge') {
    const path = await writeSiteLink({
      siteId: String(site.id),
      siteName: String(site.name ?? site.id),
      baseUrl: ctx.baseUrl,
      organizationId: site.organization_id != null ? String(site.organization_id) : undefined,
      product: 'edge',
      kind: 'edge',
    });
    ok(`Linked Edge site ${c.cyan(String(site.name ?? site.id))} (${site.id}) → ${c.dim(path)}`);
    info(c.dim('Deploy: `dply deploy` · Edge: `dply edge deploy`'));

    return path;
  }

  if (kind === 'serverless' || kind === 'cloud') {
    const path = await writeSiteLink({
      siteId: String(site.id),
      siteName: String(site.name ?? site.id),
      baseUrl: ctx.baseUrl,
      product: kind,
      kind,
    });
    ok(`Linked ${kind} site ${c.cyan(String(site.name ?? site.id))} (${site.id}) → ${c.dim(path)}`);
    info(c.dim('Deploy: `dply deploy`'));

    return path;
  }

  {
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

}
