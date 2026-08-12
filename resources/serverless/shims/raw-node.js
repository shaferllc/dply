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

/**
 * The CORS policy dply binds as a default parameter when the operator takes
 * CORS over from the platform. Absent means the platform is still answering
 * preflight itself, so the shim stays out of the way entirely.
 */
function dplyCorsPolicy(args) {
  const policy = args && args.__dply_cors;
  return policy && typeof policy === 'object' ? policy : null;
}

function dplyCorsHeaders(policy, args) {
  const headers = {};
  const requestHeaders = (args && args.__ow_headers) || {};
  const origin = requestHeaders.origin || requestHeaders.Origin || '';
  const allowed = Array.isArray(policy.allow_origins) ? policy.allow_origins : ['*'];

  // An origin outside the policy gets no CORS headers at all — that IS the
  // rejection; inventing a header here would defeat the allow-list.
  let allowOrigin = null;
  if (allowed.indexOf('*') !== -1) {
    // `*` cannot be combined with credentials, so echo the caller's origin
    // when credentials are in play.
    allowOrigin = policy.allow_credentials && origin ? origin : '*';
  } else if (origin && allowed.indexOf(origin) !== -1) {
    allowOrigin = origin;
  }
  if (!allowOrigin) return headers;

  headers['Access-Control-Allow-Origin'] = allowOrigin;
  if (allowOrigin !== '*') headers['Vary'] = 'Origin';
  if (Array.isArray(policy.allow_methods) && policy.allow_methods.length) {
    headers['Access-Control-Allow-Methods'] = policy.allow_methods.join(', ');
  }
  if (Array.isArray(policy.allow_headers) && policy.allow_headers.length) {
    headers['Access-Control-Allow-Headers'] = policy.allow_headers.join(', ');
  }
  if (Array.isArray(policy.expose_headers) && policy.expose_headers.length) {
    headers['Access-Control-Expose-Headers'] = policy.expose_headers.join(', ');
  }
  if (policy.allow_credentials) headers['Access-Control-Allow-Credentials'] = 'true';
  if (policy.max_age !== undefined && policy.max_age !== null) {
    headers['Access-Control-Max-Age'] = String(policy.max_age);
  }

  return headers;
}

async function dplyMain(args) {
  args = args || {};
  if (!userMain) {
    return { statusCode: 500, body: 'dply: this action exports no main() function.' };
  }

  const policy = dplyCorsPolicy(args);
  const method = String(args.__ow_method || 'GET').toUpperCase();

  // With web-custom-options in force the platform stops answering preflight,
  // so the function has to — before the user's handler, which knows nothing
  // about CORS.
  if (policy && method === 'OPTIONS') {
    dplyReport(args, 204, 0);
    return { statusCode: 204, headers: dplyCorsHeaders(policy, args), body: '' };
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

  // The handler's own headers win — a function that sets its own CORS header
  // has made a deliberate choice the policy shouldn't overwrite.
  if (policy && result && typeof result === 'object') {
    result.headers = Object.assign({}, dplyCorsHeaders(policy, args), result.headers || {});
  }

  return result;
}

module.exports.dplyMain = dplyMain;
