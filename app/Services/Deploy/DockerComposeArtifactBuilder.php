<?php

namespace App\Services\Deploy;

use App\Models\Site;
use Illuminate\Support\Str;

final class DockerComposeArtifactBuilder
{
    public function build(Site $site): string
    {
        $service = Str::slug($site->slug ?: $site->name ?: 'site', '-');
        $port = $site->type?->value === 'node'
            ? (int) ($site->app_port ?: 3000)
            : 8080;

        return <<<YAML
services:
  {$service}:
    build:
      context: .
      dockerfile: Dockerfile.dply
    restart: unless-stopped
    ports:
      - "80:{$port}"
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
YAML;
    }
}
