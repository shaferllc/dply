<?php

/**
 * Reusable web-server CONFIG snippets, grouped by engine. These are dropped
 * straight into the config editor (App\Livewire\Sites\WebserverConfig) — unlike
 * config/script_marketplace.php, which holds shell scripts that RUN on the host.
 *
 * Each entry:
 *   - name        short label shown in the picker
 *   - description one-line summary
 *   - webservers  engines this snippet is valid for (config syntax is
 *                 engine-specific, so these are never wildcards)
 *   - content     the config block inserted into the editor
 */
return [
    // ───────────────────────── Nginx (server-block directives) ─────────────────────────
    'nginx-security-headers' => [
        'name' => 'Security headers',
        'description' => 'Common hardening response headers.',
        'webservers' => ['nginx'],
        'content' => <<<'CONF'
# Security headers
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header X-XSS-Protection "1; mode=block" always;
CONF,
    ],
    'nginx-gzip' => [
        'name' => 'Gzip compression',
        'description' => 'Compress text responses.',
        'webservers' => ['nginx'],
        'content' => <<<'CONF'
# Gzip compression
gzip on;
gzip_comp_level 5;
gzip_min_length 256;
gzip_proxied any;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml image/svg+xml;
CONF,
    ],
    'nginx-static-cache' => [
        'name' => 'Cache static assets',
        'description' => 'Long-lived cache headers for static files.',
        'webservers' => ['nginx'],
        'content' => <<<'CONF'
# Cache static assets
location ~* \.(?:css|js|jpg|jpeg|gif|png|svg|woff2?|ico)$ {
    expires 30d;
    add_header Cache-Control "public, no-transform";
    access_log off;
}
CONF,
    ],
    'nginx-reverse-proxy' => [
        'name' => 'Reverse proxy',
        'description' => 'Proxy requests to a local upstream app.',
        'webservers' => ['nginx'],
        'content' => <<<'CONF'
# Reverse proxy to an upstream app
location / {
    proxy_pass http://127.0.0.1:3000;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
CONF,
    ],
    'nginx-deny-dotfiles' => [
        'name' => 'Block hidden files',
        'description' => 'Deny dotfiles except /.well-known.',
        'webservers' => ['nginx'],
        'content' => <<<'CONF'
# Block access to hidden files (allow ACME / .well-known)
location ~ /\.(?!well-known).* {
    deny all;
}
CONF,
    ],
    'nginx-client-max-body' => [
        'name' => 'Larger upload limit',
        'description' => 'Raise the max request body size.',
        'webservers' => ['nginx'],
        'content' => <<<'CONF'
# Allow larger uploads
client_max_body_size 64m;
CONF,
    ],

    // ───────────────────────── Caddy (Caddyfile site directives) ─────────────────────────
    'caddy-security-headers' => [
        'name' => 'Security headers',
        'description' => 'Common hardening response headers.',
        'webservers' => ['caddy'],
        'content' => <<<'CONF'
# Security headers
header {
    X-Frame-Options "SAMEORIGIN"
    X-Content-Type-Options "nosniff"
    Referrer-Policy "strict-origin-when-cross-origin"
    -Server
}
CONF,
    ],
    'caddy-encode' => [
        'name' => 'Compression',
        'description' => 'Enable zstd + gzip encoding.',
        'webservers' => ['caddy'],
        'content' => <<<'CONF'
# Response compression
encode zstd gzip
CONF,
    ],
    'caddy-static-cache' => [
        'name' => 'Cache static assets',
        'description' => 'Long-lived cache headers for static files.',
        'webservers' => ['caddy'],
        'content' => <<<'CONF'
# Cache static assets for 30 days
@static {
    path *.css *.js *.jpg *.jpeg *.gif *.png *.svg *.woff *.woff2 *.ico
}
header @static Cache-Control "public, max-age=2592000, immutable"
CONF,
    ],
    'caddy-reverse-proxy' => [
        'name' => 'Reverse proxy',
        'description' => 'Proxy requests to a local upstream app.',
        'webservers' => ['caddy'],
        'content' => <<<'CONF'
# Reverse proxy to an upstream app
reverse_proxy 127.0.0.1:3000
CONF,
    ],
    'caddy-deny-dotfiles' => [
        'name' => 'Block hidden files',
        'description' => 'Return 403 for dotfiles except /.well-known.',
        'webservers' => ['caddy'],
        'content' => <<<'CONF'
# Block hidden files (allow ACME / .well-known)
@dotfiles {
    path /.*
    not path /.well-known/*
}
respond @dotfiles 403
CONF,
    ],
    'caddy-request-body' => [
        'name' => 'Larger upload limit',
        'description' => 'Raise the max request body size.',
        'webservers' => ['caddy'],
        'content' => <<<'CONF'
# Allow larger uploads
request_body {
    max_size 64MB
}
CONF,
    ],

    // ───────────────────────── Apache (vhost directives) ─────────────────────────
    'apache-security-headers' => [
        'name' => 'Security headers',
        'description' => 'Common hardening response headers (mod_headers).',
        'webservers' => ['apache'],
        'content' => <<<'CONF'
# Security headers (requires mod_headers)
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
CONF,
    ],
    'apache-deflate' => [
        'name' => 'Compression',
        'description' => 'Compress text responses (mod_deflate).',
        'webservers' => ['apache'],
        'content' => <<<'CONF'
# Compression (mod_deflate)
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/css application/json application/javascript application/xml image/svg+xml
</IfModule>
CONF,
    ],
    'apache-expires' => [
        'name' => 'Cache static assets',
        'description' => 'Long-lived cache headers (mod_expires).',
        'webservers' => ['apache'],
        'content' => <<<'CONF'
# Cache static assets (mod_expires)
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpeg "access plus 30 days"
    ExpiresByType image/png "access plus 30 days"
    ExpiresByType text/css "access plus 30 days"
    ExpiresByType application/javascript "access plus 30 days"
</IfModule>
CONF,
    ],
    'apache-reverse-proxy' => [
        'name' => 'Reverse proxy',
        'description' => 'Proxy requests to a local upstream app (mod_proxy).',
        'webservers' => ['apache'],
        'content' => <<<'CONF'
# Reverse proxy to an upstream app (mod_proxy + mod_proxy_http)
ProxyPreserveHost On
ProxyPass / http://127.0.0.1:3000/
ProxyPassReverse / http://127.0.0.1:3000/
CONF,
    ],
    'apache-deny-dotfiles' => [
        'name' => 'Block hidden files',
        'description' => 'Deny access to dotfiles.',
        'webservers' => ['apache'],
        'content' => <<<'CONF'
# Block hidden files
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
CONF,
    ],
    'apache-body-limit' => [
        'name' => 'Larger upload limit',
        'description' => 'Raise the max request body size (bytes).',
        'webservers' => ['apache'],
        'content' => <<<'CONF'
# Allow larger uploads (64 MB, in bytes)
LimitRequestBody 67108864
CONF,
    ],

    // ───────────────────────── Nginx (extras) ─────────────────────────
    'nginx-hsts' => [
        'name' => 'HSTS',
        'description' => 'Strict-Transport-Security (enable only once HTTPS works).',
        'webservers' => ['nginx'],
        'content' => <<<'CONF'
# HSTS — only enable once HTTPS is confirmed working
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
CONF,
    ],
    'nginx-cors' => [
        'name' => 'CORS headers',
        'description' => 'Allow cross-origin requests (adjust the origin).',
        'webservers' => ['nginx'],
        'content' => <<<'CONF'
# CORS (adjust the allowed origin)
add_header Access-Control-Allow-Origin "https://example.com" always;
add_header Access-Control-Allow-Methods "GET, POST, OPTIONS" always;
add_header Access-Control-Allow-Headers "Content-Type, Authorization" always;
CONF,
    ],
    'nginx-rate-limit' => [
        'name' => 'Rate limiting',
        'description' => 'Throttle requests per client (zone defined in http{}).',
        'webservers' => ['nginx'],
        'content' => <<<'CONF'
# Rate limiting — define the zone once in the http{} context (Server settings):
#   limit_req_zone $binary_remote_addr zone=app:10m rate=10r/s;
# then apply it here:
limit_req zone=app burst=20 nodelay;
CONF,
    ],
    'nginx-basic-auth' => [
        'name' => 'Basic auth',
        'description' => 'Password-protect the site.',
        'webservers' => ['nginx'],
        'content' => <<<'CONF'
# Basic auth — create the file first: htpasswd -c /etc/nginx/.htpasswd <user>
auth_basic "Restricted";
auth_basic_user_file /etc/nginx/.htpasswd;
CONF,
    ],
    'nginx-gzip-static' => [
        'name' => 'Serve pre-compressed files',
        'description' => 'Use .gz siblings when present (gzip_static).',
        'webservers' => ['nginx'],
        'content' => <<<'CONF'
# Serve pre-compressed .gz files when they exist
gzip_static on;
CONF,
    ],

    // ───────────────────────── Caddy (extras) ─────────────────────────
    'caddy-hsts' => [
        'name' => 'HSTS',
        'description' => 'Strict-Transport-Security (enable only once HTTPS works).',
        'webservers' => ['caddy'],
        'content' => <<<'CONF'
# HSTS — only enable once HTTPS is confirmed working
header Strict-Transport-Security "max-age=31536000; includeSubDomains"
CONF,
    ],
    'caddy-cors' => [
        'name' => 'CORS headers',
        'description' => 'Allow cross-origin requests (adjust the origin).',
        'webservers' => ['caddy'],
        'content' => <<<'CONF'
# CORS (adjust the allowed origin)
header {
    Access-Control-Allow-Origin "https://example.com"
    Access-Control-Allow-Methods "GET, POST, OPTIONS"
    Access-Control-Allow-Headers "Content-Type, Authorization"
}
CONF,
    ],
    'caddy-basic-auth' => [
        'name' => 'Basic auth',
        'description' => 'Password-protect the site.',
        'webservers' => ['caddy'],
        'content' => <<<'CONF'
# Basic auth — generate the hash with: caddy hash-password
basic_auth {
    # username  $2a$14$<bcrypt-hash>
}
CONF,
    ],
    'caddy-h2' => [
        'name' => 'Protocols (HTTP/2 + HTTP/3)',
        'description' => 'Pin the protocols this site negotiates.',
        'webservers' => ['caddy'],
        'content' => <<<'CONF'
# Negotiate HTTP/1.1, HTTP/2 and HTTP/3 (global option — place in the global block)
servers {
    protocols h1 h2 h3
}
CONF,
    ],

    // ───────────────────────── Apache (extras) ─────────────────────────
    'apache-hsts' => [
        'name' => 'HSTS',
        'description' => 'Strict-Transport-Security (enable only once HTTPS works).',
        'webservers' => ['apache'],
        'content' => <<<'CONF'
# HSTS (mod_headers) — only enable once HTTPS is confirmed working
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
CONF,
    ],
    'apache-cors' => [
        'name' => 'CORS headers',
        'description' => 'Allow cross-origin requests (adjust the origin).',
        'webservers' => ['apache'],
        'content' => <<<'CONF'
# CORS (mod_headers; adjust the allowed origin)
Header always set Access-Control-Allow-Origin "https://example.com"
Header always set Access-Control-Allow-Methods "GET, POST, OPTIONS"
Header always set Access-Control-Allow-Headers "Content-Type, Authorization"
CONF,
    ],
    'apache-basic-auth' => [
        'name' => 'Basic auth',
        'description' => 'Password-protect the site.',
        'webservers' => ['apache'],
        'content' => <<<'CONF'
# Basic auth — create the file first: htpasswd -c /etc/apache2/.htpasswd <user>
<Location "/">
    AuthType Basic
    AuthName "Restricted"
    AuthUserFile /etc/apache2/.htpasswd
    Require valid-user
</Location>
CONF,
    ],
    'apache-rate-limit' => [
        'name' => 'Bandwidth rate limit',
        'description' => 'Throttle output bandwidth (mod_ratelimit, KB/s).',
        'webservers' => ['apache'],
        'content' => <<<'CONF'
# Output bandwidth limit (mod_ratelimit), in KB/s
<Location "/">
    SetOutputFilter RATE_LIMIT
    SetEnv rate-limit 400
</Location>
CONF,
    ],
];
