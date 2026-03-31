<?php

namespace App\Serverless\DigitalOcean;

use App\Contracts\ServerlessFunctionProvisioner;
use App\Serverless\Support\ArtifactZipPathPrefix;
use App\Serverless\Support\ProvisionerConfigReport;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Updates a DigitalOcean Functions (OpenWhisk-compatible) action from a local .zip via REST.
 *
 * Uses JSON exec with base64 zip ({@see https://github.com/apache/openwhisk/blob/master/docs/rest_actions.md}).
 * Obtain **api host**, **namespace**, and **`dof_v1_…` access key** from `doctl serverless connect` / namespace access keys.
 *
 * @see https://docs.digitalocean.com/products/functions/how-to/manage-namespace-access/
 */
final class DigitalOceanOpenWhiskActionProvisioner implements ServerlessFunctionProvisioner
{
    public function __construct(
        private string $apiHost,
        private string $namespace,
        private string $accessKey,
        private string $zipPathPrefix,
        private int $zipMaxBytes,
        private string $defaultActionKind,
        private string $defaultActionMain,
        private string $defaultPackage,
    ) {}

    public function deployFunction(string $name, string $runtime, string $artifactPath, array $config = []): array
    {
        $ctx = $this->resolveContext($config);
        $effectivePrefix = ArtifactZipPathPrefix::resolve($this->zipPathPrefix, $config, 'digitalocean_functions_zip_path_prefix');
        $this->assertZipPathUnderPrefix($artifactPath, $effectivePrefix);

        $actionName = $this->sanitizeActionName($name);
        $kind = $this->openWhiskKind($runtime, $ctx['action_kind']);
        $zipBytes = $this->readZipBytes($artifactPath);

        $url = $this->actionPutUrl($ctx['api_host'], $ctx['namespace'], $ctx['package'], $actionName);

        $body = [
            'exec' => [
                'kind' => $kind,
                'binary' => true,
                'code' => base64_encode($zipBytes),
                'main' => $ctx['action_main'],
            ],
        ];

        [$user, $secret] = $this->splitAccessKey($ctx['access_key']);

        $response = Http::withBasicAuth($user, $secret)
            ->timeout(300)
            ->acceptJson()
            ->put($url.'?overwrite=true', $body);

        if (! $response->successful()) {
            throw new RuntimeException('DigitalOcean Functions: HTTP '.$response->status().' — '.$response->body());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('DigitalOcean Functions: unexpected action response.');
        }

        $revision = isset($json['version']) ? (string) $json['version'] : '';
        if ($revision === '') {
            $revision = 'unknown';
        }

        return [
            'function_arn' => sprintf(
                'digitalocean:function:%s:%s%s',
                $ctx['namespace'],
                $ctx['package'] !== '' ? $ctx['package'].'/' : '',
                $actionName
            ),
            'revision_id' => $revision,
            'provider' => 'digitalocean',
            'runtime' => $runtime,
            'artifact_path' => $artifactPath,
            'config_keys' => ProvisionerConfigReport::safeConfigKeys($config),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{api_host: string, namespace: string, access_key: string, package: string, action_kind: string, action_main: string}
     */
    private function resolveContext(array $config): array
    {
        $settings = [];
        if (isset($config['project']['settings']) && is_array($config['project']['settings'])) {
            $settings = $config['project']['settings'];
        }

        $creds = [];
        if (isset($config['credentials']) && is_array($config['credentials'])) {
            $creds = $config['credentials'];
        }

        $apiHost = trim((string) ($creds['digitalocean_functions_api_host'] ?? $creds['api_host'] ?? $settings['digitalocean_functions_api_host'] ?? $this->apiHost));
        $namespace = trim((string) ($creds['digitalocean_functions_namespace'] ?? $creds['namespace'] ?? $settings['digitalocean_functions_namespace'] ?? $this->namespace));
        $accessKey = trim((string) ($creds['digitalocean_functions_access_key'] ?? $creds['access_key'] ?? $this->accessKey));
        $package = trim((string) ($settings['digitalocean_functions_package'] ?? $this->defaultPackage));
        $actionKind = trim((string) ($settings['digitalocean_functions_action_kind'] ?? $this->defaultActionKind));
        $actionMain = trim((string) ($settings['digitalocean_functions_action_main'] ?? $this->defaultActionMain));

        if ($apiHost === '' || $namespace === '' || $accessKey === '') {
            throw new RuntimeException(
                'DigitalOcean Functions requires api host, namespace, and access key (env or project credentials/settings).'
            );
        }

        $apiHost = rtrim($apiHost, '/');

        return [
            'api_host' => $apiHost,
            'namespace' => $namespace,
            'access_key' => $accessKey,
            'package' => $package,
            'action_kind' => $actionKind !== '' ? $actionKind : $this->defaultActionKind,
            'action_main' => $actionMain !== '' ? $actionMain : $this->defaultActionMain,
        ];
    }

    private function actionPutUrl(string $apiHost, string $namespace, string $package, string $actionName): string
    {
        $ns = rawurlencode($namespace);
        $an = rawurlencode($actionName);

        if ($package === '') {
            return $apiHost.'/api/v1/namespaces/'.$ns.'/actions/'.$an;
        }

        return $apiHost.'/api/v1/namespaces/'.$ns.'/actions/'.rawurlencode($package).'/'.$an;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitAccessKey(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || ! str_contains($raw, ':')) {
            throw new RuntimeException('DigitalOcean Functions access key must be `id:secret` (e.g. dof_v1_…:…).');
        }

        $pos = strpos($raw, ':');
        $user = trim(substr($raw, 0, $pos));
        $secret = trim(substr($raw, $pos + 1));

        if ($user === '' || $secret === '') {
            throw new RuntimeException('DigitalOcean Functions access key id and secret must be non-empty.');
        }

        return [$user, $secret];
    }

    private function openWhiskKind(string $runtime, string $defaultKind): string
    {
        $r = strtolower(trim($runtime));
        if (preg_match('/^[a-z][a-z0-9]*:[a-z0-9][a-z0-9._+-]*$/', $r)) {
            return $r;
        }

        return $defaultKind;
    }

    private function sanitizeActionName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 256) {
            throw new RuntimeException('Invalid function name for DigitalOcean action.');
        }
        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            throw new RuntimeException('function_name may only contain letters, digits, dot, underscore, and hyphen.');
        }

        return $name;
    }

    private function assertZipPathUnderPrefix(string $path, string $realEffectivePrefix): void
    {
        $lower = strtolower($path);
        if (! str_ends_with($lower, '.zip')) {
            throw new RuntimeException('DigitalOcean Functions deploy requires artifact_path to be a .zip file.');
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            throw new RuntimeException('Artifact zip must resolve under DIGITALOCEAN_FUNCTIONS_ZIP_PATH_PREFIX.');
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
