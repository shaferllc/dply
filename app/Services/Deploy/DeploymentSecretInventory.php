<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;
use App\Support\Deployment\DeploymentSecret;

final class DeploymentSecretInventory
{
    /**
     * @return list<DeploymentSecret>
     */
    public function forSite(Site $site): array
    {
        $site->loadMissing(['environmentVariables', 'workspace.variables']);
        $environment = $site->deployment_environment ?: 'production';
        $inventory = [];

        foreach ($this->parseDotEnv((string) ($site->env_file_content ?? '')) as $key => $value) {
            $inventory[] = new DeploymentSecret(
                key: $key,
                value: $value,
                scope: 'site',
                source: 'site_env_file',
                environment: $environment,
                classification: $this->classify($key),
                isSecret: $this->looksSensitiveKey($key),
            );
        }

        if ($site->workspace) {
            foreach ($site->workspace->variables as $row) {
                $inventory[] = new DeploymentSecret(
                    key: (string) $row->env_key,
                    value: (string) ($row->env_value ?? ''),
                    scope: 'workspace',
                    source: 'workspace_variable',
                    environment: $environment,
                    classification: $this->classify((string) $row->env_key),
                    isSecret: (bool) $row->is_secret || $this->looksSensitiveKey((string) $row->env_key),
                );
            }
        }

        foreach ($site->environmentVariables as $row) {
            if ((string) $row->environment !== $environment) {
                continue;
            }

            $inventory[] = new DeploymentSecret(
                key: (string) $row->env_key,
                value: (string) ($row->env_value ?? ''),
                scope: 'site',
                source: 'site_environment_variable',
                environment: $environment,
                classification: $this->classify((string) $row->env_key),
                isSecret: $this->looksSensitiveKey((string) $row->env_key),
            );
        }

        if (filled($site->webhook_secret)) {
            $inventory[] = new DeploymentSecret(
                key: 'DPLY_WEBHOOK_SECRET',
                value: (string) $site->webhook_secret,
                scope: 'site',
                source: 'site_webhook_secret',
                environment: $environment,
                classification: 'webhook',
                isSecret: true,
            );
        }

        if (filled($site->git_deploy_key_private)) {
            $inventory[] = new DeploymentSecret(
                key: 'DPLY_GIT_DEPLOY_KEY_PRIVATE',
                value: (string) $site->git_deploy_key_private,
                scope: 'site',
                source: 'site_git_deploy_key',
                environment: $environment,
                classification: 'ssh',
                isSecret: true,
            );
        }

        return $inventory;
    }

    /**
     * @return array<string, string>
     */
    public function environmentMapForSite(Site $site): array
    {
        $environment = [];

        foreach ($this->forSite($site) as $secret) {
            if (str_starts_with($secret->key, 'DPLY_')) {
                continue;
            }

            $environment[$secret->key] = $secret->value;
        }

        return $environment;
    }

    /**
     * Same keys Dply uses when building a deployment contract environment map
     * (inventory + Laravel defaults when a framework is detected).
     *
     * @return array<string, string>
     */
    public function effectiveEnvironmentMapForSite(Site $site): array
    {
        $site->loadMissing(['environmentVariables', 'workspace.variables']);

        $environment = $this->environmentMapForSite($site);
        $appEnvironment = (string) ($site->deployment_environment ?: 'production');

        $environment['APP_ENV'] ??= $appEnvironment;
        $environment['APP_DEBUG'] ??= $appEnvironment === 'production' ? 'false' : 'true';

        if ($this->detectedFramework($site) === 'laravel') {
            $environment['SESSION_DRIVER'] ??= 'file';
            $environment['CACHE_STORE'] ??= 'file';
            $environment['QUEUE_CONNECTION'] ??= 'sync';
        }

        return $environment;
    }

    private function detectedFramework(Site $site): ?string
    {
        foreach ([
            data_get($site->meta, 'docker_runtime.detected.framework'),
            data_get($site->meta, 'kubernetes_runtime.detected.framework'),
            data_get($site->meta, 'serverless.detected.framework'),
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function parseDotEnv(string $raw): array
    {
        $map = [];

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            $map[$key] = $this->unquote($value);
        }

        return $map;
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    private function classify(string $key): string
    {
        return match (true) {
            preg_match('/app[_-]?key|secret/i', $key) === 1 => 'app_secret',
            preg_match('/password|passwd|database_url/i', $key) === 1 => 'credential',
            preg_match('/token|api[_-]?key|access[_-]?key/i', $key) === 1 => 'token',
            default => 'config',
        };
    }

    private function looksSensitiveKey(string $key): bool
    {
        return preg_match('/key|secret|token|password|passwd|credential/i', $key) === 1;
    }
}
