export interface HostMapEntry {
  storage_prefix: string;
  deployment_id: string;
  spa_fallback: boolean;
  headers?: Record<string, string>;
}

export interface Env {
  ARTIFACTS: R2Bucket;
  HOST_MAP: KVNamespace;
  ENVIRONMENT?: string;
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

export class PathTraversalError extends Error {
  constructor() {
    super('Path traversal is not allowed.');
    this.name = 'PathTraversalError';
  }
}

export async function handleRequest(request: Request, env: Env): Promise<Response> {
  const hostname = new URL(request.url).hostname;
  const hostEntry = await env.HOST_MAP.get<HostMapEntry>(hostname, 'json');

  if (!hostEntry?.storage_prefix) {
    return notFound('Host not configured.');
  }

  let requestPath: string;

  try {
    requestPath = normalizeRequestPath(new URL(request.url).pathname);
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
    object = await env.ARTIFACTS.get(fallbackKey);
    requestPath = 'index.html';
  }

  if (!object) {
    return notFound('Object not found.');
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

  return new Response(object.body, {
    status: 200,
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
