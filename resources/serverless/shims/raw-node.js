'use strict';
/**
 * dply logging shim for a raw OpenWhisk Node.js action.
 *
 * Injected at deploy time by App\Services\Deploy\ServerlessLoggingShimInjector.
 * Do not edit in the user's repo — dply overwrites this file on every deploy.
 *
 * The DigitalOcean Functions activations list API is structurally empty, so
 * an un-wrapped raw action is invisible to dply. This shim wraps the repo's
 * own action and fire-and-forget POSTs each organic invocation to dply's
 * ingest endpoint, exactly as the Laravel adapter does for framework apps.
 */
const crypto = require('crypto');

const userModule = require('./{{DPLY_ENTRY}}');
const userMain = typeof userModule === 'function'
  ? userModule
  : (userModule && typeof userModule.main === 'function' ? userModule.main : null);

function dplyReport(args, status, durationMs) {
  try {
    const headers = (args && args.__ow_headers) || {};
    // dply-initiated invocations (ticks / the Logs test button) are already
    // captured inline by the caller — never double-report them.
    if (headers['x-dply-run'] || headers['x-dply-source']) return;

    const endpoint = process.env.DPLY_LOG_INGEST_URL || '';
    const secret = process.env.DPLY_LOG_INGEST_SECRET || '';
    if (!endpoint || !secret) return;

    const parsed = new URL(endpoint);
    if (!parsed.hostname || parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1') return;

    const payload = JSON.stringify({
      method: String((args && args.__ow_method) || 'GET').toUpperCase(),
      path: '/' + String((args && args.__ow_path) || '').replace(/^\/+/, ''),
      status: status,
      duration_ms: durationMs,
      logs: [],
      context: {},
    });
    const signature = crypto.createHmac('sha256', secret).update(payload).digest('hex');
    const transport = parsed.protocol === 'http:' ? require('http') : require('https');

    const req = transport.request(endpoint, {
      method: 'POST',
      timeout: 800,
      headers: { 'Content-Type': 'application/json', 'X-Dply-Signature': signature },
    });
    req.on('error', () => {});
    req.on('timeout', () => req.destroy());
    req.write(payload);
    req.end();
  } catch (e) {
    /* fire-and-forget — never let reporting affect the response */
  }
}

async function dplyMain(args) {
  args = args || {};
  if (!userMain) {
    return { statusCode: 500, body: 'dply: this action exports no main() function.' };
  }

  const start = Date.now();
  let result;
  let status = 200;
  let thrown = null;
  try {
    result = await userMain(args);
    if (result && typeof result.statusCode === 'number') status = result.statusCode;
  } catch (e) {
    thrown = e;
    status = 500;
    result = { statusCode: 500, body: String((e && e.stack) || e) };
  }

  dplyReport(args, status, Date.now() - start);

  if (thrown) throw thrown;
  return result;
}

module.exports.dplyMain = dplyMain;
