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
            SiteType::Static => $this->staticDockerfile(),
            default => $this->phpDockerfile($site),
        };
    }

    private function phpDockerfile(Site $site): string
    {
        $phpVersion = trim((string) ($site->php_version ?: '8.3'));

        return <<<DOCKER
FROM php:{$phpVersion}-apache

WORKDIR /var/www/html

RUN a2enmod rewrite

COPY . /var/www/html

RUN if [ -f composer.json ]; then \
      php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
      php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
      rm composer-setup.php && \
      composer install --no-interaction --prefer-dist; \
    fi

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

    private function staticDockerfile(): string
    {
        return <<<'DOCKER'
FROM nginx:alpine

COPY . /usr/share/nginx/html
DOCKER;
    }
}
