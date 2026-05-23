import { injectRumScript, shouldInjectRum, VITALS_BEACON_PATH } from './rum';

export interface HostMapEntry {
  storage_prefix: string;
  deployment_id: string;
  site_id: string;
  organization_id: string;
  spa_fallback: boolean;
  headers?: Record<string, string>;
  origin_url?: string;
  origin_routes?: string[];
}

export interface Env {
  ARTIFACTS: R2Bucket;
  HOST_MAP: KVNamespace;
  EDGE_ANALYTICS?: AnalyticsEngineDataset;
  ENVIRONMENT?: string;
  LOG_INGEST_BASE_URL?: string;
  LOG_INGEST_KEY?: string;
}

export const SECURITY_HEADERS: Readonly<Record<string, string>> = {
  'X-Content-Type-Options': 'nosniff',
  'X-Frame-Options': 'SAMEORIGIN',
  'Referrer-Policy': 'strict-origin-when-cross-origin',
  'X-XSS-Protection': '0',
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=()',
};

const MIME_BY_EXTENSION: Record<string, string> = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.mjs': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.txt': 'text/plain; charset=utf-8',
  '.xml': 'application/xml; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
};

export function normalizeRequestPath(pathname: string): string {
  let path = decodeURIComponent(pathname).replace(/^\/+/, '');

  if (path === '' || path.endsWith('/')) {
    path = `${path}index.html`.replace(/\/+/g, '/');
  }

  if (path.includes('..')) {
    throw new PathTraversalError();
  }

  return path;
}

export function buildObjectKey(storagePrefix: string, path: string): string {
  const prefix = storagePrefix.endsWith('/') ? storagePrefix : `${storagePrefix}/`;

  return `${prefix}${path}`.replace(/\/{2,}/g, '/');
}

export function isImmutableAsset(path: string): boolean {
  if (path === 'index.html' || path.endsWith('/index.html')) {
    return false;
  }

  return /\.[a-f0-9]{8,}\.[a-z0-9]+$/i.test(path);
}

export function cacheControlForPath(path: string): string {
  if (path === 'index.html' || path.endsWith('/index.html')) {
    return 'public, max-age=0, must-revalidate';
  }

  if (isImmutableAsset(path)) {
    return 'public, max-age=31536000, immutable';
  }

  return 'public, max-age=3600';
}

export function contentTypeForPath(path: string): string | undefined {
  const extension = path.slice(path.lastIndexOf('.')).toLowerCase();

  return MIME_BY_EXTENSION[extension];
}

export function pathMatchesOriginRoute(path: string, routes: string[]): boolean {
  const normalizedPath = `/${path}`.replace(/\/+/g, '/');

  for (const route of routes) {
    const normalizedRoute = route.startsWith('/') ? route : `/${route}`;

    if (normalizedRoute.endsWith('*')) {
      const prefix = normalizedRoute.slice(0, -1);
      if (normalizedPath.startsWith(prefix)) {
        return true;
      }

      continue;
    }

    if (normalizedPath === normalizedRoute || normalizedPath === `${normalizedRoute}/`) {
      return true;
    }
  }

  return false;
}

export class PathTraversalError extends Error {
  constructor() {
    super('Path traversal is not allowed.');
    this.name = 'PathTraversalError';
  }
}

