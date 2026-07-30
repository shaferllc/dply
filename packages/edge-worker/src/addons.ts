/**
 * Edge product add-ons enforced in the platform Worker (managed delivery).
 * Config arrives flattened on HostMapEntry from EdgeHostMapAddons.
 */

export interface TurnstileConfig {
  enabled: boolean;
  site_key: string;
  secret_key: string;
  mode: 'forms' | 'all';
  paths?: string[];
}

export interface RateLimitRule {
  path: string;
  limit: number;
  window_seconds: number;
  action: 'block' | 'challenge';
}

export interface RateLimitConfig {
  enabled: boolean;
  rules: RateLimitRule[];
}

export interface FormEndpoint {
  path: string;
  to_email: string;
  honeypot: string;
  require_turnstile: boolean;
}

export interface FormsConfig {
  enabled: boolean;
  endpoints: FormEndpoint[];
  ingest_url?: string | null;
  ingest_key?: string;
}

export interface WaitingRoomConfig {
  enabled: boolean;
  total_active_users: number;
  new_users_per_minute: number;
  session_duration_minutes: number;
  paths: string[];
}

export interface SnippetItem {
  name: string;
  phase: 'head' | 'body';
  html: string;
  path: string;
}

export interface SnippetsConfig {
  enabled: boolean;
  items: SnippetItem[];
}

export interface TagTool {
  name: string;
  src: string;
  async?: boolean;
}

export interface TagsConfig {
  enabled: boolean;
  consent_required?: boolean;
  tools: TagTool[];
}

export interface EdgeAddonsHostEntry {
  site_id?: string;
  turnstile?: TurnstileConfig;
  rate_limit?: RateLimitConfig;
  forms?: FormsConfig;
  waiting_room?: WaitingRoomConfig;
  snippets?: SnippetsConfig;
  tags?: TagsConfig;
}

function pathMatches(pattern: string, pathname: string): boolean {
  if (pattern === '/*' || pattern === '*') return true;
  if (pattern.endsWith('/*')) {
    const prefix = pattern.slice(0, -1); // keep trailing /
    return pathname === pattern.slice(0, -2) || pathname.startsWith(prefix);
  }
  return pathname === pattern;
}

function clientIp(request: Request): string {
  return (
    request.headers.get('cf-connecting-ip') ||
    request.headers.get('x-forwarded-for')?.split(',')[0]?.trim() ||
    '0.0.0.0'
  );
}

async function verifyTurnstile(token: string, secret: string, ip: string): Promise<boolean> {
  if (!token || !secret) return false;
  try {
    const body = new URLSearchParams();
    body.set('secret', secret);
    body.set('response', token);
    body.set('remoteip', ip);
    const res = await fetch('https://challenges.cloudflare.com/turnstile/v0/siteverify', {
      method: 'POST',
      body,
    });
    if (!res.ok) return false;
    const data = (await res.json()) as { success?: boolean };
    return data.success === true;
  } catch {
    return false;
  }
}

