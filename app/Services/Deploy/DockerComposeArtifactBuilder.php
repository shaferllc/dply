<?php

namespace App\Services\Deploy;

use App\Models\Site;
use Illuminate\Support\Str;

final class DockerComposeArtifactBuilder
{
    public function __construct(
        private readonly DeploymentContractBuilder $contractBuilder,
    ) {}

    public function build(Site $site): string
    {
        $contract = $this->contractBuilder->build($site);
        $service = Str::slug($site->slug ?: $site->name ?: 'site', '-');
        $publishedPort = (int) data_get($site->meta, 'runtime_target.publication.port', 80);
        $port = $site->type?->value === 'node'
            ? (int) ($site->app_port ?: 3000)
            : 80;
        $environment = $contract->environmentMap();
        ksort($environment);
        $environmentYaml = collect($environment)
            ->map(fn (string $value, string $key): string => '      '.$key.': '.$this->yamlScalar($value))
            ->implode("\n");

        return <<<YAML
services:
  {$service}:
    build:
      context: .
      dockerfile: Dockerfile.dply
    restart: unless-stopped
    ports:
      - "{$publishedPort}:{$port}"
    environment:
{$environmentYaml}
YAML;
    }

    private function yamlScalar(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
    }
}
