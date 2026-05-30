import { requireByoSiteContext, requireSiteId } from './site-context.mjs';
import { requireClient } from './server-context.mjs';
import {
  deployFollowIntervalMs,
  deployFollowRequested,
  followSiteDeployment,
  waitForLatestDeployment,
} from './deploy-follow.mjs';
import { c, info, ok, printJson, printKeyValues, printTable, warn } from './print.mjs';

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function siteCommand(args, flags) {
  const sub = args[0];

  if (!sub || sub === '--help' || sub === '-h' || sub === 'help') {
    return printSiteHelp();
  }

  switch (sub) {
    case 'list':
    case 'ls':
      return siteList(flags);
    case 'show':
      return siteShow(args.slice(1), flags);
    case 'deploy':
      return siteDeploy(args.slice(1), flags);
    case 'status':
      return siteStatus(args.slice(1), flags);
    case 'logs':
      return siteLogs(args.slice(1), flags);
    case 'deployments':
    case 'deploys':
      return siteDeployments(args.slice(1), flags);
    case 'deployment':
      return siteDeploymentShow(args.slice(1), flags);
    default:
      throw cliError(`Unknown site command: ${sub}. Run \`dply site help\`.`, 2);
  }
}

/**
 * @param {Record<string, unknown>} flags
 */
export async function siteList(flags) {
  const client = await requireClient(flags);
  const rows = (await client.get('/sites'))?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return;
  }

  if (rows.length === 0) {
    warn('No BYO sites visible to this token.');
    info(c.dim('Create a site on a VM in the dply web app · link a repo with `dply link --byo <id>`'));
    info(c.dim('Missing permissions? Try `dply auth refresh` or `dply r`.'));

    return;
  }

  printTable(
    ['ID', 'Name', 'Server', 'Status', 'Strategy'],
    rows.map((row) => [
      row.id,
      row.name,
      row.server_name ?? row.server_id ?? '—',
      row.status ?? '—',
      row.deploy_strategy ?? '—',
    ]),
  );

  info('');
  info(c.dim('Deploy: `dply site deploy --site <id>` · link repo: `dply link --byo <id>` · then bare `dply deploy`'));
}

async function siteShow(args, flags) {
  const client = await requireClient(flags);
  const siteId = await requireSiteId(flags, args[0]);
  const rows = (await client.get('/sites'))?.data ?? [];
  const data = rows.find((row) => row.id === siteId);

  if (!data) {
    throw cliError(`Site ${siteId} not found in this organization.`, 1);
  }

  if (flags.json) {
    printJson(data);

    return;
  }

  info(c.bold(data.name ?? 'Site'));
  printKeyValues([
    ['ID', data.id ?? '—'],
    ['Server', data.server_name ?? data.server_id ?? '—'],
    ['Status', data.status ?? '—'],
    ['Deploy strategy', data.deploy_strategy ?? '—'],
    ['Document root', data.document_root ?? '—'],
    ['Created', data.created_at ?? '—'],
  ]);

  return 0;
}

async function siteDeploy(args, flags) {
  const { client, siteId } = await requireByoSiteContext(flags, args[0]);
  const body = {};
  const shouldFollow = deployFollowRequested(flags);
  const intervalMs = deployFollowIntervalMs(flags);

  if (flags.sync) {
    body.sync = true;
  }

  /** @type {Record<string, string>} */
  const headers = {};
  const idempotencyKey = flags['idempotency-key'] || flags.idempotency;
  if (idempotencyKey) {
    headers['Idempotency-Key'] = String(idempotencyKey);
  }

  const response = await client.post(`/sites/${encodeURIComponent(siteId)}/deploy`, body, { headers });

  if (body.sync) {
    ok('Deployment finished.');
    if (response.message) {
      info(response.message);
    }
    if (response.last_deploy_at) {
      info(c.dim(`Last deploy: ${response.last_deploy_at}`));
    }

    if (shouldFollow) {
      const latest = await waitForLatestDeployment(client, siteId);
      if (latest?.id) {
        await followSiteDeployment(client, siteId, String(latest.id), { intervalMs });
      }
    }

    return 0;
  }

  ok('Deployment queued.');

  if (shouldFollow) {
    const latest = await waitForLatestDeployment(client, siteId);
    if (latest?.id) {
      await followSiteDeployment(client, siteId, String(latest.id), { intervalMs });
    } else {
      warn('Could not find a deployment to follow.');
    }
  }

  return 0;
}

async function siteStatus(args, flags) {
  const { client, siteId } = await requireByoSiteContext(flags, args[0]);
  const rows = (await client.get('/sites'))?.data ?? [];
  const site = rows.find((row) => row.id === siteId);

  if (!site) {
    throw cliError(`Site ${siteId} not found in this organization.`, 1);
  }

  const deployments = (await client.get(
    `/sites/${encodeURIComponent(siteId)}/deployments?limit=1`,
  ))?.data ?? [];
  const latest = deployments[0];

  if (flags.json) {
    printJson({ site, latest_deployment: latest ?? null });

    return;
  }

  info(c.bold(String(site.name ?? 'Site')));
  printKeyValues([
    ['ID', site.id ?? '—'],
    ['Server', site.server_name ?? site.server_id ?? '—'],
    ['Status', site.status ?? '—'],
    ['Deploy strategy', site.deploy_strategy ?? '—'],
  ]);

  info('');
  info(c.bold('Latest deployment'));

  if (!latest) {
    warn('No deployments yet.');
    info(c.dim('Run `dply deploy --follow` after linking this repo.'));

    return;
  }

  printKeyValues([
    ['ID', latest.id ?? '—'],
    ['Status', latest.status ?? '—'],
    ['Git SHA', latest.git_sha ? String(latest.git_sha).slice(0, 7) : '—'],
    ['Started', latest.started_at ?? '—'],
    ['Finished', latest.finished_at ?? '—'],
  ]);

  if (latest.status === 'running') {
    info(c.dim('Still running — `dply site deployment ' + latest.id + '` · or `dply deploy --wait`'));
  }

  return 0;
}

