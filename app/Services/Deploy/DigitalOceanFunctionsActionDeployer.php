<?php

namespace App\Services\Deploy;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

final class DigitalOceanFunctionsActionDeployer
{
    public function __construct(
        private readonly DigitalOceanFunctionsArtifactBuilder $artifactBuilder,
        private readonly ServerlessDeploymentConfigResolver $deploymentConfigResolver,
    ) {}

    /**
     * @return array{output: string, revision_id: ?string, url: ?string}
     */
    public function deploy(Site $site): array
    {
        $site->loadMissing('server', 'domains');

        $server = $site->server;
        if (! $server instanceof Server || ! $server->isDigitalOceanFunctionsHost()) {
            throw new \RuntimeException('DigitalOcean Functions deploy requires a Functions-backed host.');
        }

        $serverMeta = is_array($server->meta) ? $server->meta : [];
        $hostConfig = is_array($serverMeta['digitalocean_functions'] ?? null) ? $serverMeta['digitalocean_functions'] : [];
        $siteMeta = is_array($site->meta) ? $site->meta : [];
        $resolvedConfig = $this->deploymentConfigResolver->resolve($site);

        $apiHost = rtrim((string) ($hostConfig['api_host'] ?? ''), '/');
        $namespace = trim((string) ($hostConfig['namespace'] ?? ''));
        $accessKey = trim((string) ($hostConfig['access_key'] ?? ''));
        $package = trim((string) ($resolvedConfig['package'] ?? 'default'));
        $kind = trim((string) ($resolvedConfig['runtime'] ?? 'nodejs:18'));
        $entrypoint = trim((string) ($resolvedConfig['entrypoint'] ?? 'index'));

        if ($apiHost === '' || $namespace === '' || $accessKey === '') {
            throw new \RuntimeException('DigitalOcean Functions host metadata is incomplete. Save API host, namespace, and access key first.');
        }

        $buildResult = $this->artifactBuilder->build($site);
        $artifactPath = $buildResult['artifact_path'];

        if (! str_ends_with(strtolower($artifactPath), '.zip')) {
            throw new \RuntimeException('DigitalOcean Functions deploy expects a .zip artifact.');
        }

        $realArtifactPath = realpath($artifactPath);
        if ($realArtifactPath === false || ! is_file($realArtifactPath) || ! is_readable($realArtifactPath)) {
            throw new \RuntimeException('Artifact zip is missing or unreadable: '.$artifactPath);
        }

        [$keyId, $keySecret] = $this->splitAccessKey($accessKey);
        $actionName = $this->actionName($site);
        $url = $this->actionPutUrl($apiHost, $namespace, $package, $actionName);
        $bytes = file_get_contents($realArtifactPath);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Artifact zip is empty or unreadable.');
        }

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->timeout(300)
            ->acceptJson()
            ->put($url.'?overwrite=true', [
                'exec' => [
                    'kind' => $kind,
                    'binary' => true,
                    'code' => base64_encode($bytes),
                    'main' => $entrypoint,
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('DigitalOcean Functions deploy failed: HTTP '.$response->status().' '.$response->body());
        }

        $json = $response->json();
        $revisionId = is_array($json) && isset($json['version']) ? (string) $json['version'] : null;

        $functionsConfig = $site->serverlessConfig();
        $siteMeta['serverless'] = array_merge($functionsConfig, [
            'target' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'package' => $package,
            'runtime' => $kind,
            'entrypoint' => $entrypoint,
            'artifact_path' => $realArtifactPath,
            'action_name' => $actionName,
            'last_deployed_at' => now()->toIso8601String(),
            'last_revision_id' => $revisionId,
            'action_url' => $this->actionWebUrl($apiHost, $namespace, $package, $actionName),
        ]);

        $site->forceFill(['meta' => $siteMeta])->save();

        return [
            'output' => implode("\n", array_filter([
                $buildResult['output'] !== '' ? $buildResult['output'] : null,
                'DigitalOcean Functions deploy completed.',
                'Namespace: '.$namespace,
                'Package: '.$package,
                'Action: '.$actionName,
                'Runtime: '.$kind,
                $revisionId ? 'Revision: '.$revisionId : null,
            ])),
            'revision_id' => $revisionId,
            'url' => $siteMeta['serverless']['action_url'],
        ];
    }

    public function delete(Site $site): void
    {
        $site->loadMissing('server');

        $server = $site->server;
        if (! $server instanceof Server || ! $server->isDigitalOceanFunctionsHost()) {
            return;
        }

        $serverMeta = is_array($server->meta) ? $server->meta : [];
        $hostConfig = is_array($serverMeta['digitalocean_functions'] ?? null) ? $serverMeta['digitalocean_functions'] : [];
        $resolvedConfig = $this->deploymentConfigResolver->resolve($site);

        $apiHost = rtrim((string) ($hostConfig['api_host'] ?? ''), '/');
        $namespace = trim((string) ($hostConfig['namespace'] ?? ''));
        $accessKey = trim((string) ($hostConfig['access_key'] ?? ''));
        $package = trim((string) ($resolvedConfig['package'] ?? 'default'));
        $actionName = trim((string) ($site->serverlessConfig()['action_name'] ?? $this->actionName($site)));

        if ($apiHost === '' || $namespace === '' || $accessKey === '' || $actionName === '') {
            return;
        }

        [$keyId, $keySecret] = $this->splitAccessKey($accessKey);
        $response = Http::withBasicAuth($keyId, $keySecret)
            ->timeout(120)
            ->acceptJson()
            ->delete($this->actionPutUrl($apiHost, $namespace, $package, $actionName));

        if (! $response->successful() && $response->status() !== 404) {
            throw new \RuntimeException('DigitalOcean Functions delete failed: HTTP '.$response->status().' '.$response->body());
        }
    }

    private function actionName(Site $site): string
    {
        $base = trim((string) ($site->slug ?: $site->name));
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: 'site';
        $base = trim($base, '-');

        return $base !== '' ? $base : 'site';
    }

    private function actionPutUrl(string $apiHost, string $namespace, string $package, string $actionName): string
    {
        $namespace = rawurlencode($namespace);
        $actionName = rawurlencode($actionName);

        if ($package === '') {
            return $apiHost.'/api/v1/namespaces/'.$namespace.'/actions/'.$actionName;
        }

        return $apiHost.'/api/v1/namespaces/'.$namespace.'/actions/'.rawurlencode($package).'/'.$actionName;
    }

    private function actionWebUrl(string $apiHost, string $namespace, string $package, string $actionName): string
    {
        $namespace = rawurlencode($namespace);
        $actionName = rawurlencode($actionName);
        $packagePath = $package !== '' ? rawurlencode($package).'/' : '';

        return $apiHost.'/api/v1/web/'.$namespace.'/'.$packagePath.$actionName;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitAccessKey(string $accessKey): array
    {
        if (! str_contains($accessKey, ':')) {
            throw new \RuntimeException('DigitalOcean Functions access key must use `id:secret` format.');
        }

        [$id, $secret] = explode(':', $accessKey, 2);
        $id = trim($id);
        $secret = trim($secret);

        if ($id === '' || $secret === '') {
            throw new \RuntimeException('DigitalOcean Functions access key id and secret must both be present.');
        }

        return [$id, $secret];
    }
}
