<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\FunctionAction;
use App\Models\Site;
use App\Modules\Serverless\Support\FunctionConfiguration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deploys the *other* functions a repository declares.
 *
 * A DigitalOcean Functions project is a set of packages, each holding many
 * functions — `project.yml` and the `functions/` convention both describe
 * several. dply's deploy has always pushed exactly one action per Site, so
 * everything past the first was discovered, listed, and then never deployed.
 *
 * This runs after the primary action is up, using the same checkout: each
 * additional action is zipped from its own subdirectory and PUT as its own
 * OpenWhisk action, and every action — primary included — is recorded as a
 * {@see FunctionAction} row so the workspace's action list matches what is
 * actually deployed.
 *
 * One action failing does not fail the deploy: the primary is already live,
 * and losing it over a broken sibling would be a worse outcome than a partial
 * deploy that says so.
 *
 * @see https://docs.digitalocean.com/products/functions/how-to/structure-projects/
 */
final class ServerlessMultiActionDeployer
{
    public function __construct(
        private readonly DigitalOceanFunctionsArtifactBuilder $artifactBuilder,
        private readonly ServerlessActionDiscovery $discovery,
        private readonly ServerlessTargetCapabilityResolver $capabilityResolver,
        private readonly ServerlessDeployProgress $progress,
    ) {}

