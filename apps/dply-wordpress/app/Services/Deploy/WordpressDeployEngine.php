<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

/**
 * Phase F stub: replace with real WP core, plugins, backups, and staging flows.
 */
final class WordpressDeployEngine implements DeployEngine
{
    public function run(WordpressDeployContext $context): array
    {
        $payload = [
            'provider' => 'wordpress',
            'status' => 'stub',
            'application' => $context->applicationName,
            'php_version' => $context->phpVersion,
            'git_ref' => $context->gitRef,
            'trigger' => $context->trigger,
            'message' => 'WordpressDeployEngine is a placeholder until managed WordPress build/publish is implemented.',
        ];

        if ($context->providerConfig !== []) {
            $payload['provider_context'] = $this->sanitizedProviderContext($context->providerConfig);
        }

        return [
            'output' => json_encode($payload, JSON_THROW_ON_ERROR),
            'sha' => 'wp-stub-revision-1',
        ];
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     * @return array<string, mixed>
     */
    private function sanitizedProviderContext(array $providerConfig): array
    {
        $out = [];
        if (isset($providerConfig['project']) && is_array($providerConfig['project'])) {
            $p = $providerConfig['project'];
            $out['project'] = array_filter([
                'id' => $p['id'] ?? null,
                'slug' => $p['slug'] ?? null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }
}
