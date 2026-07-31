#!/usr/bin/env bash
# Atomic release layout + shared/.env stub for web or worker.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
ROOT="${DPLY_APP_ROOT:?}"
RUNTIME="${DPLY_RUNTIME:?}"
WORKER_ROLE="${DPLY_WORKER_ROLE:-}"
APP_KEY="${DPLY_APP_KEY:?}"
APP_URL="${DPLY_APP_URL:-https://dply.io}"
DB_HOST="${DPLY_DB_HOST:?}"
DB_PORT="${DPLY_DB_PORT:-5432}"
DB_NAME="${DPLY_DB_NAME:-dply}"
DB_USER="${DPLY_DB_USER:-dply}"
DB_PASS="${DPLY_DB_PASSWORD:?}"
REDIS_HOST="${DPLY_REDIS_HOST:?}"
REDIS_PORT="${DPLY_REDIS_PORT:-6379}"
REDIS_PASS="${DPLY_REDIS_PASSWORD:?}"

# PHP 8.4 (Ubuntu 24.04) + common extensions
apt-get install -y --no-install-recommends \
  php-cli php-fpm php-pgsql php-redis php-mbstring php-xml php-curl \
  php-zip php-bcmath php-intl php-gd php-sqlite3 unzip

if [[ "${RUNTIME}" == "web" ]]; then
  apt-get install -y --no-install-recommends nginx
fi

if [[ "${RUNTIME}" == "worker" ]]; then
  apt-get install -y --no-install-recommends supervisor docker.io
  usermod -aG docker dply || true
  usermod -aG docker www-data || true
  systemctl enable --now docker
  systemctl enable --now supervisor
fi

install -d -o dply -g dply \
  "${ROOT}" \
  "${ROOT}/shared" \
  "${ROOT}/shared/storage" \
  "${ROOT}/shared/storage/app" \
  "${ROOT}/shared/storage/framework/cache" \
  "${ROOT}/shared/storage/framework/sessions" \
  "${ROOT}/shared/storage/framework/views" \
  "${ROOT}/shared/storage/logs" \
  "${ROOT}/releases" \
  "${ROOT}/repo"

ENV_FILE="${ROOT}/shared/.env"
if [[ ! -f "${ENV_FILE}" ]]; then
  cat >"${ENV_FILE}" <<EOF
APP_NAME=dply
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=${APP_URL}

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}

REDIS_CLIENT=phpredis
REDIS_HOST=${REDIS_HOST}
REDIS_PASSWORD=${REDIS_PASS}
REDIS_PORT=${REDIS_PORT}

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

DPLY_RUNTIME=${RUNTIME}
EOF
  if [[ -n "${WORKER_ROLE}" ]]; then
    {
      echo "DPLY_WORKER_ROLE=${WORKER_ROLE}"
      echo "HORIZON_NAME=dply-worker-1"
    } >>"${ENV_FILE}"
  fi
  chown dply:dply "${ENV_FILE}"
  chmod 640 "${ENV_FILE}"
else
  echo "keeping existing ${ENV_FILE}"
fi

# Placeholder current → empty release so nginx/supervisor paths can be wired pre-deploy
RELEASE="${ROOT}/releases/bootstrap"
install -d -o dply -g dply "${RELEASE}/public"
if [[ ! -e "${ROOT}/current" ]]; then
  ln -sfn "${RELEASE}" "${ROOT}/current"
  chown -h dply:dply "${ROOT}/current"
fi

# Wire shared storage into bootstrap release
ln -sfn "${ROOT}/shared/storage" "${RELEASE}/storage"
ln -sfn "${ROOT}/shared/.env" "${RELEASE}/.env"
chown -h dply:dply "${RELEASE}/storage" "${RELEASE}/.env" || true

if [[ "${RUNTIME}" == "web" ]]; then
  cat >/etc/nginx/sites-available/dply <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root ${ROOT}/current/public;
    index index.php index.html;
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location ~ \\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
NGINX
  ln -sfn /etc/nginx/sites-available/dply /etc/nginx/sites-enabled/dply
  rm -f /etc/nginx/sites-enabled/default
  # point php-fpm sock symlink if versioned
  if [[ ! -S /run/php/php-fpm.sock ]]; then
    SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true)"
    if [[ -n "${SOCK}" ]]; then
      ln -sfn "${SOCK}" /run/php/php-fpm.sock
    fi
  fi
  nginx -t
  systemctl enable --now nginx php*-fpm 2>/dev/null || systemctl enable --now nginx
  systemctl reload nginx || systemctl restart nginx

  ufw --force reset >/dev/null 2>&1 || true
  ufw default deny incoming
  ufw default allow outgoing
  ufw allow 22/tcp
  ufw allow 80/tcp
  ufw allow 443/tcp
  ufw --force enable
fi

if [[ "${RUNTIME}" == "worker" ]]; then
  ufw --force reset >/dev/null 2>&1 || true
  ufw default deny incoming
  ufw default allow outgoing
  ufw allow 22/tcp
  ufw --force enable
fi

echo "app layout bootstrap ok root=${ROOT} runtime=${RUNTIME}"
