<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\ServerlessProviders\DigitalOcean;

use App\Modules\Deploy\Services\Support\ArtifactZipPathPrefix;
use App\Modules\Deploy\Services\Support\ProvisionerConfigReport;
use App\Modules\Serverless\Concerns\DeclaresFeatureSupport;
use App\Modules\Serverless\Contracts\DeclaresServerlessFeatures;
use App\Modules\Serverless\Contracts\ServerlessFeature;
use App\Modules\Serverless\Contracts\ServerlessFunctionProvisioner;
use App\Modules\Serverless\Contracts\SupportsAsyncInvocation;
use App\Modules\Serverless\Contracts\SupportsFunctionConfiguration;
use App\Modules\Serverless\Support\FunctionConfiguration;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class DigitalOceanOpenWhiskActionProvisioner implements DeclaresServerlessFeatures, ServerlessFunctionProvisioner, SupportsAsyncInvocation, SupportsFunctionConfiguration
{
    use DeclaresFeatureSupport;


    public function __construct(
        private readonly string $apiHost,
        private readonly string $namespace,
        private readonly string $accessKey,
        private readonly string $zipPathPrefix,
        private readonly int $zipMaxBytes,
        private readonly string $defaultActionKind,
        private readonly string $defaultActionMain,
        private readonly string $defaultPackage,
    ) {}

    /** @return array<string, mixed> */
    public function deployFunction(string $name, string $runtime, string $artifactPath, array $config = []): array
    {
        $ctx = $this->resolveContext($config);
        $effectivePrefix = ArtifactZipPathPrefix::resolve($this->zipPathPrefix, $config, 'digitalocean_functions_zip_path_prefix');
        $this->assertZipPathUnderPrefix($artifactPath, $effectivePrefix);

        $actionName = $this->sanitizeActionName($name);
        $kind = $this->openWhiskKind($runtime, $ctx['action_kind']);
        $zipBytes = $this->readZipBytes($artifactPath);
        $url = $this->actionPutUrl($ctx['api_host'], $ctx['namespace'], $ctx['package'], $actionName);

        $configuration = $this->functionConfiguration($config);

        $body = array_filter([
            'exec' => [
                'kind' => $kind,
                'binary' => true,
                'code' => base64_encode($zipBytes),
                'main' => $ctx['action_main'],
            ],
            'annotations' => $configuration->annotations(),
            'parameters' => $configuration->parameterPairs(),
        ], static fn (array $value): bool => $value !== []);

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

        $revision = isset($json['version']) ? (string) $json['version'] : 'unknown';
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
     * Update an existing action's exposure and bound parameters without
     * re-uploading its code. OpenWhisk treats a PUT with no `exec` as a
     * metadata patch, so the deployed artifact is left untouched.
     *
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, error: ?string, data: mixed}
     */
    public function applyFunctionConfiguration(string $name, FunctionConfiguration $configuration, array $context = []): array
    {
        try {
            $ctx = $this->resolveContext($context);
            $actionName = $this->sanitizeActionName($name);
            [$user, $secret] = $this->splitAccessKey($ctx['access_key']);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'data' => null];
        }

        $url = $this->actionPutUrl($ctx['api_host'], $ctx['namespace'], $ctx['package'], $actionName);

        try {
            $response = Http::withBasicAuth($user, $secret)
                ->timeout(60)
                ->acceptJson()
                ->put($url.'?overwrite=true', [
                    'annotations' => $configuration->annotations(),
                    'parameters' => $configuration->parameterPairs(),
                ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'data' => null];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => 'DigitalOcean Functions: HTTP '.$response->status().' — '.$response->body(),
                'data' => $response->json(),
            ];
        }

        return ['ok' => true, 'error' => null, 'data' => $response->json()];
    }

    /**
     * Start an invocation without waiting. OpenWhisk answers 202 with the
     * activation id; the result is collected later via {@see fetchActivation()}.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, error: ?string, activation_id: ?string}
     */
    public function invokeAsync(string $name, array $payload = [], array $context = []): array
    {
        try {
            $ctx = $this->resolveContext($context);
            $actionName = $this->sanitizeActionName($name);
            [$user, $secret] = $this->splitAccessKey($ctx['access_key']);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'activation_id' => null];
        }

        $url = $this->actionPutUrl($ctx['api_host'], $ctx['namespace'], $ctx['package'], $actionName);

        try {
            $response = Http::withBasicAuth($user, $secret)
                ->timeout(30)
                ->acceptJson()
                ->post($url.'?blocking=false&result=false', $payload);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'activation_id' => null];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => 'DigitalOcean Functions: HTTP '.$response->status().' — '.$response->body(),
                'activation_id' => null,
            ];
        }

        $json = $response->json();
        $activationId = is_array($json) ? trim((string) ($json['activationId'] ?? '')) : '';

        return [
            'ok' => $activationId !== '',
            'error' => $activationId !== '' ? null : 'DigitalOcean Functions accepted the invocation but returned no activation id.',
            'activation_id' => $activationId !== '' ? $activationId : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, error: ?string, pending: bool, activation: ?array<string, mixed>}
     */
    public function fetchActivation(string $activationId, array $context = []): array
    {
        $activationId = trim($activationId);
        if ($activationId === '' || ! preg_match('/^[a-zA-Z0-9]+$/', $activationId)) {
            return ['ok' => false, 'error' => 'Invalid activation id.', 'pending' => false, 'activation' => null];
        }

        try {
            $ctx = $this->resolveContext($context);
            [$user, $secret] = $this->splitAccessKey($ctx['access_key']);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'pending' => false, 'activation' => null];
        }

        $url = $ctx['api_host'].'/api/v1/namespaces/_/activations/'.rawurlencode($activationId);

        try {
            $response = Http::withBasicAuth($user, $secret)->timeout(30)->acceptJson()->get($url);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'pending' => false, 'activation' => null];
        }

        // The record does not exist until the activation completes, so a 404
        // is "still running", not "gone" — the poller must keep waiting.
        if ($response->status() === 404) {
            return ['ok' => false, 'error' => null, 'pending' => true, 'activation' => null];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => 'DigitalOcean Functions: HTTP '.$response->status().' — '.$response->body(),
                'pending' => false,
                'activation' => null,
            ];
        }

        $json = $response->json();

        return [
            'ok' => is_array($json),
            'error' => is_array($json) ? null : 'DigitalOcean Functions returned an unexpected activation record.',
            'pending' => false,
            'activation' => is_array($json) ? $json : null,
        ];
    }

    /** @return list<ServerlessFeature> */
    protected function serverlessFeatures(): array
    {
        return [
            ServerlessFeature::WebFunction,
            ServerlessFeature::RawHttp,
            ServerlessFeature::CustomCors,
            ServerlessFeature::SecuredWeb,
            ServerlessFeature::ApiKeyPassthrough,
            ServerlessFeature::DefaultParameters,
            ServerlessFeature::FinalParameters,
            ServerlessFeature::AsyncInvocation,
            ServerlessFeature::ActivationRecords,
            ServerlessFeature::ScheduledTriggers,
            ServerlessFeature::Sequences,
            ServerlessFeature::MultiActionProjects,
            ServerlessFeature::ProjectManifest,
            // Forwarding is driven by a LOG_DESTINATIONS variable on the
            // function, which this provisioner binds like any other parameter.
            ServerlessFeature::LogForwarding,
        ];
    }

    /**
     * The HTTP-exposure configuration for this deploy. Callers pass the
     * persisted `meta.serverless` block under `function`; its absence means a
     * plain web function, matching what the deployer has always produced.
     *
     * @param  array<string, mixed>  $config
     */
    private function functionConfiguration(array $config): FunctionConfiguration
    {
        $function = $config['function'] ?? null;

        return FunctionConfiguration::fromSiteConfig(is_array($function) ? $function : []);
    }

    /**
     * @param  array<string, mixed> $config
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
            throw new RuntimeException('DigitalOcean Functions requires api host, namespace, and access key (env or project credentials/settings).');
        }

        return [
            'api_host' => rtrim($apiHost, '/'),
            'namespace' => $namespace,
            'access_key' => $accessKey,
            'package' => $package,
            'action_kind' => $actionKind !== '' ? $actionKind : $this->defaultActionKind,
            'action_main' => $actionMain !== '' ? $actionMain : $this->defaultActionMain,
        ];
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
        $runtime = strtolower(trim($runtime));
        if (preg_match('/^[a-z][a-z0-9]*:[a-z0-9][a-z0-9._+-]*$/', $runtime)) {
            return $runtime;
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
        if (! str_ends_with(strtolower($path), '.zip')) {
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
