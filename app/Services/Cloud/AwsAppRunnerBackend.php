<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\AwsAppRunnerService;

class AwsAppRunnerBackend implements CloudBackend
{
    use ResolvesMetricWindows;

    public function providerKey(): string
    {
        return 'aws_app_runner';
    }

    public function supportsWorkers(): bool
    {
        // App Runner services are HTTP-request-driven only — there is
        // no concept of a long-running, non-HTTP background process.
        // Queue workers and the scheduler cannot run here.
        return false;
    }

    public function supportsDeployTasks(): bool
    {
        // App Runner has no job/task concept either — only the HTTP
        // service. Deploy-task creation is blocked at the form layer
        // when the resolved backend is App Runner.
        return false;
    }

    public function supportsAlerts(): bool
    {
        // App Runner emits metrics into CloudWatch with its own alarm
        // model; dply doesn't bridge that yet, so we report false and
        // the alerts UI hides itself for App Runner sites.
        return false;
    }

    public function cancelInProgressDeployment(Site $site, ProviderCredential $credential): bool
    {
        // App Runner does support StopDeployment but the request shape
        // and idempotency story differ enough that dply hasn't wired
        // it yet. Return false so the cancel UI surfaces a clear
        // "not available on this backend" rather than silently
        // succeeding without actually stopping the deploy.
        return false;
    }

    public function syncWorkers(Site $site, ProviderCredential $credential): void
    {
        throw new \RuntimeException(
            'AWS App Runner does not support background workers. '
            .'App Runner services are HTTP-request-driven only — '
            .'use a DigitalOcean App Platform site for queue workers '
            .'and the scheduler.',
        );
    }

    public function supportsAutoscaling(): bool
    {
        // App Runner has a native HealthCheckConfiguration on the
        // service, and an AutoScalingConfiguration resource for
        // min/max instances. We apply both — see syncScaling() for
        // the graceful-degradation note on autoscaling.
        return true;
    }

