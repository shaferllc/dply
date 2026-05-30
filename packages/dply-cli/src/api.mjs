/**
 * Thin wrapper around fetch — adds the bearer token, surfaces non-2xx
 * responses as structured errors, and parses JSON when present.
 */
export class ApiClient {
  /**
   * @param {{ baseUrl: string, token: string }} opts
   */
  constructor({ baseUrl, token }) {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.token = token;
  }

  /**
   * @param {string} path
   * @param {RequestInit} [init]
   */
  async request(path, init = {}) {
    const url = `${this.baseUrl}/api/v1${path.startsWith('/') ? path : `/${path}`}`;
    const headers = {
      Accept: 'application/json',
      Authorization: `Bearer ${this.token}`,
      ...(init.headers ?? {}),
    };

    if (init.body && typeof init.body !== 'string' && !(init.body instanceof Uint8Array)) {
      init.body = JSON.stringify(init.body);
      headers['Content-Type'] ??= 'application/json';
    }

    let response;
    try {
      response = await fetch(url, { ...init, headers });
    } catch (err) {
      throw apiError(`Network error talking to ${this.baseUrl}: ${err.message}`, 0);
    }

    const text = await response.text();
    let parsed = null;
    if (text) {
      try {
        parsed = JSON.parse(text);
      } catch {
        // Non-JSON body — keep raw text for the error path.
      }
    }

    if (!response.ok) {
      const message =
        (parsed && (parsed.message || parsed.error)) ||
        `${response.status} ${response.statusText}` ||
        'Request failed';
      throw apiError(message, response.status, parsed);
    }

    return parsed ?? {};
  }

  get(path, init) {
    return this.request(path, { ...init, method: 'GET' });
  }

  post(path, body, init = {}) {
    return this.request(path, { ...init, method: 'POST', body });
  }

  delete(path, init) {
    return this.request(path, { ...init, method: 'DELETE' });
  }

  patch(path, body, init = {}) {
    return this.request(path, { ...init, method: 'PATCH', body });
  }
}

function apiError(message, status, body) {
  const err = new Error(message);
  err.status = status;
  err.body = body ?? null;

  return err;
}
