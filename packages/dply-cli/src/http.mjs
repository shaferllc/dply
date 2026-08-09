/**
 * Fetch wrapper that trusts common local HTTPS CAs (Dply Local, Valet, mkcert).
 *
 * Node's built-in fetch does not use the macOS keychain, so `.test` hosts behind
 * Dply Local's "dpl local CA" fail with UNABLE_TO_VERIFY_LEAF_SIGNATURE even when
 * the browser padlock works. We load known CA PEMs and use node:https for those
 * requests (and for any host when DPLY_TLS_CA_FILE / extra CAs apply).
 */
import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import os from 'node:os';
import path from 'node:path';
import tls from 'node:tls';
import { URL } from 'node:url';

const LOCAL_HOST_RE =
  /(^|\.)test$|(^|\.)localhost$|^127\.0\.0\.1$|^\[?::1\]?$|(^|\.)local$/i;

/** @type {Buffer[] | null} */
let cachedExtraCas = null;

/**
 * @param {string} hostname
 */
export function isLocalDevHost(hostname) {
  return LOCAL_HOST_RE.test(String(hostname || ''));
}

/**
 * @returns {string[]}
 */
export function localCaCandidatePaths() {
  const home = os.homedir();
  const fromEnv = [process.env.DPLY_TLS_CA_FILE, process.env.NODE_EXTRA_CA_CERTS].filter(
    (value) => typeof value === 'string' && value.trim() !== '',
  );

  return [
    ...fromEnv,
    // Dply Local (.test HTTPS via dpld) — CA is also in the macOS keychain
    path.join(home, '.dpl', 'certs', 'ca.pem'),
    // Laravel Valet
    path.join(home, '.config', 'valet', 'CA', 'LaravelValetCASelfSigned.pem'),
    path.join(home, 'Library', 'Application Support', 'valet', 'CA', 'LaravelValetCASelfSigned.pem'),
    // mkcert
    path.join(home, 'Library', 'Application Support', 'mkcert', 'rootCA.pem'),
    path.join(home, '.local', 'share', 'mkcert', 'rootCA.pem'),
  ];
}

/**
 * @returns {Buffer[]}
 */
function loadExtraCas() {
  if (cachedExtraCas) {
    return cachedExtraCas;
  }

  /** @type {Buffer[]} */
  const pems = [];
  for (const candidate of localCaCandidatePaths()) {
    try {
      if (!fs.existsSync(candidate)) {
        continue;
      }
      const content = fs.readFileSync(candidate);
      if (content.length > 0) {
        pems.push(content);
      }
    } catch {
      // ignore unreadable paths
    }
  }

  cachedExtraCas = pems;

  return pems;
}

function insecureTlsEnabled() {
  const value = String(process.env.DPLY_INSECURE_TLS || '').toLowerCase();

  return value === '1' || value === 'true' || value === 'yes';
}

/**
 * @param {string | URL} input
 * @param {RequestInit} [init]
 * @returns {Promise<Response>}
 */
export async function dplyFetch(input, init = {}) {
  const url = typeof input === 'string' ? new URL(input) : new URL(String(input));
  const extras = loadExtraCas();
  const local = isLocalDevHost(url.hostname);
  const insecure = insecureTlsEnabled();

  // Public HTTPS with no extra CAs → native fetch (uses system roots).
  if (url.protocol === 'https:' && !local && extras.length === 0 && !insecure) {
    return fetch(input, init);
  }

  // HTTP never needs CA handling.
  if (url.protocol === 'http:') {
    return fetch(input, init);
  }

  // Local / custom CA / insecure: node:https so we can pass `ca` / rejectUnauthorized.
  return nodeHttpsFetch(url, init, {
    ca: extras.length > 0 ? [...tls.rootCertificates, ...extras] : undefined,
    // Prefer verifying with known local CAs; only skip verify when forced or
    // when talking to a local host with no CA file found.
    rejectUnauthorized: insecure ? false : !(local && extras.length === 0),
  });
}

/**
 * Minimal fetch()-compatible wrapper over http(s).request.
 *
 * @param {URL} url
 * @param {RequestInit} init
 * @param {{ ca?: Array<string|Buffer>, rejectUnauthorized?: boolean }} tlsOpts
 * @returns {Promise<Response>}
 */
function nodeHttpsFetch(url, init, tlsOpts) {
  const lib = url.protocol === 'http:' ? http : https;
  const method = (init.method || 'GET').toUpperCase();
  /** @type {Record<string, string>} */
  const headers = {};
  if (init.headers) {
    const h = new Headers(init.headers);
    h.forEach((value, key) => {
      headers[key] = value;
    });
  }

  let body = init.body;
  if (body != null && typeof body !== 'string' && !(body instanceof Uint8Array) && !Buffer.isBuffer(body)) {
    body = String(body);
  }
  if (body != null && headers['content-length'] == null && headers['Content-Length'] == null) {
    headers['Content-Length'] = String(Buffer.byteLength(body));
  }

  return new Promise((resolve, reject) => {
    const req = lib.request(
      url,
      {
        method,
        headers,
        ca: tlsOpts.ca,
        rejectUnauthorized: tlsOpts.rejectUnauthorized,
      },
      (res) => {
        const chunks = [];
        res.on('data', (chunk) => chunks.push(chunk));
        res.on('end', () => {
          const buf = Buffer.concat(chunks);
          resolve(
            new Response(buf, {
              status: res.statusCode ?? 0,
              statusText: res.statusMessage ?? '',
              headers: res.headers,
            }),
          );
        });
      },
    );

    req.on('error', reject);

    if (init.signal) {
      if (init.signal.aborted) {
        req.destroy();
        reject(init.signal.reason ?? new Error('Aborted'));

        return;
      }
      init.signal.addEventListener(
        'abort',
        () => {
          req.destroy();
          reject(init.signal.reason ?? new Error('Aborted'));
        },
        { once: true },
      );
    }

    if (body != null) {
      req.write(body);
    }
    req.end();
  });
}

/**
 * Human hint when TLS verification fails against a local control plane.
 *
 * @param {unknown} err
 * @param {string} baseUrl
 */
export function tlsFailureHint(err, baseUrl) {
  const code = err?.cause?.code || err?.code || '';
  const message = String(err?.message || err || '');
  const isTls =
    code === 'UNABLE_TO_VERIFY_LEAF_SIGNATURE' ||
    code === 'CERT_HAS_EXPIRED' ||
    code === 'DEPTH_ZERO_SELF_SIGNED_CERT' ||
    /unable to verify|self.?signed|certificate/i.test(message);

  if (!isTls) {
    return '';
  }

  try {
    const host = new URL(baseUrl).hostname;
    if (!isLocalDevHost(host)) {
      return ' TLS certificate verification failed.';
    }
  } catch {
    return ' TLS certificate verification failed.';
  }

  const dplCa = path.join(os.homedir(), '.dpl', 'certs', 'ca.pem');
  if (fs.existsSync(dplCa)) {
    return ` Local TLS failed even with ${dplCa}. Try DPLY_INSECURE_TLS=1 once, or reinstall Dply Local.`;
  }

  return ' Local HTTPS CA not found (expected ~/.dpl/certs/ca.pem from Dply Local). Run Dply Local setup, or set DPLY_TLS_CA_FILE=/path/to/ca.pem, or DPLY_INSECURE_TLS=1.';
}
