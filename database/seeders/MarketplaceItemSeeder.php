<?php

namespace Database\Seeders;

use App\Modules\Marketplace\Models\MarketplaceItem;
use Illuminate\Database\Seeder;

class MarketplaceItemSeeder extends Seeder
{
    public function run(): void
    {
        $banner = '# Dply webserver template — do not remove';

        $items = [
            [
                'slug' => 'nginx-laravel-php',
                'name' => 'Laravel (PHP-FPM)',
                'summary' => __('Nginx :code block with PHP-FPM socket, try_files, and security headers-friendly structure.', ['code' => 'server']),
                'category' => MarketplaceItem::CATEGORY_WEBSERVER,
                'recipe_type' => MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE,
                'payload' => [
                    'label' => 'Laravel (PHP-FPM)',
                    'content' => <<<NGINX
{$banner}
server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};
    root /home/{SYSTEM_USER}/{DOMAIN}{DIRECTORY};
    index index.php index.html;
    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{SOCKET};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
                    ,
                ],
                'sort_order' => 10,
            ],
            [
                'slug' => 'nginx-static-spa',
                'name' => 'Static / SPA',
                'summary' => __('Serve static files or a built SPA with a fallback to :code.', ['code' => 'index.html']),
                'category' => MarketplaceItem::CATEGORY_WEBSERVER,
                'recipe_type' => MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE,
                'payload' => [
                    'label' => 'Static / SPA',
                    'content' => <<<NGINX
{$banner}
server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};
    root /home/{SYSTEM_USER}/{DOMAIN}{DIRECTORY};
    index index.html;

    location / {
        try_files \$uri \$uri/ /index.html;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
                    ,
                ],
                'sort_order' => 20,
            ],
            [
                'slug' => 'nginx-node-reverse-proxy',
                'name' => 'Node (reverse proxy)',
                'summary' => __('Proxy to a Node process on localhost; set :code on your site in Dply.', ['code' => 'app_port']),
                'category' => MarketplaceItem::CATEGORY_WEBSERVER,
                'recipe_type' => MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE,
                'payload' => [
                    'label' => 'Node (reverse proxy)',
                    'content' => <<<NGINX
{$banner}
server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX
                    ,
                ],
                'sort_order' => 30,
            ],
            [
                'slug' => 'deploy-laravel',
                'name' => 'Laravel deploy (git + composer + migrate)',
                'summary' => __('Typical post-receive deploy for a Laravel app on the server.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && git pull origin main && composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache',
                    'mode' => 'replace',
                ],
                'sort_order' => 40,
            ],
            [
                'slug' => 'deploy-node-npm',
                'name' => 'Node deploy (npm ci + build)',
                'summary' => __('Install dependencies and build front-end assets.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && git pull && npm ci && npm run build',
                    'mode' => 'replace',
                ],
                'sort_order' => 50,
            ],
            [
                'slug' => 'deploy-static-git',
                'name' => 'Static site (git pull)',
                'summary' => __('Pull latest static assets only.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && git pull',
                    'mode' => 'replace',
                ],
                'sort_order' => 60,
            ],
            [
                'slug' => 'server-disk-usage-summary',
                'name' => 'Disk usage summary',
                'summary' => __('A server-local diagnostic runbook for filesystem usage and large app directories.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_SERVER_RECIPE,
                'payload' => [
                    'name' => 'Disk usage summary',
                    'script' => <<<'SH'
#!/bin/bash
set -euo pipefail
df -hT
echo "---"
du -sh /var/www/* 2>/dev/null || true
SH,
                ],
                'sort_order' => 65,
            ],
            [
                'slug' => 'server-nginx-test-and-reload',
                'name' => 'Nginx: test config and reload',
                'summary' => __('A server-local maintenance command for validating nginx config before reloading it.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_SERVER_RECIPE,
                'payload' => [
                    'name' => 'Nginx: test config and reload',
                    'script' => <<<'SH'
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
                'sort_order' => 66,
            ],
            [
                'slug' => 'server-php-version-and-modules',
                'name' => 'PHP version and loaded extensions',
                'summary' => __('A server-local check for the active PHP runtime and loaded modules.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_SERVER_RECIPE,
                'payload' => [
                    'name' => 'PHP version and loaded extensions',
                    'script' => <<<'SH'
#!/bin/bash
set -euo pipefail
php -v
echo "---"
php -m | sort
SH,
                ],
                'sort_order' => 67,
            ],
            [
                'slug' => 'guide-first-server',
                'name' => 'Create your first server',
                'summary' => __('Connect a provider and provision a VPS from the docs.', []),
                'category' => MarketplaceItem::CATEGORY_GUIDES,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/docs/create-first-server',
                    'open_new_tab' => false,
                ],
                'sort_order' => 70,
            ],
            [
                'slug' => 'guide-source-control',
                'name' => 'Source control & deploys',
                'summary' => __('Wire Git remotes and webhooks for BYO deploys.', []),
                'category' => MarketplaceItem::CATEGORY_GUIDES,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/docs/source-control',
                    'open_new_tab' => false,
                ],
                'sort_order' => 80,
            ],
            [
                'slug' => 'integration-slack-webhook',
                'name' => 'Slack (notification channel)',
                'summary' => __('Use organization notification channels to post deploy and alert events to Slack.', []),
                'category' => MarketplaceItem::CATEGORY_INTEGRATIONS,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/settings/profile',
                    'open_new_tab' => false,
                    'hint' => __('Open Settings → Notification channels in your organization.'),
                ],
                'sort_order' => 90,
            ],
            [
                'slug' => 'nginx-wordpress-php',
                'name' => 'WordPress (PHP-FPM)',
                'summary' => __('Nginx :code for WordPress with PHP-FPM and pretty permalinks.', ['code' => 'try_files']),
                'category' => MarketplaceItem::CATEGORY_WEBSERVER,
                'recipe_type' => MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE,
                'payload' => [
                    'label' => 'WordPress (PHP-FPM)',
                    'content' => <<<NGINX
{$banner}
server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};
    root /home/{SYSTEM_USER}/{DOMAIN}{DIRECTORY};
    index index.php index.html;
    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{SOCKET};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
                    ,
                ],
                'sort_order' => 100,
            ],
            [
                'slug' => 'nginx-redirect-http-to-https',
                'name' => 'HTTP → HTTPS redirect',
                'summary' => __('Listen on port 80 and 301 redirect to HTTPS (use with a separate TLS server block).', []),
                'category' => MarketplaceItem::CATEGORY_WEBSERVER,
                'recipe_type' => MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE,
                'payload' => [
                    'label' => 'HTTP → HTTPS redirect',
                    'content' => <<<NGINX
{$banner}
server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};
    return 301 https://\$host\$request_uri;
}
NGINX
                    ,
                ],
                'sort_order' => 110,
            ],
            [
                'slug' => 'nginx-php-generic',
                'name' => 'Generic PHP (PHP-FPM)',
                'summary' => __('Simple :code + PHP-FPM without Laravel-style front controller routing.', ['code' => 'try_files']),
                'category' => MarketplaceItem::CATEGORY_WEBSERVER,
                'recipe_type' => MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE,
                'payload' => [
                    'label' => 'Generic PHP (PHP-FPM)',
                    'content' => <<<NGINX
{$banner}
server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};
    root /home/{SYSTEM_USER}/{DOMAIN}{DIRECTORY};
    index index.php index.html;
    charset utf-8;

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{SOCKET};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
                    ,
                ],
                'sort_order' => 120,
            ],
            [
                'slug' => 'deploy-rails',
                'name' => 'Rails deploy (bundle + migrate + assets)',
                'summary' => __('Typical Capistrano-style steps for a Rails app on the server.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && git pull && bundle install && bundle exec rails db:migrate RAILS_ENV=production && bundle exec rails assets:precompile RAILS_ENV=production',
                    'mode' => 'replace',
                ],
                'sort_order' => 130,
            ],
            [
                'slug' => 'deploy-composer-only',
                'name' => 'Composer install (production)',
                'summary' => __('Run :code with optimized autoloader in the deploy directory.', ['code' => 'composer install']),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && composer install --no-dev --optimize-autoloader --no-interaction',
                    'mode' => 'append',
                ],
                'sort_order' => 140,
            ],
            [
                'slug' => 'deploy-yarn-build',
                'name' => 'Yarn install + build',
                'summary' => __('Pull latest, install JS deps, and run the build script.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && git pull && yarn install --frozen-lockfile && yarn build',
                    'mode' => 'replace',
                ],
                'sort_order' => 150,
            ],
            [
                'slug' => 'deploy-npm-build-prod',
                'name' => 'npm ci + production build',
                'summary' => __('Pull latest, run npm ci, then npm run build for reproducible installs.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && git pull && npm ci && npm run build',
                    'mode' => 'replace',
                ],
                'sort_order' => 160,
            ],
            [
                'slug' => 'guide-connect-provider',
                'name' => 'Connect a server provider',
                'summary' => __('Link API tokens or OAuth for DigitalOcean and other providers.', []),
                'category' => MarketplaceItem::CATEGORY_GUIDES,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/docs/connect-provider',
                    'open_new_tab' => false,
                ],
                'sort_order' => 170,
            ],
            [
                'slug' => 'guide-org-roles-limits',
                'name' => 'Organization roles and plan limits',
                'summary' => __('How membership roles and subscription limits apply across servers and sites.', []),
                'category' => MarketplaceItem::CATEGORY_GUIDES,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/docs/org-roles-and-limits',
                    'open_new_tab' => false,
                ],
                'sort_order' => 180,
            ],
            // —— Additional catalog (50 total recipes) ——
            [
                'slug' => 'nginx-symfony-php',
                'name' => 'Symfony (PHP-FPM, front controller)',
                'summary' => __('Nginx :code routing to :file for Symfony-style apps.', ['code' => 'try_files', 'file' => 'index.php']),
                'category' => MarketplaceItem::CATEGORY_WEBSERVER,
                'recipe_type' => MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE,
                'payload' => [
                    'label' => 'Symfony (PHP-FPM)',
                    'content' => <<<NGINX
{$banner}
server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};
    root /home/{SYSTEM_USER}/{DOMAIN}{DIRECTORY}/public;
    index index.php;
    charset utf-8;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{SOCKET};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
                    ,
                ],
                'sort_order' => 190,
            ],
            [
                'slug' => 'nginx-upload-large-bodies',
                'name' => 'PHP-FPM with large upload limit',
                'summary' => __('Raises client_max_body_size in Nginx; match upload_max_filesize in PHP.', []),
                'category' => MarketplaceItem::CATEGORY_WEBSERVER,
                'recipe_type' => MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE,
                'payload' => [
                    'label' => 'Large uploads (100M)',
                    'content' => <<<NGINX
{$banner}
server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};
    root /home/{SYSTEM_USER}/{DOMAIN}{DIRECTORY};
    index index.php index.html;
    client_max_body_size 100M;
    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{SOCKET};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
                    ,
                ],
                'sort_order' => 200,
            ],
            [
                'slug' => 'nginx-gunicorn-django',
                'name' => 'Django (Gunicorn HTTP)',
                'summary' => __('Reverse proxy to Gunicorn on :addr; serves static from :path if you collectstatic there.', ['addr' => '127.0.0.1:8000', 'path' => 'static/']),
                'category' => MarketplaceItem::CATEGORY_WEBSERVER,
                'recipe_type' => MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE,
                'payload' => [
                    'label' => 'Django (Gunicorn)',
                    'content' => <<<NGINX
{$banner}
server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};

    location /static/ {
        alias /home/{SYSTEM_USER}/{DOMAIN}{DIRECTORY}/static/;
    }

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX
                    ,
                ],
                'sort_order' => 210,
            ],
            [
                'slug' => 'nginx-node-websocket',
                'name' => 'Node with WebSocket upgrade',
                'summary' => __('Proxy to Node with :code headers for Socket.IO-style apps.', ['code' => 'Upgrade']),
                'category' => MarketplaceItem::CATEGORY_WEBSERVER,
                'recipe_type' => MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE,
                'payload' => [
                    'label' => 'Node + WebSocket',
                    'content' => <<<NGINX
{$banner}
map \$http_upgrade \$connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \$connection_upgrade;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX
                    ,
                ],
                'sort_order' => 220,
            ],
            [
                'slug' => 'nginx-node-long-timeout',
                'name' => 'Node proxy (long read timeout)',
                'summary' => __('For slow SSR or long-polling; extends proxy read/send timeouts.', []),
                'category' => MarketplaceItem::CATEGORY_WEBSERVER,
                'recipe_type' => MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE,
                'payload' => [
                    'label' => 'Node (long timeouts)',
                    'content' => <<<NGINX
{$banner}
server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX
                    ,
                ],
                'sort_order' => 230,
            ],
            [
                'slug' => 'nginx-acme-http-01',
                'name' => 'ACME HTTP-01 (webroot)',
                'summary' => __('Serves :code for Let’s Encrypt HTTP validation.', ['code' => '.well-known/acme-challenge']),
                'category' => MarketplaceItem::CATEGORY_WEBSERVER,
                'recipe_type' => MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE,
                'payload' => [
                    'label' => 'ACME HTTP-01 webroot',
                    'content' => <<<NGINX
{$banner}
server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};
    root /home/{SYSTEM_USER}/{DOMAIN}{DIRECTORY};

    location /.well-known/acme-challenge/ {
        default_type "text/plain";
        try_files \$uri =404;
    }

    location / {
        return 404;
    }
}
NGINX
                    ,
                ],
                'sort_order' => 240,
            ],
            [
                'slug' => 'deploy-symfony-prod',
                'name' => 'Symfony deploy (composer + cache)',
                'summary' => __('Install deps, warm Symfony cache for production.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && git pull && composer install --no-dev --optimize-autoloader --no-interaction && php bin/console cache:clear --env=prod --no-warmup && php bin/console cache:warmup --env=prod',
                    'mode' => 'replace',
                ],
                'sort_order' => 250,
            ],
            [
                'slug' => 'deploy-django-prod',
                'name' => 'Django deploy (migrate + collectstatic)',
                'summary' => __('Pull code, install requirements, migrate, collect static assets.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && git pull && source .venv/bin/activate 2>/dev/null || true && pip install -r requirements.txt && python manage.py migrate --noinput && python manage.py collectstatic --noinput',
                    'mode' => 'replace',
                ],
                'sort_order' => 260,
            ],
            [
                'slug' => 'deploy-git-pull-only',
                'name' => 'Git pull only',
                'summary' => __('Fetch and merge the current branch; no build step.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && git pull --ff-only',
                    'mode' => 'replace',
                ],
                'sort_order' => 270,
            ],
            [
                'slug' => 'deploy-git-pull-submodules',
                'name' => 'Git pull with submodules',
                'summary' => __('Updates nested Git submodules after pull.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && git pull && git submodule update --init --recursive',
                    'mode' => 'replace',
                ],
                'sort_order' => 280,
            ],
            [
                'slug' => 'deploy-composer-no-scripts',
                'name' => 'Composer install (no scripts)',
                'summary' => __('Safer on locked-down servers where post-install scripts fail.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && composer install --no-dev --no-scripts --no-interaction',
                    'mode' => 'append',
                ],
                'sort_order' => 290,
            ],
            [
                'slug' => 'deploy-laravel-migrate-only',
                'name' => 'Laravel migrate only',
                'summary' => __('Runs :code only; adjust :path to your app root.', ['code' => 'php artisan migrate --force', 'path' => '/var/www']),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && php artisan migrate --force',
                    'mode' => 'append',
                ],
                'sort_order' => 300,
            ],
            [
                'slug' => 'deploy-laravel-storage-link',
                'name' => 'Laravel storage:link',
                'summary' => __('Creates the public storage symlink for uploaded files.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && php artisan storage:link',
                    'mode' => 'append',
                ],
                'sort_order' => 310,
            ],
            [
                'slug' => 'deploy-laravel-queue-restart',
                'name' => 'Laravel queue:restart',
                'summary' => __('Signals Horizon/queue workers to restart after deploy.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && php artisan queue:restart',
                    'mode' => 'append',
                ],
                'sort_order' => 320,
            ],
            [
                'slug' => 'deploy-laravel-octane-reload',
                'name' => 'Laravel Octane reload',
                'summary' => __('Reload Octane workers after code deploy (requires Octane + process manager).', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && php artisan octane:reload',
                    'mode' => 'append',
                ],
                'sort_order' => 330,
            ],
            [
                'slug' => 'deploy-npm-run-prod',
                'name' => 'npm run production',
                'summary' => __('Runs the :code script after install (Laravel Mix / similar).', ['code' => 'production']),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && npm ci && npm run production',
                    'mode' => 'replace',
                ],
                'sort_order' => 340,
            ],
            [
                'slug' => 'deploy-pnpm-ci-build',
                'name' => 'pnpm install + build',
                'summary' => __('Frozen lockfile install then :code.', ['code' => 'pnpm run build']),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && git pull && pnpm install --frozen-lockfile && pnpm run build',
                    'mode' => 'replace',
                ],
                'sort_order' => 350,
            ],
            [
                'slug' => 'deploy-rails-db-migrate-only',
                'name' => 'Rails db:migrate only',
                'summary' => __('Runs database migrations in production only.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && bundle exec rails db:migrate RAILS_ENV=production',
                    'mode' => 'append',
                ],
                'sort_order' => 360,
            ],
            [
                'slug' => 'deploy-rails-assets-precompile-only',
                'name' => 'Rails assets:precompile only',
                'summary' => __('Rebuilds the asset pipeline without a full bundle update.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && bundle exec rails assets:precompile RAILS_ENV=production',
                    'mode' => 'append',
                ],
                'sort_order' => 370,
            ],
            [
                'slug' => 'deploy-docker-compose-rebuild',
                'name' => 'Docker Compose pull + up',
                'summary' => __('Rebuilds and restarts your Docker Compose stack in the deploy directory.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'cd /var/www && docker compose pull && docker compose up -d --build',
                    'mode' => 'replace',
                ],
                'sort_order' => 380,
            ],
            [
                'slug' => 'deploy-php-fpm-reload',
                'name' => 'Reload PHP-FPM (Debian/Ubuntu)',
                'summary' => __('Reload FPM after deploy; adjust service name for your PHP version.', []),
                'category' => MarketplaceItem::CATEGORY_SERVERS,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'sudo systemctl reload php8.3-fpm',
                    'mode' => 'append',
                ],
                'sort_order' => 390,
            ],
            [
                'slug' => 'guide-docs-home',
                'name' => 'Documentation home',
                'summary' => __('Browse BYO setup, providers, and deploy topics.', []),
                'category' => MarketplaceItem::CATEGORY_GUIDES,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/docs',
                    'open_new_tab' => false,
                ],
                'sort_order' => 400,
            ],
            [
                'slug' => 'guide-profile-ssh-keys',
                'name' => 'Profile: SSH keys',
                'summary' => __('Add keys for deploy and optional provision-on-new-server.', []),
                'category' => MarketplaceItem::CATEGORY_GUIDES,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/profile/ssh-keys',
                    'open_new_tab' => false,
                ],
                'sort_order' => 410,
            ],
            [
                'slug' => 'guide-server-providers-credentials',
                'name' => 'Server providers (credentials)',
                'summary' => __('Connect cloud API tokens separate from Git source control.', []),
                'category' => MarketplaceItem::CATEGORY_GUIDES,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/credentials',
                    'open_new_tab' => false,
                ],
                'sort_order' => 420,
            ],
            [
                'slug' => 'guide-script-presets',
                'name' => 'Script presets (clone & run)',
                'summary' => __('Starter shell scripts you can clone and customize per organization.', []),
                'category' => MarketplaceItem::CATEGORY_GUIDES,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/scripts/marketplace',
                    'open_new_tab' => false,
                ],
                'sort_order' => 430,
            ],
            [
                'slug' => 'guide-api-keys',
                'name' => 'HTTP API keys',
                'summary' => __('Organization-scoped tokens for automation and CI.', []),
                'category' => MarketplaceItem::CATEGORY_GUIDES,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/profile/api-keys',
                    'open_new_tab' => false,
                ],
                'sort_order' => 440,
            ],
            [
                'slug' => 'guide-database-backups',
                'name' => 'Database backups',
                'summary' => __('Configure and review database backup jobs for your servers.', []),
                'category' => MarketplaceItem::CATEGORY_GUIDES,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/backups/databases',
                    'open_new_tab' => false,
                ],
                'sort_order' => 450,
            ],
            [
                'slug' => 'site-create-flow',
                'name' => 'Create a site on a server',
                'summary' => __('Start from the servers list, pick a server, then add a site and domains.', []),
                'category' => MarketplaceItem::CATEGORY_SITES,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/servers',
                    'open_new_tab' => false,
                ],
                'sort_order' => 460,
            ],
            [
                'slug' => 'site-env-and-deploy-hooks',
                'name' => 'Site env vars and deploy pipeline',
                'summary' => __('After a site exists, tune environment variables and deploy steps in the site UI.', []),
                'category' => MarketplaceItem::CATEGORY_SITES,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/sites',
                    'open_new_tab' => false,
                ],
                'sort_order' => 470,
            ],
            [
                'slug' => 'integration-discord-webhook',
                'name' => 'Discord (notification channel)',
                'summary' => __('Route deploy and alert events to Discord via a webhook.', []),
                'category' => MarketplaceItem::CATEGORY_INTEGRATIONS,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/profile/notification-channels',
                    'open_new_tab' => false,
                    'hint' => __('Create a Discord webhook channel under Profile or Organization notification settings.'),
                ],
                'sort_order' => 480,
            ],
            [
                'slug' => 'integration-telegram-bot',
                'name' => 'Telegram (notification channel)',
                'summary' => __('Send alerts through Telegram using a bot token and chat id.', []),
                'category' => MarketplaceItem::CATEGORY_INTEGRATIONS,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/profile/notification-channels',
                    'open_new_tab' => false,
                    'hint' => __('Add a Telegram channel in notification settings.'),
                ],
                'sort_order' => 490,
            ],
            [
                'slug' => 'integration-http-webhook-generic',
                'name' => 'Generic HTTP webhook',
                'summary' => __('POST JSON payloads to any HTTPS endpoint for custom automation.', []),
                'category' => MarketplaceItem::CATEGORY_INTEGRATIONS,
                'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
                'payload' => [
                    'url' => '/profile/notification-channels',
                    'open_new_tab' => false,
                    'hint' => __('Use the HTTP webhook channel type with your receiver URL and optional secret.'),
                ],
                'sort_order' => 500,
            ],
        ];

        // Runtime / framework tags applied per slug so the marketplace can
        // filter recipes by the site or server's runtime context. Items
        // missing from this map are universal (no runtime tag) — guides,
        // notification integrations, generic NGINX snippets, etc.
        $runtimeTags = [
            // PHP / Laravel
            'nginx-laravel-php' => ['runtimes' => ['php'], 'frameworks' => ['laravel']],
            'deploy-laravel' => ['runtimes' => ['php'], 'frameworks' => ['laravel']],
            'deploy-laravel-migrate-only' => ['runtimes' => ['php'], 'frameworks' => ['laravel']],
            'deploy-laravel-storage-link' => ['runtimes' => ['php'], 'frameworks' => ['laravel']],
            'deploy-laravel-queue-restart' => ['runtimes' => ['php'], 'frameworks' => ['laravel']],
            'deploy-laravel-octane-reload' => ['runtimes' => ['php'], 'frameworks' => ['laravel']],
            // PHP / Symfony
            'nginx-symfony-php' => ['runtimes' => ['php'], 'frameworks' => ['symfony']],
            'deploy-symfony-prod' => ['runtimes' => ['php'], 'frameworks' => ['symfony']],
            // PHP / WordPress
            'nginx-wordpress-php' => ['runtimes' => ['php'], 'frameworks' => ['wordpress']],
            // PHP / generic
            'nginx-php-generic' => ['runtimes' => ['php']],
            'deploy-composer-only' => ['runtimes' => ['php']],
            'deploy-composer-no-scripts' => ['runtimes' => ['php']],
            'deploy-php-fpm-reload' => ['runtimes' => ['php']],
            'server-php-version-and-modules' => ['runtimes' => ['php']],
            // Node
            'nginx-node-reverse-proxy' => ['runtimes' => ['node']],
            'nginx-node-websocket' => ['runtimes' => ['node']],
            'nginx-node-long-timeout' => ['runtimes' => ['node']],
            'deploy-node-npm' => ['runtimes' => ['node']],
            'deploy-npm-build-prod' => ['runtimes' => ['node']],
            'deploy-npm-run-prod' => ['runtimes' => ['node']],
            'deploy-pnpm-ci-build' => ['runtimes' => ['node']],
            'deploy-yarn-build' => ['runtimes' => ['node']],
            // Python / Django
            'nginx-gunicorn-django' => ['runtimes' => ['python'], 'frameworks' => ['django']],
            'deploy-django-prod' => ['runtimes' => ['python'], 'frameworks' => ['django']],
            // Ruby / Rails
            'deploy-rails' => ['runtimes' => ['ruby'], 'frameworks' => ['rails']],
            'deploy-rails-db-migrate-only' => ['runtimes' => ['ruby'], 'frameworks' => ['rails']],
            'deploy-rails-assets-precompile-only' => ['runtimes' => ['ruby'], 'frameworks' => ['rails']],
            // Static
            'nginx-static-spa' => ['runtimes' => ['static']],
            'deploy-static-git' => ['runtimes' => ['static']],
        ];

        // Curated v1 set of non-PHP worker / scheduler recipes. The detector
        // layer pre-creates SiteProcess rows for the common ones; these
        // marketplace items make it easy for a user to copy the recipe into a
        // custom process row when they have a non-standard layout.
        $nonPhpProcessRecipes = [
            [
                'slug' => 'process-node-bullmq-worker',
                'name' => 'BullMQ worker (Node)',
                'summary' => __('Run a BullMQ / Bull background queue worker as a SiteProcess; pair with Redis.', []),
                'category' => MarketplaceItem::CATEGORY_SITES,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'npm run worker',
                    'mode' => 'append',
                    'process_type' => 'worker',
                    'process_name' => 'worker',
                ],
                'runtimes' => ['node'],
                'sort_order' => 600,
            ],
            [
                'slug' => 'process-python-celery-worker',
                'name' => 'Celery worker (Python)',
                'summary' => __('Background task worker for Django / FastAPI / Flask apps using Celery.', []),
                'category' => MarketplaceItem::CATEGORY_SITES,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'celery -A <project> worker --loglevel=info',
                    'mode' => 'append',
                    'process_type' => 'worker',
                    'process_name' => 'celery',
                ],
                'runtimes' => ['python'],
                'sort_order' => 610,
            ],
            [
                'slug' => 'process-python-celery-beat',
                'name' => 'Celery beat scheduler (Python)',
                'summary' => __('Periodic-task scheduler that fires Celery jobs on a schedule.', []),
                'category' => MarketplaceItem::CATEGORY_SITES,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'celery -A <project> beat --loglevel=info',
                    'mode' => 'append',
                    'process_type' => 'scheduler',
                    'process_name' => 'celery-beat',
                ],
                'runtimes' => ['python'],
                'sort_order' => 615,
            ],
            [
                'slug' => 'process-ruby-sidekiq',
                'name' => 'Sidekiq worker (Ruby)',
                'summary' => __('Background job processor for Rails apps using the Sidekiq gem and Redis.', []),
                'category' => MarketplaceItem::CATEGORY_SITES,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'bundle exec sidekiq -C config/sidekiq.yml',
                    'mode' => 'append',
                    'process_type' => 'worker',
                    'process_name' => 'sidekiq',
                ],
                'runtimes' => ['ruby'],
                'frameworks' => ['rails'],
                'sort_order' => 620,
            ],
            [
                'slug' => 'process-laravel-horizon',
                'name' => 'Laravel Horizon (PHP)',
                'summary' => __('Queue dashboard + worker manager for Laravel apps using Redis queues.', []),
                'category' => MarketplaceItem::CATEGORY_SITES,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'php artisan horizon',
                    'mode' => 'append',
                    'process_type' => 'worker',
                    'process_name' => 'horizon',
                ],
                'runtimes' => ['php'],
                'frameworks' => ['laravel'],
                'sort_order' => 630,
            ],
            [
                'slug' => 'process-laravel-scheduler',
                'name' => 'Laravel scheduler (PHP)',
                'summary' => __('Run :code as a long-running scheduler process (Laravel 11+ replacement for cron).', ['code' => 'php artisan schedule:work']),
                'category' => MarketplaceItem::CATEGORY_SITES,
                'recipe_type' => MarketplaceItem::RECIPE_DEPLOY_COMMAND,
                'payload' => [
                    'command' => 'php artisan schedule:work',
                    'mode' => 'append',
                    'process_type' => 'scheduler',
                    'process_name' => 'scheduler',
                ],
                'runtimes' => ['php'],
                'frameworks' => ['laravel'],
                'sort_order' => 635,
            ],
        ];

        $runbookRecipes = [
            [
                'slug' => 'runbook-deploy-rollback',
                'name' => 'Deploy rollback checklist',
                'summary' => __('BYO atomic deploy rollback — identify the last good release, flip the symlink, and verify health before closing the incident.'),
                'category' => MarketplaceItem::CATEGORY_RUNBOOKS,
                'recipe_type' => MarketplaceItem::RECIPE_WORKSPACE_RUNBOOK,
                'payload' => [
                    'title' => 'Deploy rollback checklist',
                    'body' => implode("\n", [
                        '1. Confirm the bad deploy in Dply deploy history (note commit + release id).',
                        '2. SSH to the server and list releases under the site path.',
                        '3. Point the `current` symlink at the previous release directory.',
                        '4. Reload the webserver config from Dply (or `sudo systemctl reload nginx`).',
                        '5. Hit the health URL and a critical user flow.',
                        '6. Post in the incident channel with rollback commit + operator name.',
                    ]),
                ],
                'sort_order' => 700,
            ],
            [
                'slug' => 'runbook-database-restore',
                'name' => 'Database restore verification',
                'summary' => __('After restoring a SQL dump, verify connections, migrations, and read-only smoke queries before reopening traffic.'),
                'category' => MarketplaceItem::CATEGORY_RUNBOOKS,
                'recipe_type' => MarketplaceItem::RECIPE_WORKSPACE_RUNBOOK,
                'payload' => [
                    'title' => 'Database restore verification',
                    'body' => implode("\n", [
                        '1. Import the dump to the target database (note exact filename + timestamp).',
                        '2. Run application migrations if the dump predates schema changes.',
                        '3. Verify app `.env` database credentials match the restored database.',
                        '4. Run read-only smoke queries (row counts on critical tables).',
                        '5. Clear app caches that embed stale schema or config.',
                        '6. Document restore owner + verification results in the project runbook.',
                    ]),
                ],
                'sort_order' => 710,
            ],
            [
                'slug' => 'runbook-edge-origin-failover',
                'name' => 'Edge hybrid origin failover',
                'summary' => __('When a linked Cloud origin is down, swap or relink the hybrid SSR origin and purge Edge cache.'),
                'category' => MarketplaceItem::CATEGORY_RUNBOOKS,
                'recipe_type' => MarketplaceItem::RECIPE_WORKSPACE_RUNBOOK,
                'payload' => [
                    'title' => 'Edge hybrid origin failover',
                    'body' => implode("\n", [
                        '1. Confirm Edge static assets still serve (Worker/CDN path).',
                        '2. Check linked Cloud app health and recent deploy status.',
                        '3. If origin is bad, promote last good Cloud deploy or link standby origin URL.',
                        '4. Purge Edge cache for affected hostnames after origin swap.',
                        '5. Re-test SSR routes + API paths that fetch from origin.',
                        '6. Note failover time + operator in project activity.',
                    ]),
                ],
                'sort_order' => 720,
            ],
            [
                'slug' => 'runbook-incident-first-15',
                'name' => 'Incident triage — first 15 minutes',
                'summary' => __('Operator checklist for the opening window: scope, comms, rollback decision, and stakeholder ping.'),
                'category' => MarketplaceItem::CATEGORY_RUNBOOKS,
                'recipe_type' => MarketplaceItem::RECIPE_WORKSPACE_RUNBOOK,
                'payload' => [
                    'title' => 'Incident triage — first 15 minutes',
                    'body' => implode("\n", [
                        '1. Assign incident commander + scribe.',
                        '2. Capture customer impact scope (which sites/products/regions).',
                        '3. Open status page or internal comms channel.',
                        '4. Check recent deploys, cert expiries, and provider status pages.',
                        '5. Decide: rollback, scale, or hotfix — pick one path and owner.',
                        '6. Link relevant Dply workspaces/servers in the incident doc.',
                    ]),
                ],
                'sort_order' => 730,
            ],
            [
                'slug' => 'runbook-ssl-renewal-panic',
                'name' => 'SSL certificate renewal panic',
                'summary' => __('Certificate expired or ACME failed — renew, apply webserver config, and confirm browser trust.'),
                'category' => MarketplaceItem::CATEGORY_RUNBOOKS,
                'recipe_type' => MarketplaceItem::RECIPE_WORKSPACE_RUNBOOK,
                'payload' => [
                    'title' => 'SSL certificate renewal panic',
                    'body' => implode("\n", [
                        '1. Confirm which hostname(s) fail TLS (browser + `openssl s_client`).',
                        '2. In Dply → site → Certificates, retry issuance or upload replacement.',
                        '3. Queue webserver config apply and watch for reload errors.',
                        '4. Verify HTTPS on apex + www/preview aliases.',
                        '5. If DNS-01, confirm challenge records still exist at provider.',
                        '6. Add calendar reminder 14 days before next expiry.',
                    ]),
                ],
                'sort_order' => 740,
            ],
        ];

        foreach ($runbookRecipes as $row) {
            $items[] = $row;
        }

        foreach ($nonPhpProcessRecipes as $row) {
            $items[] = $row;
        }

        foreach ($items as $row) {
            $row['is_active'] = true;
            $tags = $runtimeTags[$row['slug']] ?? [];
            $row['runtimes'] = $row['runtimes'] ?? ($tags['runtimes'] ?? null);
            $row['frameworks'] = $row['frameworks'] ?? ($tags['frameworks'] ?? null);

            MarketplaceItem::query()->updateOrCreate(
                ['slug' => $row['slug']],
                $row
            );
        }
    }
}