async function siteLogs(args, flags) {
  const { client, siteId } = await requireByoSiteContext(flags, args[0]);
  let deploymentId = flags.deployment || flags.d;

  if (!deploymentId) {
    const latest = (await client.get(
      `/sites/${encodeURIComponent(siteId)}/deployments?limit=1`,
    ))?.data?.[0];

    deploymentId = latest?.id;
  }

  if (!deploymentId) {
    warn('No deployments yet for this site.');

    return;
  }

  const intervalMs = deployFollowIntervalMs(flags);

  if (deployFollowRequested(flags)) {
    await followSiteDeployment(client, siteId, String(deploymentId), { intervalMs });

    return 0;
  }

  const data = (await client.get(
    `/sites/${encodeURIComponent(siteId)}/deployments/${encodeURIComponent(String(deploymentId))}`,
  ))?.data ?? {};

  info(c.bold(`Deployment ${deploymentId}`));
  printKeyValues([
    ['Status', data.status ?? '—'],
    ['Git SHA', data.git_sha ? String(data.git_sha).slice(0, 7) : '—'],
    ['Started', data.started_at ?? '—'],
    ['Finished', data.finished_at ?? '—'],
  ]);

  if (data.log_output) {
    info('');
    info(c.bold('Log'));
    info(data.log_output);
  } else {
    warn('No log output stored for this deployment.');
  }

  if (data.status === 'running') {
    info(c.dim('Still running — `dply site logs --follow`'));
  }

  return 0;
}

async function siteDeployments(args, flags) {
  const { client, siteId } = await requireByoSiteContext(flags, args[0]);
  const limit = flags.limit ?? 20;
  const rows = (await client.get(
    `/sites/${encodeURIComponent(siteId)}/deployments?limit=${encodeURIComponent(String(limit))}`,
  ))?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return;
  }

  if (rows.length === 0) {
    warn('No deployments yet for this site.');

    return;
  }

  printTable(
    ['ID', 'Status', 'Trigger', 'SHA', 'Started', 'Finished'],
    rows.map((row) => [
      row.id,
      row.status ?? '—',
      row.trigger ?? '—',
      row.git_sha ? String(row.git_sha).slice(0, 7) : '—',
      row.started_at ?? '—',
      row.finished_at ?? '—',
    ]),
  );
}

async function siteDeploymentShow(args, flags) {
  let siteRef;
  let deploymentId;

  if (args[0] === 'show') {
    siteRef = args[1];
    deploymentId = args[2];
  } else if (args.length >= 2) {
    siteRef = args[0];
    deploymentId = args[1];
  } else {
    deploymentId = args[0];
  }

  deploymentId ||= flags.deployment || flags.d;

  const { client, siteId } = await requireByoSiteContext(flags, siteRef);

  if (!deploymentId) {
    throw cliError('Usage: dply site deployment <deployment-id> [--site …] · or deployment show <site> <id>', 2);
  }

  const data = (await client.get(
    `/sites/${encodeURIComponent(siteId)}/deployments/${encodeURIComponent(String(deploymentId))}`,
  ))?.data ?? {};

  if (flags.json) {
    printJson(data);

    return;
  }

  printKeyValues([
    ['ID', data.id ?? '—'],
    ['Status', data.status ?? '—'],
    ['Trigger', data.trigger ?? '—'],
    ['Git SHA', data.git_sha ?? '—'],
    ['Exit code', data.exit_code != null ? String(data.exit_code) : '—'],
    ['Started', data.started_at ?? '—'],
    ['Finished', data.finished_at ?? '—'],
  ]);

  if (data.log_output) {
    info('');
    info(c.bold('Log'));
    info(data.log_output);
  }

  return 0;
}

function printSiteHelp() {
  info(`${c.bold('dply site')} — BYO VM site deploys`);
  info('');
  info(`  ${'list'.padEnd(16)} ${c.dim('List sites on your servers')}`);
  info(`  ${'show'.padEnd(16)} ${c.dim('<id-or-name> — site details')}`);
  info(`  ${'deploy'.padEnd(16)} ${c.dim('[site] — queue deploy (linked repo or --site)')}`);
  info(`  ${'status'.padEnd(16)} ${c.dim('[site] — site + latest deployment')}`);
  info(`  ${'logs'.padEnd(16)} ${c.dim('[site] — latest deploy log · --follow to tail')}`);
  info(`  ${'deployments'.padEnd(16)} ${c.dim('[site] — recent deploy runs')}`);
  info(`  ${'deployment'.padEnd(16)} ${c.dim('<id> [--site …] — one deploy + logs')}`);
  info('');
  info(c.dim('Flags: --sync · --follow/--wait · --interval ms · --idempotency-key · --site · --json'));
  info(c.dim('CI: `dply deploy --sync --wait` · dev: `dply deploy --follow` after `dply link --byo <id>`'));

  return 0;
}

/**
 * @param {string} message
 * @param {number} [exitCode]
 */
function cliError(message, exitCode = 1) {
  const err = new Error(message);
  err.exitCode = exitCode;

  return err;
}
