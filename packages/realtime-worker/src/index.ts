/// <reference types="@cloudflare/workers-types" />

// dply-realtime — a Pusher/Reverb-compatible realtime relay on Cloudflare.
// Deployed once to the platform Cloudflare account; dply provisions apps into
// it by writing credentials into the APPS KV namespace (never re-deploying the
// Worker). Routes:
//   GET  /app/{appKey}        WebSocket connect (laravel-echo / pusher-js)
//   POST /apps/{appId}/events Server-side publish (pusher-php-server compatible)
//   GET  /health              Liveness probe

import { verifyPublishRequest, type AppCredentials } from './auth';
import { AppHub } from './hub';
import type { AppRecord, Env } from './types';

export { AppHub };

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const url = new URL(request.url);
    const path = url.pathname;

    if (path === '/health' || path === '/') {
      return Response.json({ ok: true, service: 'dply-realtime' });
    }

    const connectMatch = path.match(/^\/app\/([^/]+)$/);
    if (connectMatch) {
      return handleConnect(request, env, decodeURIComponent(connectMatch[1]));
    }

    const publishMatch = path.match(/^\/apps\/([^/]+)\/events$/);
    if (publishMatch && request.method === 'POST') {
      return handlePublish(request, env, url, decodeURIComponent(publishMatch[1]));
    }

    // Operator/billing: read or reset peak-concurrent stats for an app.
    const statsMatch = path.match(/^\/apps\/([^/]+)\/stats(\/reset)?$/);
    if (statsMatch) {
      return handleStats(request, env, url, decodeURIComponent(statsMatch[1]), Boolean(statsMatch[2]));
    }

    return Response.json({ error: 'not_found' }, { status: 404 });
  },
};

async function handleConnect(request: Request, env: Env, appKey: string): Promise<Response> {
  if (request.headers.get('Upgrade') !== 'websocket') {
    return Response.json({ error: 'expected_websocket' }, { status: 426 });
  }

  const app = await lookupAppByKey(env, appKey);
  if (!app || !app.enabled) {
    console.log({ src: 'realtime', event: 'connect_rejected', reason: app ? 'disabled' : 'unknown_key', appKey });
    return Response.json({ error: 'invalid_app_key' }, { status: 401 });
  }
  console.log({ src: 'realtime', event: 'connect', appId: app.id, appKey: app.key });

  const stub = hubFor(env, app.id);
  // Forward the upgrade to the app's hub, carrying resolved credentials so the
  // DO can verify channel auth without re-reading KV.
  const forwarded = new Request(request.url, request);
  forwarded.headers.set('X-App-Id', app.id);
  forwarded.headers.set('X-App-Key', app.key);
  forwarded.headers.set('X-App-Secret', app.secret);
  return stub.fetch(forwarded);
}

async function handlePublish(request: Request, env: Env, url: URL, appId: string): Promise<Response> {
  const record = await lookupAppById(env, appId);
  if (!record || !record.enabled) {
    console.log({ src: 'realtime', event: 'publish_rejected', reason: record ? 'disabled' : 'unknown_app', appId });
    return Response.json({ error: 'invalid_app' }, { status: 401 });
  }

  const rawBody = await request.text();
  const app: AppCredentials = record;
  const authorized = await verifyPublishRequest(
    app,
    request.method,
    url.pathname,
    url.searchParams,
    request.headers,
    rawBody,
  );
  if (!authorized) {
    console.log({ src: 'realtime', event: 'publish_unauthorized', appId });
    return Response.json({ error: 'unauthorized' }, { status: 401 });
  }

  const stub = hubFor(env, app.id);
  const internal = new Request('https://hub/internal/publish', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: rawBody,
  });
  return stub.fetch(internal);
}

async function handleStats(
  request: Request,
  env: Env,
  url: URL,
  appId: string,
  reset: boolean,
): Promise<Response> {
  const record = await lookupAppById(env, appId);
  if (!record) {
    return Response.json({ error: 'invalid_app' }, { status: 401 });
  }
  // Header auth only (operator/server to server) — no public stats access.
  const key = request.headers.get('X-Dply-Key');
  const secret = request.headers.get('X-Dply-Secret');
  if (key !== record.key || secret !== record.secret) {
    return Response.json({ error: 'unauthorized' }, { status: 401 });
  }

  const stub = hubFor(env, appId);
  const internalPath = reset ? '/internal/stats/reset' : '/internal/stats';
  return stub.fetch(new Request(`https://hub${internalPath}`, { method: 'POST' }));
}

function hubFor(env: Env, appId: string): DurableObjectStub {
  return env.APP_HUB.get(env.APP_HUB.idFromName(appId));
}

async function lookupAppByKey(env: Env, appKey: string): Promise<AppRecord | null> {
  return env.APPS.get<AppRecord>(`key:${appKey}`, 'json');
}

async function lookupAppById(env: Env, appId: string): Promise<AppRecord | null> {
  return env.APPS.get<AppRecord>(`id:${appId}`, 'json');
}
