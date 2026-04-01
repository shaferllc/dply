<?php

declare(strict_types=1);

namespace App\Services\Deploy\ServerlessProviders\Cloudflare;

use App\Contracts\ServerlessFunctionProvisioner;
use App\Services\Deploy\Support\ProvisionerConfigReport;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CloudflareWorkersProvisioner implements ServerlessFunctionProvisioner
{
    public function __construct(
        private readonly string $accountId,
        private readonly string $apiToken,
        private readonly string $compatibilityDate,
        private readonly string $scriptPathPrefix,
        private readonly int $scriptMaxBytes,
    ) {}

    public function deployFunction(string $name, string $runtime, string $artifactPath, array $config = []): array
    {
        $this->assertPathUnderPrefix($artifactPath);
        $ctx = $this->resolveCloudflareContext($config);
        $scriptName = $this->toScriptName($name);
        $body = $this->readScriptBytes($artifactPath);
        $mainModule = $this->mainModuleName($artifactPath);

        $metadata = [
            'main_module' => $mainModule,
            'bindings' => [],
            'compatibility_date' => $ctx['compatibility_date'],
        ];

        $url = sprintf(
            'https://api.cloudflare.com/client/v4/accounts/%s/workers/scripts/%s',
            rawurlencode($ctx['account_id']),
            rawurlencode($scriptName),
        );

        $response = Http::withToken($ctx['api_token'])
            ->timeout(120)
            ->attach('metadata', json_encode($metadata, JSON_THROW_ON_ERROR), 'metadata', ['Content-Type' => 'application/json'])
            ->attach($mainModule, $body, $mainModule, ['Content-Type' => 'application/javascript'])
            ->put($url);

        if (! $response->successful()) {
            throw new RuntimeException('Cloudflare Workers: HTTP '.$response->status().' — '.$response->body());
        }

        $json = $response->json();
        if (! is_array($json) || empty($json['success'])) {
            $errors = json_encode($json['errors'] ?? $json, JSON_THROW_ON_ERROR);
            throw new RuntimeException('Cloudflare Workers API error: '.$errors);
        }

        $etag = $response->header('ETag');
        $revision = is_string($etag) && $etag !== ''
            ? trim($etag, '"')
            : (string) (is_array($json['result'] ?? null) ? ($json['result']['id'] ?? 'unknown') : 'unknown');

        return [
            'function_arn' => sprintf('cloudflare:worker:%s:%s', $ctx['account_id'], $scriptName),
            'revision_id' => $revision,
            'provider' => 'cloudflare',
            'runtime' => $runtime,
            'artifact_path' => $artifactPath,
            'config_keys' => ProvisionerConfigReport::safeConfigKeys($config),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{account_id: string, api_token: string, compatibility_date: string}
     */
    private function resolveCloudflareContext(array $config): array
    {
        $settings = [];
        if (isset($config['project']['settings']) && is_array($config['project']['settings'])) {
            $settings = $config['project']['settings'];
        }

        $creds = [];
        if (isset($config['credentials']) && is_array($config['credentials'])) {
            $creds = $config['credentials'];
        }

        $accountId = trim((string) ($creds['account_id'] ?? $creds['cloudflare_account_id'] ?? $settings['cloudflare_account_id'] ?? ''));
        if ($accountId === '') {
            $accountId = $this->accountId;
        }

        $apiToken = trim((string) ($creds['api_token'] ?? $creds['cloudflare_api_token'] ?? ''));
        if ($apiToken === '') {
            $apiToken = $this->apiToken;
        }

        $compatibilityDate = trim((string) ($settings['cloudflare_compatibility_date'] ?? ''));
        if ($compatibilityDate === '') {
            $compatibilityDate = $this->compatibilityDate;
        }

        if ($accountId === '' || $apiToken === '') {
            throw new RuntimeException('Cloudflare account_id and api_token are required (set CLOUDFLARE_ACCOUNT_ID / CLOUDFLARE_API_TOKEN or project credentials / settings).');
        }

        return [
            'account_id' => $accountId,
            'api_token' => $apiToken,
            'compatibility_date' => $compatibilityDate,
        ];
    }

    private function toScriptName(string $name): string
    {
        $script = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name));
        $script = trim($script, '-');
        if ($script === '' || strlen($script) > 255) {
            throw new RuntimeException('function_name is not usable as a Cloudflare Worker script name (use letters, digits, hyphen, underscore).');
        }

        return $script;
    }

    private function mainModuleName(string $path): string
    {
        $base = basename($path);

        return preg_match('/\.m?js$/i', $base) === 1 ? $base : 'worker.js';
    }

    private function readScriptBytes(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Worker script file is missing or not readable: '.$path);
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('Worker script file is empty.');
        }
        if ($size > $this->scriptMaxBytes) {
            throw new RuntimeException('Worker script exceeds maximum size ('.$this->scriptMaxBytes.' bytes).');
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Could not read worker script file.');
        }

        return $bytes;
    }

    private function assertPathUnderPrefix(string $path): void
    {
        $realPath = realpath($path);
        $realPrefix = realpath($this->scriptPathPrefix);
        if ($realPath === false || $realPrefix === false) {
            throw new RuntimeException('Worker script path must resolve under CLOUDFLARE_WORKER_SCRIPT_PATH_PREFIX.');
        }

        $prefixWithSep = rtrim($realPrefix, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if ($realPath !== $realPrefix && ! str_starts_with($realPath, $prefixWithSep)) {
            throw new RuntimeException('Worker script path escapes allowed prefix directory.');
        }
    }
}