    /**
     * Push the site's autoscaling + health-check config to App Runner.
     *
     * Health check: applied directly via the service's native
     * HealthCheckConfiguration (HTTP path + interval / timeout /
     * thresholds).
     *
     * Autoscaling: App Runner uses an AutoScalingConfiguration
     * resource (MinSize / MaxSize) associated with the service. We
     * create one and associate it best-effort. App Runner autoscaling
     * is concurrency-driven, not CPU-target, so dply's cpu_percent is
     * recorded as intent but not mapped. If the AutoScalingConfiguration
     * lifecycle fails (quota, permissions, an in-progress operation),
     * we degrade gracefully: the config stays on the site's meta and a
     * note is recorded in meta.container.autoscaling.backend_note
     * rather than leaving the service half-configured.
     *
     * No-op when the site has no backend service yet — the config
     * lands via the next provision instead.
     */
    public function syncScaling(Site $site, ProviderCredential $credential): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }

        $service = new AwsAppRunnerService($credential, $site->container_region ?: null);

        // Health check — straightforward, applied directly.
        $hc = CloudScalingConfig::healthCheck($site);
        if ($hc['enabled']) {
            $service->updateHealthCheck($site->container_backend_id, [
                'http_path' => $hc['http_path'],
                'period_seconds' => $hc['period_seconds'],
                'timeout_seconds' => $hc['timeout_seconds'],
                'success_threshold' => $hc['success_threshold'],
                'failure_threshold' => $hc['failure_threshold'],
            ]);
        }

        // Autoscaling — best-effort. Degrade gracefully on any failure.
        $autoscaling = CloudScalingConfig::autoscaling($site);
        $note = null;
        if ($autoscaling['enabled']) {
            try {
                $configName = $this->autoScalingConfigName($site);
                $arn = $service->applyAutoScaling(
                    $site->container_backend_id,
                    $configName,
                    $autoscaling['min_instances'],
                    $autoscaling['max_instances'],
                );
                $note = $arn !== ''
                    ? sprintf(
                        'Applied App Runner AutoScalingConfiguration %s (MinSize %d, MaxSize %d). '
                        .'Note: App Runner autoscaling is concurrency-driven — the %d%% CPU target is recorded as intent only.',
                        $arn,
                        $autoscaling['min_instances'],
                        $autoscaling['max_instances'],
                        $autoscaling['cpu_percent'],
                    )
                    : 'App Runner did not return an AutoScalingConfiguration ARN — config recorded as intent only.';
            } catch (\Throwable $e) {
                $note = 'Could not apply App Runner AutoScalingConfiguration: '.$e->getMessage()
                    .' — config recorded on the site as intent; apply it in the App Runner console.';
            }
        }

        // Record the outcome note on the site's meta so the dashboard
        // / CLI can surface what actually happened on App Runner.
        $meta = ($site->meta );
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $as = is_array($container['autoscaling'] ?? null) ? $container['autoscaling'] : [];
        if ($note === null) {
            unset($as['backend_note']);
        } else {
            $as['backend_note'] = $note;
        }
        $container['autoscaling'] = $as;
        $meta['container'] = $container;
        $site->update(['meta' => $meta]);
    }

    /**
     * A short, AWS-safe AutoScalingConfiguration name for the site.
     * App Runner names are alnum + hyphen + underscore, ≤ 32 chars.
     */
    private function autoScalingConfigName(Site $site): string
    {
        $base = $this->backendServiceName($site);

        return substr('dply-'.$base, 0, 32);
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function provision(Site $site, ProviderCredential $credential): array
    {
        $service = new AwsAppRunnerService($credential, $site->container_region ?: null);
        [$cpu, $memory] = $this->cpuMemoryForSite($site);
        $result = $service->createService(
            serviceName: $this->backendServiceName($site),
            image: (string) $site->container_image,
            port: (int) ($site->container_port ?: 8080),
            envVars: $this->siteEnvVars($site),
            cpu: $cpu,
            memory: $memory,
        );

        return [
            'backend_id' => $result['service_arn'],
            'live_url' => $result['service_url'],
        ];
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function provisionFromSource(Site $site, ProviderCredential $credential): array
    {
        $source = $this->sourceSpec($site);
        $connectionArn = $this->connectionArn($credential);

        $service = new AwsAppRunnerService($credential, $site->container_region ?: null);
        [$cpu, $memory] = $this->cpuMemoryForSite($site);
        $result = $service->createServiceFromSource(
            serviceName: $this->backendServiceName($site),
            repositoryUrl: $source['repository_url'],
            branch: $source['branch'],
            connectionArn: $connectionArn,
            port: (int) ($site->container_port ?: 8080),
            envVars: $this->siteEnvVars($site),
            buildEnvVars: $this->siteBuildEnvVars($site),
            dockerfilePath: $source['dockerfile_path'],
            autoDeploy: $source['deploy_on_push'],
            cpu: $cpu,
            memory: $memory,
        );

        return [
            'backend_id' => $result['service_arn'],
            'live_url' => $result['service_url'],
        ];
    }

    /**
     * @return array{repository_url: string, branch: string, dockerfile_path: ?string, deploy_on_push: bool}
     */
    private function sourceSpec(Site $site): array
    {
        $meta = ($site->meta );
        $source = $meta['container']['source'] ?? [];
        if (! is_array($source) || ! is_string($source['repo'] ?? null) || $source['repo'] === '') {
            throw new \RuntimeException('Site has no container source spec recorded — cannot provision from source.');
        }

        $repo = (string) $source['repo'];
        $repositoryUrl = str_starts_with($repo, 'http') ? $repo : 'https://github.com/'.$repo;

        return [
            'repository_url' => $repositoryUrl,
            'branch' => is_string($source['branch'] ?? null) && $source['branch'] !== '' ? (string) $source['branch'] : 'main',
            'dockerfile_path' => is_string($source['dockerfile_path'] ?? null) && $source['dockerfile_path'] !== '' ? (string) $source['dockerfile_path'] : null,
            'deploy_on_push' => (bool) ($source['deploy_on_push'] ?? true),
        ];
    }

    private function connectionArn(ProviderCredential $credential): string
    {
        $arn = $credential->credentials['github_connection_arn'] ?? null;
        if (! is_string($arn) || $arn === '') {
            throw new \RuntimeException('AWS App Runner credential has no github_connection_arn — connect a GitHub App in App Runner first.');
        }

        return $arn;
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function redeploy(Site $site, ProviderCredential $credential): array
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return ['deployment_id' => null];
        }

        $service = new AwsAppRunnerService($credential, $site->container_region ?: null);
        $result = $service->startDeployment($site->container_backend_id);

        return ['deployment_id' => $result['operation_id']];
    }

    public function updateImage(Site $site, ProviderCredential $credential, string $image): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }

        (new AwsAppRunnerService($credential, $site->container_region ?: null))->updateImage(
            $site->container_backend_id,
            $image,
            (int) ($site->container_port ?: 8080),
            $this->siteEnvVars($site),
        );
    }

    public function updateEnvVars(Site $site, ProviderCredential $credential): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }

        $service = new AwsAppRunnerService($credential, $site->container_region ?: null);
        $envVars = $this->siteEnvVars($site);

        if (is_array($site->meta['container']['source'] ?? null)) {
            // Source mode — re-push the CodeRepository spec with the
            // new runtime + build env vars while keeping repo / branch / dockerfile.
            $source = $this->sourceSpec($site);
            $service->updateServiceSourceEnv(
                serviceArn: $site->container_backend_id,
                repositoryUrl: $source['repository_url'],
                branch: $source['branch'],
                connectionArn: $this->connectionArn($credential),
                port: (int) ($site->container_port ?: 8080),
                envVars: $envVars,
                buildEnvVars: $this->siteBuildEnvVars($site),
                dockerfilePath: $source['dockerfile_path'],
            );

            return;
        }

        // Image mode — updateImage already re-pushes env vars alongside
        // the (unchanged) image.
        if (($site->container_image) && $site->container_image !== '') {
            $service->updateImage(
                $site->container_backend_id,
                $site->container_image,
                (int) ($site->container_port ?: 8080),
                $envVars,
            );
        }
    }

    public function teardown(Site $site, ProviderCredential $credential): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }

        try {
            (new AwsAppRunnerService($credential, $site->container_region ?: null))
                ->deleteService($site->container_backend_id);
        } catch (\Throwable) {
            // Idempotent.
        }
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function inspect(Site $site, ProviderCredential $credential): array
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return ['phase' => 'unknown', 'live_url' => null, 'raw' => []];
        }

        $svc = (new AwsAppRunnerService($credential, $site->container_region ?: null))
            ->describeService($site->container_backend_id);
        $url = isset($svc['ServiceUrl']) ? 'https://'.$svc['ServiceUrl'] : null;

        return [
            'phase' => (string) ($svc['Status'] ?? 'unknown'),
            'live_url' => $url,
            'raw' => $svc,
        ];
    }

    /** @return array<string, mixed> */
    /**
     * @return list<array<string, string>>
     */
    public function regions(): array
    {
        return AwsAppRunnerService::getRegions();
    }

    /** @return list<array<string, string>>
    /** @return list<array<string, string>>
    /**
     * @return array<int, array<string, string|null>>
     */
    public function recentDeployments(Site $site, ProviderCredential $credential, int $limit = 10): array
    {
        // App Runner exposes ListOperations on a service for full
        // deploy history; we keep this minimal — the CLI surface is
        // primarily used against DigitalOcean (where deployments are
        // first-class). For AWS, return a single synthetic "latest"
        // entry derived from local meta so the CLI / dashboard show
        // something instead of an empty list.
        $meta = ($site->meta );
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $startedAt = is_string($container['last_deploy_started_at'] ?? null) ? (string) $container['last_deploy_started_at'] : null;
        $deploymentId = is_string($container['last_deployment_id'] ?? null) ? (string) $container['last_deployment_id'] : null;

        if ($deploymentId === null && $startedAt === null) {
            return [];
        }

        return [[
            'id' => $deploymentId ?? 'unknown',
            'phase' => 'UNKNOWN',
            'started_at' => $startedAt,
            'finished_at' => null,
            'cause' => 'aws_app_runner',
        ]];
    }

    /** @return array<int, array<string, string|null>>
    /** @return array<int, array<string, string|null>>
    /**
     * @return array<int, array<string, string|null>>
     */
    public function latestDeploymentLogs(Site $site, ProviderCredential $credential): array
    {
        // App Runner streams logs to CloudWatch under
        // /aws/apprunner/{service-name}/{revision}/{application,service}.
        // We don't fetch them through the App Runner API (no equivalent
        // of DO's signed-URL endpoint); the operator goes to CloudWatch
        // directly. Surface the LogGroup hint instead.
        $serviceName = $this->backendServiceName($site);

        return [
            'content' => null,
            'url' => null,
            'message' => sprintf(
                'AWS App Runner streams logs to CloudWatch under /aws/apprunner/%s/<revision>/{application,service}. Open the AWS console for live tailing.',
                $serviceName,
            ),
        ];
    }

    /**
     * App Runner publishes CPU / memory / request metrics to
     * CloudWatch under the AWS/AppRunner namespace, not over the App
     * Runner API. v1 does not fetch them through the CloudWatch SDK —
     * deep CloudWatch integration is deferred — so this returns the
     * structured unavailable state with a CloudWatch console deep
     * link the operator can open instead.
     */
    /** @return array<string, mixed> */
    public function metrics(Site $site, ProviderCredential $credential, string $window): array
    {
        $window = $this->normalizeWindow($window);

        return [
            'window' => $window,
            'series' => ['cpu' => [], 'memory' => [], 'requests' => []],
            'available' => false,
            'note' => 'AWS App Runner publishes CPU, memory, and request metrics to CloudWatch '
                .'under the AWS/AppRunner namespace. Open the CloudWatch console for live charts.',
            'url' => $this->cloudWatchMetricsUrl($site),
        ];
    }

    /**
     * App Runner streams runtime (application) logs to CloudWatch
     * Logs under /aws/apprunner/{service}/{revision}/application.
     * v1 does not tail them through the CloudWatch Logs SDK — the
     * operator opens the CloudWatch console via the returned link.
     */
    /** @return array<string, mixed> */
    public function runtimeLogs(Site $site, ProviderCredential $credential, int $lines = 200, string $component = 'web'): array
    {
        $serviceName = $this->backendServiceName($site);

        return [
            'lines' => [],
            'available' => false,
            'url' => $this->cloudWatchLogsUrl($site),
            'note' => sprintf(
                'AWS App Runner streams runtime logs to CloudWatch under '
                .'/aws/apprunner/%s/<revision>/application. Open the CloudWatch console for live tailing.',
                $serviceName,
            ),
        ];
    }

    /**
     * Deep link to the CloudWatch Logs console for the service's
     * App Runner application log group. Region is best-effort —
     * container_region is the App Runner region.
     */
    private function cloudWatchLogsUrl(Site $site): string
    {
        $region = $site->container_region ?: 'us-east-1';
        $serviceName = $this->backendServiceName($site);
        $logGroup = '/aws/apprunner/'.$serviceName.'/application';

        return sprintf(
            'https://%s.console.aws.amazon.com/cloudwatch/home?region=%s#logsV2:log-groups/log-group/%s',
            rawurlencode($region),
            rawurlencode($region),
            rawurlencode($logGroup),
        );
    }

    /**
     * Deep link to the CloudWatch metrics console scoped to the
     * AWS/AppRunner namespace for this service.
     */
    private function cloudWatchMetricsUrl(Site $site): string
    {
        $region = $site->container_region ?: 'us-east-1';

        return sprintf(
            'https://%s.console.aws.amazon.com/cloudwatch/home?region=%s#metricsV2:graph=~();namespace=~AWS*2fAppRunner',
            rawurlencode($region),
            rawurlencode($region),
        );
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function attachDomain(Site $site, ProviderCredential $credential, string $hostname): array
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return [];
        }

        return (new AwsAppRunnerService($credential, $site->container_region ?: null))
            ->associateCustomDomain($site->container_backend_id, $hostname);
    }

    public function detachDomain(Site $site, ProviderCredential $credential, string $hostname): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }
        try {
            (new AwsAppRunnerService($credential, $site->container_region ?: null))
                ->disassociateCustomDomain($site->container_backend_id, $hostname);
        } catch (\Throwable) {
            // Idempotent — already gone is fine.
        }
    }

    /**
     * @return array<string, string>
     */
    private function siteEnvVars(Site $site): array
    {
        return $this->parseEnvLines((string) ($site->env_file_content ?? ''));
    }

    /**
     * Build-time env vars are stored separately on the Site's meta
     * under meta.container.build_env_file_content. They map to App
     * Runner's BuildEnvironmentVariables — needed for app secrets
     * (e.g. private package tokens) that the build needs but
     * shouldn't leak into runtime.
     *
     * @return array<string, string>
     */
    private function siteBuildEnvVars(Site $site): array
    {
        $meta = ($site->meta );
        $content = $meta['container']['build_env_file_content'] ?? '';

        return $this->parseEnvLines(is_string($content) ? $content : '');
    }

    /**
     * Desired instance count for the site. AWS App Runner uses
     * AutoScalingConfiguration ARNs for full scaling control; this
     * value is the "intent" the operator sets via dply:cloud:scale,
     * surfaced in the dashboard / CLI even when the live App Runner
     * config differs.
     */
    private function siteInstanceCount(Site $site): int
    {
        $meta = ($site->meta );
        $raw = $meta['container']['instance_count'] ?? null;

        return is_int($raw) && $raw > 0 ? $raw : 1;
    }

    /**
     * Map the site's portable size_tier (small / medium / large /
     * xlarge) to App Runner's [Cpu, Memory] pair (string slugs).
     * Defaults to "small" → 256/512 (the smallest App Runner combo).
     *
     * @return array{0: string, 1: string}
     */
    private function cpuMemoryForSite(Site $site): array
    {
        $meta = ($site->meta );
        $tier = (string) ($meta['container']['size_tier'] ?? 'small');

        // AWS App Runner has one compute axis (CPU + RAM combo) and no
        // Basic/Pro split — the dply Pro suffix is a DO concept. We map
        // each `*-pro` value to the same CPU/RAM combo as its Basic peer
        // so swapping backends doesn't accidentally change AWS sizing.
        return match ($tier) {
            'medium', 'medium-pro' => ['512', '1024'],
            'large', 'large-pro' => ['1024', '2048'],
            'xlarge', 'xlarge-pro' => ['2048', '4096'],
            default => ['256', '512'],
        };
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvLines(string $envContent): array
    {
        if ($envContent === '') {
            return [];
        }
        $vars = [];
        foreach (explode("\n", $envContent) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1), " \t\"'");
            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    private function backendServiceName(Site $site): string
    {
        // App Runner service names: alnum + hyphen + underscore,
        // 4–40 chars, must start with a letter.
        $name = preg_replace('/[^a-z0-9_-]/i', '-', strtolower($site->slug ?: $site->name ?: 'dply-app'));
        $name = trim((string) $name, '-_');
        if (! preg_match('/^[a-z]/', $name)) {
            $name = 'dply-'.$name;
        }
        $name = substr($name, 0, 40);
        if (strlen($name) < 4) {
            $name = 'dply-'.$name;
        }

        return $name;
    }
}
