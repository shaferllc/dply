<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Support\Deployment\DeploymentSecret;

final class DeploymentSecretInventory
{
    public function __construct(
        private readonly DotEnvFileParser $parser,
    ) {}

    /**
     * @return list<DeploymentSecret>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<App\Support\Deployment\DeploymentSecret>
     */
    public function forSite(Site $site): array
    {
        $site->loadMissing(['workspace.variables']);
        $environment = $site->deployment_environment ?: 'production';
        $inventory = [];

        // Order matters: environmentMapForSite() collapses the inventory into a
        // map by overwriting on duplicate keys. Workspace vars come first
        // (lowest priority); the site env_file_content blob comes last so its
        // keys override workspace inheritance for matching names.
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

        // Managed resource bindings contribute their connection variables here,
        // between workspace inheritance and the site .env blob. They are a
        // separate layer kept out of the editable Variables list — but an
        // explicit site .env key still overrides a binding-provided value
        // (parsed below), so operators retain a manual escape hatch.
        // A derived worker inherits its parent app's resource bindings, so the
        // secret inventory (and the required-env gate it feeds) sees the parent's
        // connection vars, not the worker's empty own.
        $bindingSource = $site->resourceSourceSite();
        $bindingSource->loadMissing('bindings');
        foreach ($bindingSource->bindings as $binding) {
            foreach ($binding->connectionEnv() as $key => $value) {
                $inventory[] = new DeploymentSecret(
                    key: (string) $key,
                    value: (string) $value,
                    scope: 'binding',
                    source: 'site_binding:'.$binding->type,
                    environment: $environment,
                    classification: $this->classify((string) $key),
                    isSecret: $this->looksSensitiveKey((string) $key),
                );
            }
        }

        $parsed = $this->parser->parse((string) ($site->env_file_content ?? ''));
        foreach ($parsed['variables'] as $key => $value) {
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
     * @return list<App\Support\Deployment\DeploymentSecret>
     */
    /** @return array<string, mixed> */
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
    /** @return array<string, mixed> */
    public function effectiveEnvironmentMapForSite(Site $site): array
    {
        $site->loadMissing(['workspace.variables']);

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
