<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

/**
 * Phase G stub: replace with real build → static/edge publish, previews, and CDN wiring.
 */
final class EdgeDeployEngine implements DeployEngine
{
    public function run(EdgeDeployContext $context): array
    {
        $payload = [
            'provider' => 'edge',
            'status' => 'stub',
            'application' => $context->applicationName,
            'framework' => $context->framework,
            'git_ref' => $context->gitRef,
            'trigger' => $context->trigger,
            'message' => 'EdgeDeployEngine is a placeholder until framework builds and CDN/preview flows are implemented.',
        ];

        if ($context->providerConfig !== []) {
            $payload['provider_context'] = $this->sanitizedProviderContext($context->providerConfig);
        }

        return [
            'output' => json_encode($payload, JSON_THROW_ON_ERROR),
            'sha' => 'edge-stub-revision-1',
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