export async function handleRequest(
  request: Request,
  env: Env,
  ctx?: ExecutionContext,
): Promise<Response> {
  const started = Date.now();
  const url = new URL(request.url);
  const hostname = url.hostname;
  const hostEntry = await env.HOST_MAP.get<HostMapEntry>(hostname, 'json');

  if (!hostEntry?.storage_prefix) {
    return notFound('Host not configured.');
  }

  if (url.pathname === VITALS_BEACON_PATH) {
    return handleVitalsBeacon(request, env, ctx, hostEntry, url);
  }

  let requestPath: string;

  try {
    requestPath = normalizeRequestPath(url.pathname);
  } catch (error) {
    if (error instanceof PathTraversalError) {
      return new Response('Bad Request', { status: 400 });
    }

    throw error;
  }

  const objectKey = buildObjectKey(hostEntry.storage_prefix, requestPath);
  let object = await env.ARTIFACTS.get(objectKey);

  if (!object && hostEntry.spa_fallback && requestPath !== 'index.html') {
    const fallbackKey = buildObjectKey(hostEntry.storage_prefix, 'index.html');
    const fallbackObject = await env.ARTIFACTS.get(fallbackKey);
    if (fallbackObject) {
      object = fallbackObject;
      requestPath = 'index.html';
    }
  }

  if (!object && hostEntry.origin_url && hostEntry.origin_routes?.length) {
    if (pathMatchesOriginRoute(requestPath, hostEntry.origin_routes)) {
      const response = await proxyToOrigin(request, hostEntry.origin_url);
      recordRequest(ctx, env, request, response, hostEntry, url, requestPath, started, 'origin');

      return response;
    }
  }

  if (!object) {
    const response = notFound('Object not found.');
    recordRequest(ctx, env, request, response, hostEntry, url, requestPath, started, 'miss');

    return response;
  }

  const headers = new Headers();
  object.writeHttpMetadata(headers);

  if (!headers.has('Content-Type')) {
    const inferred = contentTypeForPath(requestPath);

    if (inferred) {
      headers.set('Content-Type', inferred);
    }
  }

  headers.set('Cache-Control', cacheControlForPath(requestPath));
  headers.set('X-Dply-Deployment-Id', hostEntry.deployment_id);

  for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
    headers.set(name, value);
  }

  for (const [name, value] of Object.entries(hostEntry.headers ?? {})) {
    headers.set(name, value);
  }

  const hasIngest = Boolean(env.LOG_INGEST_BASE_URL && env.LOG_INGEST_KEY && hostEntry.site_id);
  const contentType = headers.get('Content-Type') ?? '';
  const isHtml = contentType.includes('text/html') || requestPath.endsWith('.html') || requestPath === 'index.html';

  let response: Response;

  if (isHtml && shouldInjectRum(requestPath, hasIngest)) {
    const html = injectRumScript(await object.text());
    headers.delete('Content-Length');
    response = new Response(html, { status: 200, headers });
  } else {
    response = new Response(object.body, { status: 200, headers });
  }

  recordRequest(ctx, env, request, response, hostEntry, url, requestPath, started, 'hit');

  return response;
}

async function handleVitalsBeacon(
  request: Request,
  env: Env,
  ctx: ExecutionContext | undefined,
  hostEntry: HostMapEntry,
  url: URL,
): Promise<Response> {
  if (request.method !== 'POST') {
    return new Response('Method Not Allowed', { status: 405, headers: SECURITY_HEADERS });
  }

  const task = () => reportVitals(env, request, hostEntry, url);

  if (ctx) {
    ctx.waitUntil(task());
  } else {
    void task();
  }

  return new Response(null, { status: 204, headers: SECURITY_HEADERS });
}

async function reportVitals(
  env: Env,
  request: Request,
  hostEntry: HostMapEntry,
  url: URL,
): Promise<void> {
  const baseUrl = (env.LOG_INGEST_BASE_URL ?? '').replace(/\/+$/, '');
  const ingestKey = env.LOG_INGEST_KEY ?? '';

  if (baseUrl === '' || ingestKey === '' || !hostEntry.site_id) {
    return;
  }

  let body: Record<string, unknown>;

  try {
    body = (await request.json()) as Record<string, unknown>;
  } catch {
    return;
  }

  const payload = JSON.stringify({
    deployment_id: hostEntry.deployment_id,
    hostname: url.hostname,
    path: typeof body.path === 'string' ? body.path : '/',
    lcp_ms: body.lcp_ms ?? null,
    cls: body.cls ?? null,
    inp_ms: body.inp_ms ?? null,
    fcp_ms: body.fcp_ms ?? null,
    ttfb_ms: body.ttfb_ms ?? null,
    country: request.headers.get('CF-IPCountry') ?? '',
    occurred_at: new Date().toISOString(),
  });

  const signature = await hmacSha256Hex(`${hostEntry.site_id}.${payload}`, ingestKey);

  try {
    await fetch(`${baseUrl}/hooks/edge/${hostEntry.site_id}/vitals`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Dply-Signature': signature,
      },
      body: payload,
    });
  } catch {
    // Fire-and-forget vitals ingest.
  }
}

