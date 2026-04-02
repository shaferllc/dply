<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;

final class DeploymentPreflightValidator
{
    public function __construct(
        private readonly DeploymentContractBuilder $contractBuilder,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     checks: list<array{key: string, level: string, message: string}>
     * }
     */
    public function validate(Site $site): array
    {
        $contract = $this->contractBuilder->build($site);
        $errors = [];
        $warnings = [];
        $checks = [];

        if ($site->server_id === null) {
            $errors[] = 'A deployment target server is required.';
            $checks[] = $this->check('server', 'error', 'No deployment server is attached to this site.');
        } else {
            $checks[] = $this->check('server', 'ok', 'Deployment server is attached.');
        }

        if (in_array($site->runtimeTargetMode(), ['docker', 'kubernetes', 'serverless'], true)) {
            if (! filled($site->git_repository_url)) {
                $errors[] = 'A repository URL is required for this runtime target.';
                $checks[] = $this->check('repository', 'error', 'Repository URL is missing.');
            } else {
                $checks[] = $this->check('repository', 'ok', 'Repository URL is configured.');
            }
        }

        if ($this->framework($site) === 'laravel' && ! array_key_exists('APP_KEY', $contract->environmentMap())) {
            $errors[] = 'Laravel deployments require APP_KEY before launch.';
            $checks[] = $this->check('app_key', 'error', 'APP_KEY is missing for a Laravel deployment.');
        }

        if ($site->runtimeTargetMode() === 'vm' && ! filled($site->testingHostname()) && ! filled(optional($site->primaryDomain())->hostname)) {
            $warnings[] = 'No preview or primary domain is configured yet.';
            $checks[] = $this->check('publication', 'warning', 'No publication hostname is configured yet.');
        }

        foreach ($contract->resourceBindings as $binding) {
            if (! $binding->required || $binding->status === 'configured') {
                continue;
            }

            $warnings[] = ucfirst($binding->type).' is not configured yet.';
            $checks[] = $this->check($binding->type, 'warning', ucfirst($binding->type).' binding is still pending.');
        }

        foreach ($contract->resourceBindings as $binding) {
            if ($binding->type === 'redis'
                && $binding->status === 'pending'
                && ($binding->config['reason'] ?? null) === 'drivers_reference_redis_without_connection') {
                $msg = 'Session, cache, or queue targets Redis, but REDIS_HOST or REDIS_URL is not set.';
                $warnings[] = $msg;
                $checks[] = $this->check('redis', 'warning', $msg);
            }

            if ($binding->type === 'storage'
                && $binding->source === 'environment'
                && $binding->status === 'pending'
                && isset($binding->config['reason'])) {
                $msg = match ($binding->config['reason']) {
                    's3_disk_without_bucket' => 'S3-style disk is selected, but AWS_BUCKET or AWS_URL is missing.',
                    'bucket_without_keys' => 'Object storage bucket is set, but AWS access keys are incomplete.',
                    default => 'Object storage environment looks incomplete.',
                };
                $warnings[] = $msg;
                $checks[] = $this->check('storage', 'warning', $msg);
            }
        }

        $checks[] = $this->check(
            'runtime_revision',
            data_get($contract->status, 'runtime_drifted') ? 'warning' : 'ok',
            data_get($contract->status, 'runtime_drifted')
                ? 'Current contract differs from the last applied runtime revision.'
                : 'Current contract matches the last applied runtime revision or has not been applied yet.'
        );

        return [
            'ok' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{key: string, level: string, message: string}
     */
    private function check(string $key, string $level, string $message): array
    {
        return [
            'key' => $key,
            'level' => $level,
            'message' => $message,
        ];
    }

    private function framework(Site $site): ?string
    {
        foreach ([
            data_get($site->meta, 'docker_runtime.detected.framework'),
            data_get($site->meta, 'kubernetes_runtime.detected.framework'),
            data_get($site->meta, 'serverless.detected.framework'),
        ] as $framework) {
            if (is_string($framework) && $framework !== '') {
                return $framework;
            }
        }

        return null;
    }
}
