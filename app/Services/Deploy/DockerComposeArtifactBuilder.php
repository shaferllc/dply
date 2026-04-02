<?php

namespace App\Services\Deploy;

use App\Models\Site;
use App\Services\Sites\SiteDotEnvComposer;
use Illuminate\Support\Str;

final class DockerComposeArtifactBuilder
{
    public function __construct(
        private readonly SiteDotEnvComposer $dotEnvComposer,
    ) {}

    public function build(Site $site): string
    {
        $service = Str::slug($site->slug ?: $site->name ?: 'site', '-');
        $publishedPort = (int) data_get($site->meta, 'runtime_target.publication.port', 80);
        $port = $site->type?->value === 'node'
            ? (int) ($site->app_port ?: 3000)
            : 80;
        $environment = array_merge([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
        ], $this->laravelRuntimeDefaults($site), $this->dotEnvComposer->composeMap($site));
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

    /**
     * @return array<string, string>
     */
    private function laravelRuntimeDefaults(Site $site): array
    {
        if ((string) data_get($site->meta, 'docker_runtime.detected.framework') !== 'laravel') {
            return [];
        }

        return [
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
        ];
    }

    private function yamlScalar(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
    }
}
