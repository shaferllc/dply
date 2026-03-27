<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

/**
 * Phase E stub: replace with real build + container/VM publish when the runtime adapter exists.
 */
final class CloudDeployEngine implements DeployEngine
{
    public function run(CloudDeployContext $context): array
    {
        $payload = [
            'provider' => 'cloud',
            'status' => 'stub',
            'application' => $context->applicationName,
            'stack' => $context->stack,
            'git_ref' => $context->gitRef,
            'trigger' => $context->trigger,
            'message' => 'CloudDeployEngine is a placeholder until Phase E build/publish is implemented.',
        ];

        if ($context->providerConfig !== []) {
            $payload['provider_context'] = $this->sanitizedProviderContext($context->providerConfig);
        }

        return [
            'output' => json_encode($payload, JSON_THROW_ON_ERROR),
            'sha' => 'cloud-stub-revision-1',
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
