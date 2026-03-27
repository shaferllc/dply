<?php

namespace App\Serverless\Netlify;

use App\Contracts\ServerlessFunctionProvisioner;
use App\Serverless\Support\ArtifactZipPathPrefix;
use App\Serverless\Support\ProvisionerConfigReport;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Creates a Netlify deploy from a local zip via the REST API.
 *
 * @see https://docs.netlify.com/api/get-started/#deploy-with-the-api
 */
final class NetlifyZipDeployProvisioner implements ServerlessFunctionProvisioner
{
    public function __construct(
        private string $defaultApiToken,
        private string $defaultSiteId,
        private string $zipPathPrefix,
        private int $zipMaxBytes,
    ) {}

    public function deployFunction(string $name, string $runtime, string $artifactPath, array $config = []): array
    {
        $ctx = $this->resolveNetlifyContext($config);
        $effectivePrefix = ArtifactZipPathPrefix::resolve($this->zipPathPrefix, $config, 'netlify_deploy_zip_path_prefix');
        $this->assertZipPathUnderPrefix($artifactPath, $effectivePrefix);
        $zipBytes = $this->readZipBytes($artifactPath);

        $url = sprintf('https://api.netlify.com/api/v1/sites/%s/deploys', rawurlencode($ctx['site_id']));

        $response = Http::withToken($ctx['api_token'])
            ->timeout(300)
            ->acceptJson()
            ->attach('file', $zipBytes, basename($artifactPath))
            ->post($url);

        if (! $response->successful()) {
            throw new RuntimeException('Netlify: HTTP '.$response->status().' — '.$response->body());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Netlify: unexpected deploy response.');
        }

        $deployId = isset($json['id']) ? (string) $json['id'] : '';
        if ($deployId === '') {
            throw new RuntimeException('Netlify: deploy response missing id.');
        }

        return [
            'function_arn' => sprintf('netlify:site:%s:deploy:%s', $ctx['site_id'], $deployId),
            'revision_id' => $deployId,
            'provider' => 'netlify',
            'runtime' => $runtime,
            'artifact_path' => $artifactPath,
            'config_keys' => ProvisionerConfigReport::safeConfigKeys($config),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{api_token: string, site_id: string}
     */
    private function resolveNetlifyContext(array $config): array
    {
        $settings = [];
        if (isset($config['project']['settings']) && is_array($config['project']['settings'])) {
            $settings = $config['project']['settings'];
        }

        $creds = [];
        if (isset($config['credentials']) && is_array($config['credentials'])) {
            $creds = $config['credentials'];
        }

        $apiToken = trim((string) ($creds['api_token'] ?? $creds['netlify_personal_access_token'] ?? ''));
        if ($apiToken === '') {
            $apiToken = $this->defaultApiToken;
        }

        $siteId = trim((string) ($creds['site_id'] ?? $creds['netlify_site_id'] ?? $settings['netlify_site_id'] ?? ''));
        if ($siteId === '') {
            $siteId = $this->defaultSiteId;
        }

        if ($apiToken === '' || $siteId === '') {
            throw new RuntimeException(
                'Netlify api_token and site_id are required (set NETLIFY_AUTH_TOKEN + NETLIFY_SITE_ID or project credentials / settings).'
            );
        }

        return [
            'api_token' => $apiToken,
            'site_id' => $siteId,
        ];
    }

    private function assertZipPathUnderPrefix(string $path, string $realEffectivePrefix): void
    {
        $lower = strtolower($path);
        if (! str_ends_with($lower, '.zip')) {
            throw new RuntimeException('Netlify deploy requires artifact_path to be a .zip file.');
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            throw new RuntimeException('Artifact zip must resolve under NETLIFY_DEPLOY_ZIP_PATH_PREFIX.');
        }
        $prefixWithSep = rtrim($realEffectivePrefix, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if ($realPath !== $realEffectivePrefix && ! str_starts_with($realPath, $prefixWithSep)) {
            throw new RuntimeException('Artifact zip path escapes allowed prefix directory.');
        }
    }

    private function readZipBytes(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Artifact zip is missing or not readable: '.$path);
        }
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('Artifact zip is empty.');
        }
        if ($size > $this->zipMaxBytes) {
            throw new RuntimeException('Artifact zip exceeds maximum size ('.$this->zipMaxBytes.' bytes).');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Could not read artifact zip.');
        }

        return $bytes;
    }
}
