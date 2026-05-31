<?php

declare(strict_types=1);

/**
 * On-host password gate for VM sites (form login + JWT cookie).
 * Deployed to {repo}/.dply/access-gate/index.php by SiteAccessGateProvisioner.
 */
const DPLY_VM_ACCESS_COOKIE = '__dply_vm_access';
const DPLY_VM_ACCESS_POST = '/__dply/access';
const DPLY_VM_ACCESS_VERIFY = '/__dply/access/verify';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$route = getenv('DPLY_ACCESS_ROUTE') ?: '';
$isVerifyRoute = $route === 'verify' || str_starts_with($uri, DPLY_VM_ACCESS_VERIFY);

try {
    dply_vm_access_handle($uri, $method, $route, $isVerifyRoute);
} catch (Throwable $e) {
    if ($isVerifyRoute) {
        dply_vm_access_deny_verify();
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Access gate error.';

    exit;
}

function dply_vm_access_handle(string $uri, string $method, string $route, bool $isVerifyRoute): void
{
    $configPath = __DIR__.'/config.json';
    if (! is_readable($configPath)) {
        if ($isVerifyRoute) {
            dply_vm_access_deny_verify();
        }

        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Access gate is not configured.';

        exit;
    }

    /** @var array<string, mixed>|null $config */
    $config = json_decode((string) file_get_contents($configPath), true);
    if (! is_array($config) || ($config['mode'] ?? '') !== 'password') {
        if ($isVerifyRoute) {
            dply_vm_access_deny_verify();
        }

        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Access gate is not active.';

        exit;
    }

    if ($route === '' && $isVerifyRoute) {
        $route = 'verify';
    }

    $hostname = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $hostname = preg_replace('/:\d+$/', '', $hostname) ?: $hostname;

    $allowedHostnames = array_map(
        static fn ($h): string => strtolower((string) $h),
        is_array($config['hostnames'] ?? null) ? $config['hostnames'] : [],
    );

    if ($allowedHostnames !== [] && ! in_array($hostname, $allowedHostnames, true)) {
        if ($isVerifyRoute) {
            dply_vm_access_deny_verify();
        }

        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden.';

        exit;
    }

    if ($route === 'verify' || $uri === DPLY_VM_ACCESS_VERIFY) {
        if (dply_vm_access_has_valid_cookie($config, $hostname)) {
            http_response_code(204);

            exit;
        }

        dply_vm_access_deny_verify();
    }

    if ($uri === DPLY_VM_ACCESS_POST && $method === 'POST') {
        $password = (string) ($_POST['password'] ?? '');
        $matched = dply_vm_access_match_password($config, $password);

        if ($matched === null) {
            dply_vm_access_render_form($config, $hostname, 'Incorrect password.');

            exit;
        }

        $token = dply_vm_access_issue_token($config, $hostname, $matched);
        dply_vm_access_log_login($matched, $hostname);
        $return = (string) ($_POST['return'] ?? '/');
        if ($return === '' || ! str_starts_with($return, '/')) {
            $return = '/';
        }

        dply_vm_access_redirect_with_cookie($return, $token, (bool) ($config['secure_cookies'] ?? false));

        exit;
    }

    dply_vm_access_render_form($config, $hostname);
}

function dply_vm_access_deny_verify(): never
{
    http_response_code(401);

    exit;
}

/**
 * @param  array<string, mixed>  $config
 * @return array{id?: string, label?: string, password_salt?: string, password_verifier?: string}|null
 */
function dply_vm_access_match_password(array $config, string $password): ?array
{
    $entries = is_array($config['passwords'] ?? null) ? $config['passwords'] : [];

    if ($entries === [] && is_string($config['password_salt'] ?? null) && is_string($config['password_verifier'] ?? null)) {
        $entries = [[
            'id' => 'legacy',
            'label' => 'Default',
            'password_salt' => $config['password_salt'],
            'password_verifier' => $config['password_verifier'],
        ]];
    }

    foreach ($entries as $entry) {
        if (! is_array($entry)) {
            continue;
        }

        $salt = (string) ($entry['password_salt'] ?? '');
        $verifier = (string) ($entry['password_verifier'] ?? '');
        if ($salt === '' || $verifier === '') {
            continue;
        }

        $candidate = hash('sha256', $salt.$password);
        if (hash_equals($verifier, $candidate)) {
            return $entry;
        }
    }

    return null;
}

/**
 * @param  array{id?: string, label?: string}  $credential
 */
function dply_vm_access_log_login(array $credential, string $hostname): void
{
    $entry = json_encode([
        'at' => gmdate('c'),
        'credential_id' => (string) ($credential['id'] ?? ''),
        'label' => (string) ($credential['label'] ?? ''),
        'hostname' => $hostname,
        'ip' => dply_vm_access_client_ip(),
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    file_put_contents(__DIR__.'/logins.jsonl', $entry."\n", FILE_APPEND | LOCK_EX);
}

function dply_vm_access_client_ip(): ?string
{
    $candidates = [
        $_SERVER['HTTP_X_REAL_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (! is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        $first = trim(explode(',', $candidate)[0]);

        return $first !== '' ? $first : null;
    }

    return null;
}

/**
 * @param  array<string, mixed>  $config
 */
function dply_vm_access_has_valid_cookie(array $config, string $hostname): bool
{
    $token = dply_vm_access_read_cookie(DPLY_VM_ACCESS_COOKIE);
    if ($token === null) {
        return false;
    }

    $payload = dply_vm_access_verify_jwt(
        $token,
        (string) ($config['cookie_secret'] ?? ''),
        $hostname,
        (string) ($config['site_id'] ?? ''),
    );

    return $payload !== null;
}

/**
 * @param  array<string, mixed>  $config
 * @param  array{id?: string, label?: string}  $credential
 */
function dply_vm_access_issue_token(array $config, string $hostname, array $credential): string
{
    $expiresAt = time() + 86400;
    $header = dply_vm_access_base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $body = dply_vm_access_base64url(json_encode([
        'site_id' => (string) ($config['site_id'] ?? ''),
        'hostname' => $hostname,
        'credential_id' => (string) ($credential['id'] ?? ''),
        'label' => (string) ($credential['label'] ?? ''),
        'exp' => $expiresAt,
    ], JSON_THROW_ON_ERROR));
    $signature = dply_vm_access_hmac("{$header}.{$body}", (string) ($config['cookie_secret'] ?? ''));

    return "{$header}.{$body}.{$signature}";
}

/**
 * @return array{site_id: string, hostname: string, credential_id?: string, label?: string, exp: int}|null
 */
function dply_vm_access_verify_jwt(string $token, string $secret, string $hostname, string $siteId): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$header, $body, $signature] = $parts;
    $expected = dply_vm_access_hmac("{$header}.{$body}", $secret);
    if (! hash_equals($expected, $signature)) {
        return null;
    }

    try {
        $json = dply_vm_access_base64url_decode($body);
        /** @var array{site_id?: string, hostname?: string, credential_id?: string, label?: string, exp?: int} $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    if (($payload['site_id'] ?? '') !== $siteId) {
        return null;
    }

    if (strtolower((string) ($payload['hostname'] ?? '')) !== $hostname) {
        return null;
    }

    if (! is_int($payload['exp'] ?? null) || $payload['exp'] < time()) {
        return null;
    }

    return [
        'site_id' => (string) $payload['site_id'],
        'hostname' => (string) $payload['hostname'],
        'credential_id' => isset($payload['credential_id']) ? (string) $payload['credential_id'] : '',
        'label' => isset($payload['label']) ? (string) $payload['label'] : '',
        'exp' => (int) $payload['exp'],
    ];
}

function dply_vm_access_read_cookie(string $name): ?string
{
    $header = (string) ($_SERVER['HTTP_COOKIE'] ?? '');
    foreach (explode(';', $header) as $part) {
        $part = trim($part);
        if (str_starts_with($part, $name.'=')) {
            return rawurldecode(substr($part, strlen($name) + 1));
        }
    }

    return null;
}

function dply_vm_access_redirect_with_cookie(string $location, string $token, bool $secure): void
{
    $flags = 'Path=/; HttpOnly; SameSite=Lax; Max-Age=86400';
    if ($secure) {
        $flags .= '; Secure';
    }

    header('Set-Cookie: '.DPLY_VM_ACCESS_COOKIE.'='.rawurlencode($token).'; '.$flags);
    header('Location: '.$location, true, 302);
}

/**
 * @param  array<string, mixed>  $config
 */
function dply_vm_access_render_form(array $config, string $hostname, ?string $error = null): void
{
    http_response_code($error !== null ? 401 : 200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');

    $return = (string) ($_GET['return'] ?? '/');
    if ($return === '' || ! str_starts_with($return, '/')) {
        $return = '/';
    }

    $safeHost = htmlspecialchars($hostname, ENT_QUOTES, 'UTF-8');
    $safeReturn = htmlspecialchars($return, ENT_QUOTES, 'UTF-8');
    $safeError = $error !== null ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : '';

    $errorBlock = $error !== null
        ? <<<HTML
        <div class="alert" role="alert">
            <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            <span>{$safeError}</span>
        </div>
        HTML
        : '';

    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex,nofollow">
<title>Site access · {$safeHost}</title>
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  :root {
    color-scheme: light dark;
    --ink: #171a0e;
    --forest: #32482c;
    --moss: #5d6259;
    --sage: #688479;
    --gold: #cda942;
    --sand: #e1d8ac;
    --cream: #fdfcf9;
    --mist: #a7a69a;
    --card: rgba(255, 255, 255, 0.82);
    --card-border: rgba(23, 26, 14, 0.1);
    --input-bg: #fff;
    --input-border: rgba(23, 26, 14, 0.14);
    --shadow: 0 24px 80px rgba(23, 26, 14, 0.12), 0 8px 24px rgba(23, 26, 14, 0.06);
    --mesh-a: rgba(205, 169, 66, 0.16);
    --mesh-b: rgba(104, 132, 121, 0.18);
    --mesh-c: rgba(50, 72, 44, 0.1);
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --ink: #eef0e8;
      --forest: #9cbc92;
      --moss: #b9bcb4;
      --sage: #9fb5ae;
      --gold: #e6d18f;
      --sand: #2f352c;
      --cream: #141612;
      --mist: #7a7d76;
      --card: rgba(26, 28, 24, 0.88);
      --card-border: rgba(238, 240, 232, 0.1);
      --input-bg: rgba(20, 22, 18, 0.9);
      --input-border: rgba(238, 240, 232, 0.12);
      --shadow: 0 24px 80px rgba(0, 0, 0, 0.45), 0 8px 24px rgba(0, 0, 0, 0.25);
      --mesh-a: rgba(205, 169, 66, 0.07);
      --mesh-b: rgba(104, 132, 121, 0.09);
      --mesh-c: rgba(50, 72, 44, 0.06);
    }
  }
  html, body { height: 100%; margin: 0; }
  body {
    font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
    color: var(--ink);
    background: var(--cream);
    background-image:
      radial-gradient(ellipse 80% 60% at 15% 10%, var(--mesh-a), transparent 55%),
      radial-gradient(ellipse 70% 55% at 85% 0%, var(--mesh-b), transparent 50%),
      radial-gradient(ellipse 90% 70% at 50% 100%, var(--mesh-c), transparent 60%);
    display: grid;
    place-items: center;
    padding: max(1.25rem, env(safe-area-inset-top)) max(1.25rem, env(safe-area-inset-right))
             max(1.25rem, env(safe-area-inset-bottom)) max(1.25rem, env(safe-area-inset-left));
    -webkit-font-smoothing: antialiased;
  }
  .shell {
    width: min(100%, 26rem);
    animation: rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
  }
  @keyframes rise {
    from { opacity: 0; transform: translateY(12px) scale(0.985); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }
  .card {
    position: relative;
    overflow: hidden;
    border-radius: 1.35rem;
    border: 1px solid var(--card-border);
    background: var(--card);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    box-shadow: var(--shadow);
    padding: 1.75rem 1.75rem 1.5rem;
  }
  .card::before {
    content: "";
    position: absolute;
    inset: 0 0 auto 0;
    height: 3px;
    background: linear-gradient(90deg, var(--forest), var(--sage), var(--gold));
    opacity: 0.85;
  }
  .brand-row {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    margin-bottom: 1.35rem;
  }
  .icon-wrap {
    flex-shrink: 0;
    display: grid;
    place-items: center;
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 1rem;
    border: 1px solid color-mix(in srgb, var(--sage) 35%, transparent);
    background: color-mix(in srgb, var(--sage) 18%, transparent);
    color: var(--forest);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.25);
  }
  .icon-wrap svg { width: 1.35rem; height: 1.35rem; }
  .eyebrow {
    margin: 0;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--sage);
  }
  h1 {
    margin: 0.15rem 0 0;
    font-size: 1.35rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.2;
  }
  .lede {
    margin: 0.85rem 0 0;
    font-size: 0.9375rem;
    line-height: 1.55;
    color: var(--moss);
  }
  .host-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    margin-top: 0.65rem;
    max-width: 100%;
    padding: 0.35rem 0.65rem;
    border-radius: 999px;
    border: 1px solid var(--input-border);
    background: color-mix(in srgb, var(--sand) 45%, transparent);
    font-family: ui-monospace, 'SF Mono', Menlo, monospace;
    font-size: 0.75rem;
    color: var(--ink);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .host-pill svg { width: 0.85rem; height: 0.85rem; flex-shrink: 0; color: var(--sage); }
  .alert {
    display: flex;
    align-items: flex-start;
    gap: 0.55rem;
    margin-top: 1.1rem;
    padding: 0.75rem 0.85rem;
    border-radius: 0.85rem;
    border: 1px solid rgba(185, 28, 28, 0.25);
    background: rgba(254, 226, 226, 0.65);
    color: #991b1b;
    font-size: 0.875rem;
    line-height: 1.45;
  }
  @media (prefers-color-scheme: dark) {
    .alert {
      background: rgba(127, 29, 29, 0.35);
      border-color: rgba(248, 113, 113, 0.25);
      color: #fecaca;
    }
  }
  .alert-icon { width: 1.1rem; height: 1.1rem; flex-shrink: 0; margin-top: 0.05rem; }
  form { margin-top: 1.35rem; display: grid; gap: 1rem; }
  label { display: grid; gap: 0.45rem; }
  label span {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--moss);
  }
  .input-wrap { position: relative; }
  input[type="password"] {
    width: 100%;
    appearance: none;
    border: 1px solid var(--input-border);
    border-radius: 0.85rem;
    background: var(--input-bg);
    color: var(--ink);
    padding: 0.8rem 2.75rem 0.8rem 0.9rem;
    font: inherit;
    font-size: 1rem;
    line-height: 1.2;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }
  input[type="password"]:focus {
    outline: none;
    border-color: color-mix(in srgb, var(--sage) 65%, transparent);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--sage) 22%, transparent);
  }
  .toggle-pw {
    position: absolute;
    top: 50%;
    right: 0.55rem;
    transform: translateY(-50%);
    border: 0;
    background: transparent;
    color: var(--mist);
    padding: 0.35rem;
    border-radius: 0.5rem;
    cursor: pointer;
    display: grid;
    place-items: center;
  }
  .toggle-pw:hover { color: var(--sage); background: color-mix(in srgb, var(--sage) 12%, transparent); }
  .toggle-pw svg { width: 1.15rem; height: 1.15rem; }
  button[type="submit"] {
    appearance: none;
    border: 0;
    border-radius: 0.85rem;
    padding: 0.85rem 1rem;
    font: inherit;
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--cream);
    cursor: pointer;
    background: linear-gradient(180deg, color-mix(in srgb, var(--forest) 92%, #fff 8%), var(--forest));
    box-shadow: 0 1px 0 rgba(255,255,255,0.12) inset, 0 10px 24px color-mix(in srgb, var(--forest) 35%, transparent);
    transition: transform 0.12s ease, box-shadow 0.15s ease, filter 0.15s ease;
  }
  button[type="submit"]:hover {
    filter: brightness(1.05);
    box-shadow: 0 1px 0 rgba(255,255,255,0.14) inset, 0 14px 28px color-mix(in srgb, var(--forest) 42%, transparent);
  }
  button[type="submit"]:active { transform: translateY(1px); }
  .foot {
    margin-top: 1.15rem;
    padding-top: 1rem;
    border-top: 1px solid var(--card-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    font-size: 0.75rem;
    color: var(--mist);
  }
  .foot-mark {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--sage);
  }
  .foot-mark svg { width: 0.9rem; height: 0.9rem; }
</style>
</head>
<body>
<div class="shell">
  <main class="card">
    <div class="brand-row">
      <div class="icon-wrap" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
          <rect x="5" y="11" width="14" height="10" rx="2"/>
          <path d="M8 11V8a4 4 0 1 1 8 0v3"/>
        </svg>
      </div>
      <div style="min-width:0">
        <p class="eyebrow">Staging access</p>
        <h1>Enter password</h1>
      </div>
    </div>
    <p class="lede">This site is locked while you work on it. Sign in once — your browser keeps access for 24 hours.</p>
    <div class="host-pill" title="{$safeHost}">
      <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.083 9h1.946c.089-1.546.383-2.97.837-4.118A6.004 6.004 0 004.083 9zM10 2a8 8 0 100 16 8 8 0 000-16zm0 2c-.076 0-.232.032-.465.262-.238.234-.497.623-.737 1.182-.389.906-.673 2.142-.766 3.556h3.936c-.093-1.414-.377-2.65-.766-3.556-.24-.56-.5-.948-.737-1.182C10.232 4.032 10.076 4 10 4zm3.971 5c-.089-1.546-.383-2.97-.837-4.118A6.004 6.004 0 0115.917 9h-1.946zm-2.003 2H8.032c.093 1.414.377 2.65.766 3.556.24.56.5.948.737 1.182.233.23.389.262.465.262.076 0 .232-.032.465-.262.238-.234.498-.623.737-1.182.389-.906.673-2.142.766-3.556zm1.166 4.118c.454-1.147.748-2.572.837-4.118h1.946a6.004 6.004 0 01-2.783 4.118zm-6.268 0C6.412 13.97 6.118 12.546 6.03 11H4.083a6.004 6.004 0 002.783 4.118z" clip-rule="evenodd"/></svg>
      <span>{$safeHost}</span>
    </div>
    {$errorBlock}
    <form method="post" action="/__dply/access" autocomplete="on">
      <input type="hidden" name="return" value="{$safeReturn}" />
      <label>
        <span>Password</span>
        <div class="input-wrap">
          <input id="password" type="password" name="password" autocomplete="current-password" required placeholder="Enter gate password" autofocus />
          <button type="button" class="toggle-pw" aria-label="Show password" data-toggle-password>
            <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true" hidden><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-8-10-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
      </label>
      <button type="submit">Continue to site</button>
    </form>
    <div class="foot">
      <span>Cookie lasts 24 hours</span>
      <span class="foot-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Dply
      </span>
    </div>
  </main>
</div>
<script>
(function () {
  var btn = document.querySelector('[data-toggle-password]');
  var input = document.getElementById('password');
  if (!btn || !input) return;
  btn.addEventListener('click', function () {
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    btn.querySelector('.icon-show').hidden = show;
    btn.querySelector('.icon-hide').hidden = !show;
    input.focus();
  });
})();
</script>
</body>
</html>
HTML;
}

function dply_vm_access_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function dply_vm_access_base64url_decode(string $value): string
{
    $padded = strtr($value, '-_', '+/');
    $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

    return (string) base64_decode($padded, true);
}

function dply_vm_access_hmac(string $message, string $secret): string
{
    return dply_vm_access_base64url(hash_hmac('sha256', $message, $secret, true));
}
