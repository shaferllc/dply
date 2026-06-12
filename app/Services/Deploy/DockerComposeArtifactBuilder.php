<?php

namespace App\Services\Deploy;

use App\Models\Site;
use Illuminate\Support\Str;

final class DockerComposeArtifactBuilder
{
    public function __construct(
        private readonly DeploymentContractBuilder $contractBuilder,
    ) {}

    /**
     * @param  string|null  $imageTag  When set, the service is tagged with this
     *                                 image name so each release is a pinnable
     *                                 artifact (enables image rollback).
     * @param  bool  $withBuild  When false, the `build:` block is omitted so
     *                           compose RUNS an already-built $imageTag instead of
     *                           rebuilding — the rollback path.
     */
    public function build(Site $site, ?string $imageTag = null, bool $withBuild = true): string
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

        // `image:` names the built artifact so a prior release can be re-run by
        // tag; `build:` is dropped on rollback so compose uses the existing image.
        $imageLine = $imageTag !== null ? "    image: {$imageTag}\n" : '';
        $buildBlock = $withBuild
            ? "    build:\n      context: .\n      dockerfile: Dockerfile.dply\n"
            : '';

        return <<<YAML
services:
  {$service}:
{$imageLine}{$buildBlock}    restart: unless-stopped
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
