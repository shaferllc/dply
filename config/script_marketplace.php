<?php

/**
 * Preset scripts users can clone into their organization and edit.
 *
 * Keys are stable IDs; names and bodies can be updated between releases.
 */
return [
    'ubuntu-apt-update' => [
        'name' => 'APT update (Ubuntu)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get upgrade -y
echo "APT update finished."
SH,
    ],
    'php-composer-self-update' => [
        'name' => 'Composer self-update',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v composer >/dev/null 2>&1; then
  composer self-update --no-interaction
  echo "Composer updated."
else
  echo "composer not found in PATH" >&2
  exit 1
fi
SH,
    ],
    'disk-usage-summary' => [
        'name' => 'Disk usage summary',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
df -hT
echo "---"
du -sh /var/www/* 2>/dev/null || true
SH,
    ],
    'memory-and-load' => [
        'name' => 'Memory, load, and uptime',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
uptime
echo "---"
free -h 2>/dev/null || vm_stat
echo "---"
ps aux --sort=-%mem | head -n 15
SH,
    ],
    'listening-ports' => [
        'name' => 'Listening TCP ports (ss)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v ss >/dev/null 2>&1; then
  ss -tulpn
else
  netstat -tulpn 2>/dev/null || netstat -tuln
fi
SH,
    ],
    'systemd-failed-units' => [
        'name' => 'Systemd failed units',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
systemctl --failed --no-pager || true
SH,
    ],
    'nginx-test-and-reload' => [
        'name' => 'Nginx: test config and reload',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
nginx -t
if command -v systemctl >/dev/null 2>&1; then
  systemctl reload nginx
else
  service nginx reload
fi
echo "Nginx config OK and reload requested."
SH,
    ],
    'certbot-certificates' => [
        'name' => 'Certbot: list certificates',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v certbot >/dev/null 2>&1; then
  certbot certificates
else
  echo "certbot not installed" >&2
  exit 1
fi
SH,
    ],
    'certbot-renew-dry-run' => [
        'name' => 'Certbot: renew dry run',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
certbot renew --dry-run
SH,
    ],
    'redis-ping' => [
        'name' => 'Redis: PING',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v redis-cli >/dev/null 2>&1; then
  redis-cli PING
else
  echo "redis-cli not found" >&2
  exit 1
fi
SH,
    ],
    'php-and-extensions' => [
        'name' => 'PHP version and loaded extensions',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
php -v
echo "---"
php -m | sort
SH,
    ],
    'laravel-artisan-about' => [
        'name' => 'Laravel: php artisan about',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan about
SH,
    ],
    'laravel-clear-caches' => [
        'name' => 'Laravel: clear caches (no optimize)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
echo "Laravel caches cleared."
SH,
    ],
    'laravel-optimize' => [
        'name' => 'Laravel: config, route, and view cache',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "Laravel optimize complete."
SH,
    ],
    'laravel-down-maintenance' => [
        'name' => 'Laravel: enable maintenance mode',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan down --render="errors::503" || php artisan down
echo "Maintenance mode on."
SH,
    ],
    'laravel-up-maintenance' => [
        'name' => 'Laravel: disable maintenance mode',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan up
echo "Maintenance mode off."
SH,
    ],
    'laravel-queue-restart' => [
        'name' => 'Laravel: queue workers restart signal',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan queue:restart
echo "Sent queue:restart (workers should recycle)."
SH,
    ],
    'laravel-horizon-terminate' => [
        'name' => 'Laravel Horizon: terminate',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan horizon:terminate
echo "Horizon terminate sent."
SH,
    ],
    'laravel-storage-permissions' => [
        'name' => 'Laravel: fix storage & bootstrap/cache perms (www-data)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
echo "Permissions updated for www-data."
SH,
    ],
    'git-fetch-prune' => [
        'name' => 'Git: fetch and prune remotes',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
REPO_DIR="${REPO_DIR:-/var/www}"
cd "$REPO_DIR" || exit 1
git fetch --all --prune
git status -sb
SH,
    ],
    'docker-ps' => [
        'name' => 'Docker: running containers',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v docker >/dev/null 2>&1; then
  docker ps
else
  echo "docker not found" >&2
  exit 1
fi
SH,
    ],
    'docker-compose-pull' => [
        'name' => 'Docker Compose: pull images',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
COMPOSE_DIR="${COMPOSE_DIR:-/var/www}"
cd "$COMPOSE_DIR" || exit 1
if docker compose version >/dev/null 2>&1; then
  docker compose pull
elif command -v docker-compose >/dev/null 2>&1; then
  docker-compose pull
else
  echo "docker compose not available" >&2
  exit 1
fi
SH,
    ],
    'log-tail-nginx-error' => [
        'name' => 'Tail nginx error log (last 80 lines)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
for f in /var/log/nginx/error.log /var/log/nginx/error.log.1; do
  if [[ -f "$f" ]]; then
    echo "=== $f ==="
    tail -n 80 "$f"
    exit 0
  fi
done
echo "No nginx error log found in /var/log/nginx/" >&2
exit 1
SH,
    ],
    'inode-usage' => [
        'name' => 'Disk inode usage',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
df -ih
SH,
    ],
];