    /**
     * @param  array{api_host: string, namespace: string, access_key: string, package: string, primary_action: string, primary_runtime: string, primary_entrypoint: string, primary_url: string}  $context
     * @return array{deployed: list<string>, failed: list<string>, output: string}
     */
    public function deploy(Site $site, string $workingDirectory, array $context): array
    {
        $limits = Site::normalizeServerlessLimits($site->serverlessLimits());

        $this->recordAction($site, [
            'name' => $context['primary_action'],
            'runtime' => $context['primary_runtime'],
            'entrypoint' => $context['primary_entrypoint'],
            'source_subdir' => '',
        ], $limits, $context['primary_url']);

        $additional = $this->additionalActions($site, $workingDirectory, $context['primary_action']);

        if ($additional === []) {
            return ['deployed' => [], 'failed' => [], 'output' => ''];
        }

        $this->progress->active($site, 'actions', 'Deploying additional functions', count($additional).' declared');

        $deployed = [];
        $failed = [];
        $log = [];

        foreach ($additional as $descriptor) {
            $name = (string) $descriptor['name'];

            try {
                $url = $this->deployOne($site, $workingDirectory, $descriptor, $context, $limits);
                $this->recordAction($site, $descriptor, $limits, $url);
                $deployed[] = $name;
                $log[] = 'Deployed additional function: '.$name;
            } catch (Throwable $e) {
                $failed[] = $name;
                $log[] = 'Failed to deploy additional function '.$name.': '.$e->getMessage();
                Log::warning('serverless.multi_action.failed', [
                    'site_id' => $site->id,
                    'action' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($failed === []) {
            $this->progress->done($site, 'actions', 'Deployed additional functions', implode(', ', $deployed));
        } else {
            $this->progress->step(
                $site,
                'actions',
                'Some additional functions failed',
                ServerlessDeployProgress::STATE_FAILED,
                implode(', ', $failed),
            );
        }

        return ['deployed' => $deployed, 'failed' => $failed, 'output' => implode("\n", $log)];
    }

    /**
     * Every discovered action except the primary one, which the main deployer
     * has already pushed from the repo root.
     *
     * @return list<array<string, mixed>>
     */
    private function additionalActions(Site $site, string $workingDirectory, string $primaryAction): array
    {
        $descriptors = $this->discovery->discover(
            $workingDirectory,
            $this->capabilityResolver->forSite($site),
        );

        $additional = [];
        $seen = [$primaryAction => true];

        foreach ($descriptors as $descriptor) {
            $name = trim((string) ($descriptor['name'] ?? ''));
            $subdir = trim((string) ($descriptor['source_subdir'] ?? ''));

            // A nameless descriptor is the single-action case (the primary),
            // and one without its own directory has nothing separate to zip.
            if ($name === '' || $subdir === '' || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $additional[] = $descriptor;
        }

        return $additional;
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @param  array<string, mixed>  $context
     * @param  array{memory: int, timeout: int, concurrency: int, logs: int}  $limits
     * @return string The deployed invocation URL.
     */
    private function deployOne(Site $site, string $workingDirectory, array $descriptor, array $context, array $limits): string
    {
        $name = $this->sanitizeActionName((string) $descriptor['name']);
        $package = trim((string) ($descriptor['package'] ?? '')) ?: (string) $context['package'];
        $runtime = trim((string) ($descriptor['runtime'] ?? '')) ?: (string) $context['primary_runtime'];
        $entrypoint = trim((string) ($descriptor['entrypoint'] ?? '')) ?: 'main';

        $artifactPath = $this->artifactBuilder->packageAction(
            $site,
            $workingDirectory,
            (string) $descriptor['source_subdir'],
            $name,
            is_array($descriptor['include'] ?? null) ? $descriptor['include'] : [],
            is_array($descriptor['exclude'] ?? null) ? $descriptor['exclude'] : [],
        );

        $bytes = file_get_contents($artifactPath);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Packaged artifact is empty.');
        }

        [$keyId, $keySecret] = $this->splitAccessKey((string) $context['access_key']);
        $apiHost = rtrim((string) $context['api_host'], '/');

        $this->ensurePackage($apiHost, $keyId, $keySecret, $package);

        // The repo's manifest owns this action's exposure and bindings where
        // it states them; the Site's settings fill in the rest.
        $configuration = new FunctionConfiguration(
            webMode: (string) ($descriptor['web_mode'] ?? FunctionConfiguration::MODE_WEB),
            parameters: $this->boundParameters($descriptor),
        );

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->timeout(300)
            ->acceptJson()
            ->put($this->actionUrl($apiHost, $package, $name).'?overwrite=true', array_filter([
                'exec' => [
                    'kind' => $runtime,
                    'binary' => true,
                    'code' => base64_encode($bytes),
                    'main' => $entrypoint,
                ],
                'annotations' => array_merge(
                    $configuration->annotations(),
                    $this->manifestAnnotations($descriptor),
                ),
                'parameters' => $configuration->parameterPairs(),
                'limits' => $this->resolveLimits($descriptor, $limits),
            ], static fn (array $value): bool => $value !== []));

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status().' '.$response->body());
        }

        return $this->webUrl($apiHost, (string) $context['namespace'], $package, $name);
    }

    /**
     * The parameters bound to this action: the manifest's `parameters` plus
     * its `environment`, which OpenWhisk has no separate concept for — an
     * environment variable on a function IS a bound parameter.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<string, mixed>
     */
    private function boundParameters(array $descriptor): array
    {
        $parameters = is_array($descriptor['parameters'] ?? null) ? $descriptor['parameters'] : [];
        $environment = is_array($descriptor['environment'] ?? null) ? $descriptor['environment'] : [];

        // Explicit parameters win over environment on a name collision — the
        // more specific declaration should be the one that lands.
        return array_merge($environment, $parameters);
    }

    /**
     * Manifest annotations beyond the ones the exposure config already emits.
     * dply's own annotations are authoritative, so a manifest cannot
     * accidentally un-export a web action dply just exported.
     *
     * @param  array<string, mixed>  $descriptor
     * @return list<array{key: string, value: mixed}>
     */
    private function manifestAnnotations(array $descriptor): array
    {
        $reserved = ['web-export', 'raw-http', 'web-custom-options', 'final'];
        $annotations = is_array($descriptor['annotations'] ?? null) ? $descriptor['annotations'] : [];

        $out = [];
        foreach ($annotations as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || in_array($key, $reserved, true)) {
                continue;
            }

            $out[] = ['key' => $key, 'value' => $value];
        }

        return $out;
    }

    /**
     * Manifest limits override the Site's, per key — a manifest that sets
     * only `memory` should not also reset the timeout to a default.
     *
     * @param  array<string, mixed>  $descriptor
     * @param  array{memory: int, timeout: int, concurrency: int, logs: int}  $siteLimits
     * @return array<string, int>
     */
    private function resolveLimits(array $descriptor, array $siteLimits): array
    {
        $manifest = is_array($descriptor['limits'] ?? null) ? $descriptor['limits'] : [];

        return [
            'timeout' => (int) ($manifest['timeout'] ?? $siteLimits['timeout']),
            'memory' => (int) ($manifest['memory'] ?? $siteLimits['memory']),
            'concurrency' => (int) ($manifest['concurrency'] ?? $siteLimits['concurrency']),
            'logs' => (int) ($manifest['logs'] ?? $siteLimits['logs'] ?? Site::SERVERLESS_DEFAULT_LOGS_KB),
        ];
    }

    /**
     * OpenWhisk will not accept an action into a package that does not exist,
     * and a manifest routinely names one that has never been created. Creating
     * it is idempotent, so this runs unconditionally rather than probing first.
     */
    private function ensurePackage(string $apiHost, string $keyId, string $keySecret, string $package): void
    {
        if ($package === '' || $package === 'default') {
            return;
        }

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->timeout(60)
            ->acceptJson()
            ->put($apiHost.'/api/v1/namespaces/_/packages/'.rawurlencode($package).'?overwrite=true', [
                'annotations' => [['key' => 'managed-by', 'value' => 'dply']],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Could not create package "'.$package.'": HTTP '.$response->status());
        }
    }

    /**
     * Upsert the row describing this action. Keyed on (site, name), which is
     * the table's own uniqueness rule.
     *
     * @param  array<string, mixed>  $descriptor
     * @param  array{memory: int, timeout: int, concurrency: int, logs: int}  $limits
     */
    private function recordAction(Site $site, array $descriptor, array $limits, string $url): void
    {
        $name = trim((string) ($descriptor['name'] ?? ''));
        if ($name === '') {
            return;
        }

        FunctionAction::query()->updateOrCreate(
            ['site_id' => $site->id, 'name' => $name],
            [
                'kind' => FunctionAction::KIND_CODE,
                'runtime' => (string) ($descriptor['runtime'] ?? ''),
                'entrypoint' => (string) ($descriptor['entrypoint'] ?? 'main'),
                'memory_mb' => $limits['memory'],
                'timeout_ms' => $limits['timeout'],
                'concurrency' => $limits['concurrency'],
                'url' => $url !== '' ? $url : null,
                'meta' => [
                    'source_subdir' => (string) ($descriptor['source_subdir'] ?? ''),
                    'discovered_via' => (string) ($descriptor['source'] ?? 'deploy'),
                    'last_deployed_at' => now()->toIso8601String(),
                ],
            ],
        );
    }

    private function actionUrl(string $apiHost, string $package, string $name): string
    {
        $path = ($package === '' || $package === 'default')
            ? rawurlencode($name)
            : rawurlencode($package).'/'.rawurlencode($name);

        return $apiHost.'/api/v1/namespaces/_/actions/'.$path;
    }

    /** The same shape the primary action's URL takes — an addressable web action. */
    private function webUrl(string $apiHost, string $namespace, string $package, string $name): string
    {
        return rtrim($apiHost, '/').'/api/v1/web/'
            .rawurlencode($namespace).'/'
            .rawurlencode($package !== '' ? $package : 'default').'/'
            .rawurlencode($name);
    }

    private function sanitizeActionName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || ! preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            throw new \RuntimeException('Invalid action name: '.$name);
        }

        return $name;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitAccessKey(string $raw): array
    {
        $raw = trim($raw);
        if (! str_contains($raw, ':')) {
            throw new \RuntimeException('The host access key is malformed.');
        }

        [$id, $secret] = explode(':', $raw, 2);

        return [trim($id), trim($secret)];
    }
}
