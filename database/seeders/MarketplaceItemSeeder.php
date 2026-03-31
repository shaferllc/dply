<?php

namespace Database\Seeders;

use App\Models\MarketplaceItem;
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
                    'url' => '/settings',
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

        foreach ($items as $row) {
            $row['is_active'] = true;

            MarketplaceItem::query()->updateOrCreate(
                ['slug' => $row['slug']],
                $row
            );
        }
    }
}