export function injectTurnstileWidget(html: string, siteKey: string): string {
  if (!html || !siteKey) return html;
  const widget = `<div class="cf-turnstile" data-sitekey="${escapeAttr(siteKey)}"></div>`;
  const script = `<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>`;
  let out = html;
  if (!/challenges\.cloudflare\.com\/turnstile/i.test(out)) {
    out = out.includes('</body>') ? out.replace(/<\/body>/i, `${script}</body>`) : out + script;
  }
  if (!/class=["']cf-turnstile["']/.test(out) && out.includes('</form>')) {
    out = out.replace(/<\/form>/i, `${widget}</form>`);
  }
  return out;
}

function escapeAttr(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

export async function enforceRateLimit(
  request: Request,
  pathname: string,
  config: RateLimitConfig | undefined,
  turnstile: TurnstileConfig | undefined,
): Promise<Response | null> {
  if (!config?.enabled || !config.rules?.length) return null;
  const ip = clientIp(request);
  for (const rule of config.rules) {
    if (!pathMatches(rule.path, pathname)) continue;
    const key = `rl:${ip}:${rule.path}:${rule.window_seconds}`;
    const allowed = await bumpCacheCounter(key, rule.limit, rule.window_seconds);
    if (allowed) continue;
    if (rule.action === 'challenge' && turnstile?.enabled && turnstile.secret_key) {
      const token =
        request.headers.get('cf-turnstile-response') ||
        new URL(request.url).searchParams.get('cf-turnstile-response') ||
        '';
      if (token && (await verifyTurnstile(token, turnstile.secret_key, ip))) {
        continue;
      }
      return challengeHtml(turnstile.site_key);
    }
    return new Response('Too Many Requests', {
      status: 429,
      headers: { 'Retry-After': String(rule.window_seconds), 'Content-Type': 'text/plain; charset=utf-8' },
    });
  }
  return null;
}

async function bumpCacheCounter(key: string, limit: number, windowSeconds: number): Promise<boolean> {
  try {
    const cache = caches.default;
    const url = new URL(`https://edge-rate-limit.dply.internal/${encodeURIComponent(key)}`);
    const hit = await cache.match(url);
    let count = 0;
    if (hit) {
      count = Number(await hit.text()) || 0;
    }
    count += 1;
    const response = new Response(String(count), {
      headers: { 'Cache-Control': `max-age=${windowSeconds}`, 'Content-Type': 'text/plain' },
    });
    await cache.put(url, response.clone());
    return count <= limit;
  } catch {
    return true; // fail open
  }
}

function challengeHtml(siteKey: string): Response {
  const body = `<!doctype html><html><head><meta charset="utf-8"><title>Verify</title>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script></head>
<body style="font-family:system-ui;display:grid;place-items:center;min-height:100vh">
<form method="get"><div class="cf-turnstile" data-sitekey="${escapeAttr(siteKey)}" data-callback="ok"></div></form>
<script>function ok(t){const u=new URL(location.href);u.searchParams.set('cf-turnstile-response',t);location.href=u.toString()}</script>
</body></html>`;
  return new Response(body, { status: 429, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

export async function handleEdgeForm(
  request: Request,
  pathname: string,
  config: FormsConfig | undefined,
  turnstile: TurnstileConfig | undefined,
): Promise<Response | null> {
  if (!config?.enabled || request.method !== 'POST' || !config.endpoints?.length) return null;
  const endpoint = config.endpoints.find((e) => pathMatches(e.path, pathname));
  if (!endpoint) return null;

  const contentType = request.headers.get('content-type') || '';
  let fields: Record<string, string> = {};
  try {
    if (contentType.includes('application/json')) {
      const json = (await request.json()) as Record<string, unknown>;
      for (const [k, v] of Object.entries(json)) {
        if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') {
          fields[k] = String(v);
        }
      }
    } else {
      const form = await request.formData();
      form.forEach((v, k) => {
        if (typeof v === 'string') fields[k] = v;
      });
    }
  } catch {
    return jsonFormError('Invalid form body', 400);
  }

  const honeypot = endpoint.honeypot || 'company';
  if ((fields[honeypot] || '').trim() !== '') {
    return jsonFormOk(); // bot absorbed
  }

  if (endpoint.require_turnstile) {
    const token = fields['cf-turnstile-response'] || fields['turnstile_token'] || '';
    const secret = turnstile?.secret_key || '';
    if (!secret || !(await verifyTurnstile(token, secret, clientIp(request)))) {
      return jsonFormError('Bot check failed', 403);
    }
  }

  if (!config.ingest_url || !config.ingest_key) {
    return jsonFormError('Form delivery is not configured', 503);
  }

  const payload = {
    path: endpoint.path,
    to_email: endpoint.to_email,
    fields: Object.fromEntries(
      Object.entries(fields).filter(([k]) => !['cf-turnstile-response', 'turnstile_token', honeypot].includes(k)),
    ),
    submitted_at: new Date().toISOString(),
  };
  const body = JSON.stringify(payload);
  const sig = await hmacHex(config.ingest_key, body);
  try {
    const res = await fetch(config.ingest_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Dply-Edge-Form-Signature': sig,
      },
      body,
    });
    if (!res.ok) {
      return jsonFormError('Could not deliver form', 502);
    }
  } catch {
    return jsonFormError('Could not deliver form', 502);
  }

  const wantsHtml = (request.headers.get('accept') || '').includes('text/html');
  if (wantsHtml) {
    return new Response('<!doctype html><html><body><p>Thanks — we received your message.</p></body></html>', {
      status: 200,
      headers: { 'Content-Type': 'text/html; charset=utf-8' },
    });
  }
  return jsonFormOk();
}

function jsonFormOk(): Response {
  return new Response(JSON.stringify({ ok: true }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
}

function jsonFormError(message: string, status: number): Response {
  return new Response(JSON.stringify({ ok: false, error: message }), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

async function hmacHex(secret: string, body: string): Promise<string> {
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  const sig = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(body));
  return [...new Uint8Array(sig)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

const WR_COOKIE = 'dply_wr';

export async function enforceWaitingRoom(
  request: Request,
  pathname: string,
  config: WaitingRoomConfig | undefined,
): Promise<Response | null> {
  if (!config?.enabled) return null;
  const paths = config.paths?.length ? config.paths : ['/*'];
  if (!paths.some((p) => pathMatches(p, pathname))) return null;

  const cookieHeader = request.headers.get('cookie') || '';
  const admitted = cookieHeader.split(';').some((c) => c.trim().startsWith(`${WR_COOKIE}=1`));
  if (admitted) return null;

  const ip = clientIp(request);
  const minuteKey = `wr:admit:${Math.floor(Date.now() / 60000)}`;
  const activeKey = `wr:active`;
  const admittedThisMinute = await readCacheCount(minuteKey);
  const activeApprox = await readCacheCount(activeKey);

  if (
    admittedThisMinute < config.new_users_per_minute &&
    activeApprox < config.total_active_users
  ) {
    await bumpCacheCounter(minuteKey, config.new_users_per_minute + 1, 120);
    await bumpCacheCounter(activeKey, config.total_active_users + 1, config.session_duration_minutes * 60);
    // Let request through; caller stamps cookie via waitingRoomAdmitHeaders
    (request as Request & { __dplyWaitingRoomAdmit?: boolean }).__dplyWaitingRoomAdmit = true;
    return null;
  }

  const retry = Math.max(5, Math.ceil(60 / Math.max(1, config.new_users_per_minute)));
  const html = `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="${retry}">
<title>You’re in line</title></head>
<body style="font-family:system-ui;display:grid;place-items:center;min-height:100vh;background:#f6f5ef">
<main style="text-align:center;max-width:28rem;padding:2rem">
<h1>You’re in line</h1>
<p>This site is at capacity. We’ll refresh automatically.</p>
</main></body></html>`;
  return new Response(html, {
    status: 503,
    headers: {
      'Content-Type': 'text/html; charset=utf-8',
      'Retry-After': String(retry),
      'Cache-Control': 'no-store',
    },
  });
}

async function readCacheCount(key: string): Promise<number> {
  try {
    const cache = caches.default;
    const url = new URL(`https://edge-rate-limit.dply.internal/${encodeURIComponent(key)}`);
    const hit = await cache.match(url);
    if (!hit) return 0;
    return Number(await hit.text()) || 0;
  } catch {
    return 0;
  }
}

export function waitingRoomAdmitCookie(request: Request, config: WaitingRoomConfig | undefined): string | null {
  if (!config?.enabled) return null;
  const flagged = (request as Request & { __dplyWaitingRoomAdmit?: boolean }).__dplyWaitingRoomAdmit;
  if (!flagged) return null;
  const maxAge = Math.max(60, (config.session_duration_minutes || 30) * 60);
  return `${WR_COOKIE}=1; Path=/; Max-Age=${maxAge}; HttpOnly; Secure; SameSite=Lax`;
}

export function injectSnippets(html: string, pathname: string, config: SnippetsConfig | undefined): string {
  if (!config?.enabled || !config.items?.length || !html) return html;
  let out = html;
  for (const item of config.items) {
    if (!pathMatches(item.path || '/*', pathname)) continue;
    if (item.phase === 'head' && out.includes('</head>')) {
      out = out.replace(/<\/head>/i, `${item.html}</head>`);
    } else if (item.phase === 'body' && out.includes('</body>')) {
      out = out.replace(/<\/body>/i, `${item.html}</body>`);
    }
  }
  return out;
}

export function injectTags(html: string, config: TagsConfig | undefined): string {
  if (!config?.enabled || !html) return html;

  const tools = Array.isArray(config.tools) ? config.tools : [];
  const scripts = tools
    .filter((t) => typeof t.src === 'string' && t.src.startsWith('https://'))
    .map((t) => {
      const asyncAttr = t.async === false ? '' : ' async';
      return `<script src="${escapeAttr(t.src)}"${asyncAttr}></script>`;
    })
    .join('');

  let consent = '';
  if (config.consent_required) {
    consent = `<script>window.__dplyTags=window.__dplyTags||{consent:localStorage.getItem('dply_tag_consent')==='1'};</script>`;
  }

  // Consent helper can ship alone (no script URLs yet). Skip only when
  // there's nothing to inject.
  const block = consent + scripts;
  if (block === '') return html;

  if (html.includes('</head>')) {
    return html.replace(/<\/head>/i, `${block}</head>`);
  }
  return html + block;
}

export async function runEarlyAddons(
  request: Request,
  pathname: string,
  host: EdgeAddonsHostEntry,
): Promise<Response | null> {
  const waiting = await enforceWaitingRoom(request, pathname, host.waiting_room);
  if (waiting) return waiting;

  const form = await handleEdgeForm(request, pathname, host.forms, host.turnstile);
  if (form) return form;

  const limited = await enforceRateLimit(request, pathname, host.rate_limit, host.turnstile);
  if (limited) return limited;

  return null;
}

export function applyHtmlAddons(html: string, pathname: string, host: EdgeAddonsHostEntry): string {
  let out = html;
  out = injectSnippets(out, pathname, host.snippets);
  out = injectTags(out, host.tags);
  if (host.turnstile?.enabled && host.turnstile.mode === 'all' && host.turnstile.site_key) {
    out = injectTurnstileWidget(out, host.turnstile.site_key);
  } else if (
    host.turnstile?.enabled &&
    host.turnstile.mode === 'forms' &&
    host.turnstile.site_key &&
    host.forms?.enabled
  ) {
    out = injectTurnstileWidget(out, host.turnstile.site_key);
  }
  return out;
}
