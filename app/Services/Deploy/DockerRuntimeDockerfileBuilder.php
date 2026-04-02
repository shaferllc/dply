<?php

namespace App\Services\Deploy;

use App\Enums\SiteType;
use App\Models\Site;

final class DockerRuntimeDockerfileBuilder
{
    public function build(Site $site): string
    {
        return match ($site->type) {
            SiteType::Node => $this->nodeDockerfile($site),
            SiteType::Static => $this->staticDockerfile($site),
            default => $this->phpDockerfile($site),
        };
    }

    private function phpDockerfile(Site $site): string
    {
        $phpVersion = trim((string) ($site->php_version ?: '8.3'));
        $documentRoot = $this->containerDocumentRoot($site);
        $laravelBootstrap = $this->laravelBootstrapInstructions($site);

        return <<<DOCKER
FROM php:{$phpVersion}-apache

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y git unzip zip \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite
RUN sed -ri -e 's!/var/www/html!{$documentRoot}!g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY . /var/www/html

RUN if [ -f composer.json ]; then \
      php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
      php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
      rm composer-setup.php && \
      composer install --no-interaction --prefer-dist; \
    fi

{$laravelBootstrap}

RUN chown -R www-data:www-data /var/www/html
DOCKER;
    }

    private function nodeDockerfile(Site $site): string
    {
        $port = (int) ($site->app_port ?: 3000);

        return <<<DOCKER
FROM node:20-alpine

WORKDIR /app

COPY package*.json ./
RUN if [ -f package-lock.json ]; then npm ci; elif [ -f package.json ]; then npm install; fi

COPY . .

ENV PORT={$port}
EXPOSE {$port}

CMD ["npm", "start"]
DOCKER;
    }

    private function staticDockerfile(Site $site): string
    {
        $framework = (string) data_get($site->meta, 'docker_runtime.detected.framework', '');

        if ($framework === 'vite_static') {
            return <<<'DOCKER'
FROM node:20-alpine AS build

WORKDIR /app

COPY package*.json ./
RUN if [ -f package-lock.json ]; then npm ci; elif [ -f package.json ]; then npm install; fi

COPY . .
RUN npm run build

FROM nginx:alpine

COPY --from=build /app/dist /usr/share/nginx/html
DOCKER;
        }

        return <<<'DOCKER'
FROM nginx:alpine

COPY . /usr/share/nginx/html
DOCKER;
    }

    private function containerDocumentRoot(Site $site): string
    {
        $documentRoot = trim((string) $site->document_root);

        if ($documentRoot === '') {
            return '/var/www/html';
        }

        $relative = preg_replace('#^/var/www/[^/]+#', '', $documentRoot);
        $relative = is_string($relative) ? trim($relative, '/') : '';

        return $relative !== '' ? '/var/www/html/'.$relative : '/var/www/html';
    }

    private function laravelBootstrapInstructions(Site $site): string
    {
        if ((string) data_get($site->meta, 'docker_runtime.detected.framework') !== 'laravel') {
            return '';
        }

        return <<<'DOCKER'
RUN mkdir -p /var/www/html/database \
    && touch /var/www/html/database/database.sqlite
DOCKER;
    }
}
