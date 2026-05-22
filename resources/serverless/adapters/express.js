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

async function dplyMain(args) {
  args = args || {};
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
  return {
    statusCode: status,
    headers: (response && response.headers) || {},
    body: response && response.body !== undefined ? response.body : '',
  };
}

module.exports.dplyMain = dplyMain;
