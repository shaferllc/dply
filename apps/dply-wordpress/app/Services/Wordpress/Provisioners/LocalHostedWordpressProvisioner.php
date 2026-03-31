<?php

namespace App\Services\Wordpress\Provisioners;

use App\Contracts\HostedWordpressProvisioner;
use App\Services\Deploy\WordpressDeployContext;

/**
 * Local / deterministic provisioner until an HTTP/SDK adapter targets real fleet capacity (ADR-007).
 */
final class LocalHostedWordpressProvisioner implements HostedWordpressProvisioner
{
    public function deploy(WordpressDeployContext $context): array
    {
        $project = $context->providerConfig['project'] ?? [];
        $slug = is_array($project) ? (string) ($project['slug'] ?? 'unknown') : 'unknown';
        $settings = is_array($project) && isset($project['settings']) && is_array($project['settings'])
            ? $project['settings']
            : [];

        $payload = [
            'provider' => 'wordpress',
            'status' => 'deployed',
            'runtime' => 'hosted',
            'application' => $context->applicationName,
            'php_version' => $context->phpVersion,
            'git_ref' => $context->gitRef,
            'trigger' => $context->trigger,
            'hosted' => [
                'environment_id' => $this->nonEmptyString($settings['environment_id'] ?? null),
                'primary_url' => $this->nonEmptyString($settings['primary_url'] ?? null),
                'compute_ref' => $this->nonEmptyString($settings['compute_ref'] ?? null),
                'data_ref' => $this->nonEmptyString($settings['data_ref'] ?? null),
            ],
            'wordpress' => [
                'detected_version' => (string) config('wordpress.mock_wordpress_version', '6.4.2'),
            ],
            'message' => 'LocalHostedWordpressProvisioner: wire HttpHostedWordpressProvisioner for real fleet.',
        ];

        $revisionId = hash('sha256', $slug.'|'.$context->gitRef.'|'.$context->phpVersion.'|'.$context->applicationName);

        return [
            'output' => json_encode($payload, JSON_THROW_ON_ERROR),
            'revision_id' => $revisionId,
        ];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