function recordRequest(
  ctx: ExecutionContext | undefined,
  env: Env,
  request: Request,
  response: Response,
  hostEntry: HostMapEntry,
  url: URL,
  requestPath: string,
  started: number,
  cacheStatus: string,
): void {
  const durationMs = Date.now() - started;
  const task = () => reportRequest(env, request, response, hostEntry, url, requestPath, durationMs, cacheStatus);

  if (ctx) {
    ctx.waitUntil(task());

    return;
  }

  void task();
}

async function reportRequest(
  env: Env,
  request: Request,
  response: Response,
  hostEntry: HostMapEntry,
  url: URL,
  requestPath: string,
  durationMs: number,
  cacheStatus: string,
): Promise<void> {
  const status = response.status;
  const bytesHeader = response.headers.get('Content-Length');
  const bytes = bytesHeader ? Number.parseInt(bytesHeader, 10) : 0;

  if (env.EDGE_ANALYTICS) {
    try {
      env.EDGE_ANALYTICS.writeDataPoint({
        blobs: [
          hostEntry.site_id ?? '',
          url.hostname,
          request.method,
          url.pathname,
          cacheStatus,
        ],
        doubles: [status, durationMs, Number.isFinite(bytes) ? bytes : 0],
        indexes: [hostEntry.site_id ?? url.hostname],
      });
    } catch {
      // Non-fatal — delivery must not fail when analytics errors.
    }
  }

  const baseUrl = (env.LOG_INGEST_BASE_URL ?? '').replace(/\/+$/, '');
  const ingestKey = env.LOG_INGEST_KEY ?? '';

  if (baseUrl === '' || ingestKey === '' || !hostEntry.site_id) {
    return;
  }

  const payload = JSON.stringify({
    deployment_id: hostEntry.deployment_id,
    hostname: url.hostname,
    method: request.method,
    path: url.pathname === '' ? '/' : url.pathname,
    status,
    duration_ms: durationMs,
    bytes_egress: Number.isFinite(bytes) ? bytes : 0,
    country: request.headers.get('CF-IPCountry') ?? '',
    cache_status: cacheStatus,
    referrer: request.headers.get('Referer') ?? '',
    user_agent: request.headers.get('User-Agent') ?? '',
    occurred_at: new Date().toISOString(),
  });

  const signature = await hmacSha256Hex(`${hostEntry.site_id}.${payload}`, ingestKey);

  try {
    await fetch(`${baseUrl}/hooks/edge/${hostEntry.site_id}/log`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Dply-Signature': signature,
      },
      body: payload,
    });
  } catch {
    // Fire-and-forget ingest.
  }
}

async function hmacSha256Hex(message: string, secret: string): Promise<string> {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw',
    enc.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  const signature = await crypto.subtle.sign('HMAC', key, enc.encode(message));

  return [...new Uint8Array(signature)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function proxyToOrigin(request: Request, originUrl: string): Promise<Response> {
  const target = new URL(request.url);
  const origin = new URL(originUrl);
  target.protocol = origin.protocol;
  target.hostname = origin.hostname;
  target.port = origin.port;

  const upstream = await fetch(new Request(target.toString(), request));

  const headers = new Headers(upstream.headers);
  for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
    headers.set(name, value);
  }
  headers.set('X-Dply-Origin-Proxy', '1');

  return new Response(upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers,
  });
}

function notFound(message: string): Response {
  return new Response(message, {
    status: 404,
    headers: {
      'Content-Type': 'text/plain; charset=utf-8',
      ...SECURITY_HEADERS,
    },
  });
}
