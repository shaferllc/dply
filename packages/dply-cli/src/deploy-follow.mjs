import { c, info, ok, warn } from './print.mjs';

export const TERMINAL_DEPLOY_STATUSES = new Set(['success', 'failed', 'skipped']);

/**
 * @param {string} fullLog
 * @param {number} lastLength
 * @returns {string}
 */
export function nextLogChunk(fullLog, lastLength) {
  if (!fullLog || lastLength >= fullLog.length) {
    return '';
  }

  return fullLog.slice(lastLength);
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {string} deploymentId
 * @param {{ intervalMs?: number }} [options]
 */
export async function followSiteDeployment(client, siteId, deploymentId, options = {}) {
  const intervalMs = options.intervalMs ?? 2000;
  let lastLogLen = 0;
  let statusLinePrinted = false;

  info(c.dim(`Following deployment ${deploymentId}…`));

  while (true) {
    const data = (await client.get(
      `/sites/${encodeURIComponent(siteId)}/deployments/${encodeURIComponent(deploymentId)}`,
    ))?.data ?? {};

    const chunk = nextLogChunk(String(data.log_output ?? ''), lastLogLen);
    if (chunk) {
      process.stdout.write(chunk);
      lastLogLen = String(data.log_output).length;
    }

    if (data.status && TERMINAL_DEPLOY_STATUSES.has(data.status)) {
      if (!chunk && data.log_output) {
        process.stdout.write(String(data.log_output));
      }

      if (data.log_output) {
        process.stdout.write('\n');
      }

      if (data.status === 'success') {
        ok(`Deployment ${c.cyan(String(deploymentId))} succeeded.`);
      } else {
        warn(`Deployment ${deploymentId} finished with status ${data.status}.`);
      }

      if (data.status === 'failed') {
        const err = new Error('Deployment failed.');
        err.exitCode = 1;

        throw err;
      }

      return data;
    }

    if (data.status && !statusLinePrinted) {
      info(c.dim(`  status: ${data.status}`));
      statusLinePrinted = true;
    }

    await sleep(intervalMs);
  }
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {{ attempts?: number, delayMs?: number }} [options]
 */
export async function waitForLatestDeployment(client, siteId, options = {}) {
  const attempts = options.attempts ?? 20;
  const delayMs = options.delayMs ?? 500;

  for (let attempt = 0; attempt < attempts; attempt++) {
    const rows = (await client.get(
      `/sites/${encodeURIComponent(siteId)}/deployments?limit=1`,
    ))?.data ?? [];

    if (rows[0]?.id) {
      return rows[0];
    }

    await sleep(delayMs);
  }

  return null;
}

export const TERMINAL_EDGE_DEPLOY_STATUSES = new Set(['live', 'failed', 'superseded']);

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {string} deploymentId
 * @param {{ intervalMs?: number }} [options]
 */
export async function followEdgeDeployment(client, siteId, deploymentId, options = {}) {
  const intervalMs = options.intervalMs ?? 2000;

  info(c.dim(`Waiting for Edge deployment ${deploymentId}…`));

  while (true) {
    const data = (await client.get(
      `/edge/sites/${encodeURIComponent(siteId)}/deployments/${encodeURIComponent(deploymentId)}`,
    ))?.data ?? {};

    if (data.status && TERMINAL_EDGE_DEPLOY_STATUSES.has(data.status)) {
      if (data.status === 'live') {
        ok(`Edge deployment ${c.cyan(String(deploymentId))} is live.`);
        if (data.published_at) {
          info(c.dim(`Published: ${data.published_at}`));
        }

        return data;
      }

      if (data.status === 'failed') {
        warn(`Edge deployment ${deploymentId} failed.`);
        if (data.failure_reason) {
          info(String(data.failure_reason));
        }

        const err = new Error('Edge deployment failed.');
        err.exitCode = 1;

        throw err;
      }

      warn(`Edge deployment ${deploymentId} finished with status ${data.status}.`);

      return data;
    }

    if (data.status) {
      info(c.dim(`  ${data.status}…`));
    }

    await sleep(intervalMs);
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * @param {Record<string, unknown>} flags
 */
export function deployFollowRequested(flags) {
  return flags.follow === true || flags.wait === true || flags.w === true;
}

/**
 * @param {Record<string, unknown>} flags
 * @returns {number}
 */
export function deployFollowIntervalMs(flags) {
  const raw = flags.interval ?? flags.i ?? 2000;
  const parsed = Number.parseInt(String(raw), 10);

  return Number.isFinite(parsed) && parsed >= 500 ? parsed : 2000;
}
