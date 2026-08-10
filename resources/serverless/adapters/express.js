'use strict';
/**
 * dply DigitalOcean Functions <-> Express adapter.
 *
 * Injected at deploy time by App\Services\Deploy\ServerlessExpressAdapter.
 * Do not edit in the user's repo — dply overwrites this file on every deploy.
 *
 * DigitalOcean Functions (managed OpenWhisk) invokes `main($args)` with a
 * raw web-action event; an Express app expects an HTTP request. This file
 * is the OpenWhisk-side counterpart to the Laravel adapter: it translates
 * the `__ow_*` event into the AWS HTTP-API event shape `serverless-http`
 * understands, runs it through the repo's Express app, and maps the result
 * back to the `{statusCode, headers, body}` OpenWhisk expects. It also
 * fire-and-forget reports each organic invocation to dply's Logs page.
 */
const crypto = require('crypto');
const serverless = require('serverless-http');

const userExport = require('./{{DPLY_ENTRY}}');
// Accept `module.exports = app` or `export default app`.
const expressApp = userExport && userExport.default ? userExport.default : userExport;

let cachedHandler = null;

function dplyHandler() {
  if (cachedHandler === null) {
    if (typeof expressApp !== 'function') {
      throw new Error('dply Express adapter: the entry file must `module.exports` the Express app (do not call app.listen()).');
    }
    cachedHandler = serverless(expressApp);
  }
  return cachedHandler;
}

function owEventToHttpApi(args) {
  const headers = (args && args.__ow_headers) || {};
  const method = String((args && args.__ow_method) || 'GET').toUpperCase();
  const rawPath = '/' + String((args && args.__ow_path) || '').replace(/^\/+/, '');

  return {
    version: '2.0',
    routeKey: '$default',
    rawPath: rawPath,
    rawQueryString: String((args && args.__ow_query) || ''),
    headers: headers,
    requestContext: {
      http: {
        method: method,
        path: rawPath,
        sourceIp: headers['x-forwarded-for'] || headers['cf-connecting-ip'] || '',
      },
    },
    body: args && args.__ow_body !== undefined ? args.__ow_body : '',
    isBase64Encoded: !!(args && args.__ow_isBase64Encoded),
  };
}

function dplyReport(args, status, durationMs) {
  try {
    const headers = (args && args.__ow_headers) || {};
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
    /* fire-and-forget */
  }
}

/**
 * The CORS policy dply binds as a default parameter when the operator takes
 * CORS over from the platform. Absent means the platform is still answering
 * preflight itself, so the adapter stays out of the way entirely.
 */
function dplyCorsHeaders(policy, args) {
  const headers = {};
  const requestHeaders = (args && args.__ow_headers) || {};
  const origin = requestHeaders.origin || requestHeaders.Origin || '';
  const allowed = Array.isArray(policy.allow_origins) ? policy.allow_origins : ['*'];

  // An origin outside the policy gets no CORS headers at all — that IS the
  // rejection; inventing a header here would defeat the allow-list.
  let allowOrigin = null;
  if (allowed.indexOf('*') !== -1) {
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

  const policy = args.__dply_cors && typeof args.__dply_cors === 'object' ? args.__dply_cors : null;

  // With web-custom-options in force the platform stops answering preflight,
  // so the function has to — before the app, which may well 404 an OPTIONS
  // route it never registered.
  if (policy && String(args.__ow_method || 'GET').toUpperCase() === 'OPTIONS') {
    dplyReport(args, 204, 0);
    return { statusCode: 204, headers: dplyCorsHeaders(policy, args), body: '' };
  }

  const start = Date.now();

  let response;
  let status = 200;
  let thrown = null;
  try {
    response = await dplyHandler()(owEventToHttpApi(args));
    status = response && typeof response.statusCode === 'number' ? response.statusCode : 200;
  } catch (e) {
    thrown = e;
    status = 500;
    response = { statusCode: 500, headers: { 'content-type': 'text/plain' }, body: String((e && e.stack) || e) };
  }

  dplyReport(args, status, Date.now() - start);

  if (thrown) throw thrown;

  // The app's own headers win — a route that sets its own CORS header has
  // made a deliberate choice the policy shouldn't overwrite.
  const responseHeaders = (response && response.headers) || {};

  return {
    statusCode: status,
    headers: policy ? Object.assign({}, dplyCorsHeaders(policy, args), responseHeaders) : responseHeaders,
    body: response && response.body !== undefined ? response.body : '',
  };
}

module.exports.dplyMain = dplyMain;
