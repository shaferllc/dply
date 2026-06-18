<?php

namespace App\Modules\Deploy\Services;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Modules\Serverless\Services\ServerlessFunctionDnsProvisioner;
use Illuminate\Support\Facades\Http;

final class DigitalOceanFunctionsActionDeployer
{
    public function __construct(
        private readonly DigitalOceanFunctionsArtifactBuilder $artifactBuilder,
        private readonly ServerlessDeploymentConfigResolver $deploymentConfigResolver,
        private readonly DeploymentContractBuilder $contractBuilder,
        private readonly DeploymentRevisionTracker $revisionTracker,
        private readonly ServerlessDeployProgress $progress,
        private readonly ServerlessFunctionDnsProvisioner $dnsProvisioner,
        private readonly ServerlessDeployHookRunner $hookRunner,
    ) {}

    /**
     * @return array{output: string, revision_id: ?string, url: ?string}
     */
    /** @return array<string, mixed> */
    public function deploy(Site $site): array
    {
        $site->loadMissing('server', 'domains');
        $this->assertFunctionsHost($site->server);

        $buildResult = $this->artifactBuilder->build($site);
        $result = $this->pushArtifact($site, $buildResult['artifact_path'], $buildResult['output']);

        // after_activate hooks — the action is uploaded and smoke-tested, so
        // the function is live; run post-deploy shell (warm-up, notify, …).
        $hookLog = $this->runActivateHooks($site, $buildResult['working_directory']);
        if ($hookLog !== '') {
            $result['output'] .= "\n".$hookLog;
        }

        return $result;
    }

    /**
     * Run after_activate deploy hooks as a journey sub-step, returning the
     * transcript (empty when the site configures none).
     */
    private function runActivateHooks(Site $site, string $workingDirectory): string
    {
        $site->loadMissing('deployHooks');
        if ($site->deployHooks->where('phase', SiteDeployHook::PHASE_AFTER_ACTIVATE)->isEmpty()) {
            return '';
        }

        $label = ServerlessDeployHookRunner::PHASE_LABELS[SiteDeployHook::PHASE_AFTER_ACTIVATE].' hooks';
        $this->progress->active($site, 'hooks_activate', $label);
        $output = $this->hookRunner->runPhase($site, SiteDeployHook::PHASE_AFTER_ACTIVATE, $workingDirectory);
        $this->progress->done($site, 'hooks_activate', $label);

        return $output;
    }

    /**
     * Re-deploy a previously built artifact without rebuilding — the rollback
     * path. The artifact is one recorded in `serverless.artifact_history`.
     *
     * @return array{output: string, revision_id: ?string, url: ?string}
     */
    /** @return array<string, mixed> */
    public function redeployArtifact(Site $site, string $artifactPath): array
    {
        $site->loadMissing('server', 'domains');
        $this->assertFunctionsHost($site->server);

        return $this->pushArtifact($site, $artifactPath, 'Rollback — re-deploying a previous artifact.');
    }

    private function assertFunctionsHost(?Server $server): void
    {
        if (! $server instanceof Server || ! $server->isDigitalOceanFunctionsHost()) {
            throw new \RuntimeException('DigitalOcean Functions deploy requires a Functions-backed host.');
        }
    }

    /**
     * Upload an artifact zip to the OpenWhisk action and record the result.
     * Shared by a fresh deploy and a rollback.
     *
     * @return array{output: string, revision_id: ?string, url: ?string}
     */
    private function pushArtifact(Site $site, string $artifactPath, string $buildOutput): array
    {
        $server = $site->server;
        $serverMeta = is_array($server->meta) ? $server->meta : [];
        $hostConfig = is_array($serverMeta['digitalocean_functions'] ?? null) ? $serverMeta['digitalocean_functions'] : [];
        $siteMeta = ($site->meta );

        $apiHost = rtrim((string) ($hostConfig['api_host'] ?? ''), '/');
        $namespace = trim((string) ($hostConfig['namespace'] ?? ''));
        $accessKey = trim((string) ($hostConfig['access_key'] ?? ''));

        if ($apiHost === '' || $namespace === '' || $accessKey === '') {
            throw new \RuntimeException('DigitalOcean Functions host metadata is incomplete. Save API host, namespace, and access key first.');
        }

        // Resolve runtime config AFTER the build — the artifact builder
        // detects the framework and persists the corrected runtime / entry
        // function (e.g. the Laravel adapter's `main`). Reading it earlier
        // would use the pre-build placeholder and deploy a wrong `main`.
        $resolvedConfig = $this->deploymentConfigResolver->resolve($site->fresh() ?? $site);
        $package = trim((string) ($resolvedConfig['package'] ?? 'default'));
        $kind = trim((string) ($resolvedConfig['runtime'] ?? 'nodejs:18'));
        $entrypoint = trim((string) ($resolvedConfig['entrypoint'] ?? 'main')) ?: 'main';

        if (! str_ends_with(strtolower($artifactPath), '.zip')) {
            throw new \RuntimeException('DigitalOcean Functions deploy expects a .zip artifact.');
        }

        $realArtifactPath = realpath($artifactPath);
        if ($realArtifactPath === false || ! is_file($realArtifactPath) || ! is_readable($realArtifactPath)) {
            throw new \RuntimeException('Artifact zip is missing or unreadable: '.$artifactPath);
        }

        [$keyId, $keySecret] = $this->splitAccessKey($accessKey);
        $actionName = $this->actionName($site);
        $url = $this->actionPutUrl($apiHost, $package, $actionName);
        $bytes = file_get_contents($realArtifactPath);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Artifact zip is empty or unreadable.');
        }

        // Resource limits are operator-controlled on the Runtime tab and
        // stored in meta.serverless.limits. serverlessLimits() fills in the
        // platform defaults (512MB / 60s / concurrency 1) when unset — a
        // framework cold start needs far more than OpenWhisk's stock 3s/256MB.
        $limits = $site->serverlessLimits();

        $this->progress->active($site, 'upload', 'Uploading to DigitalOcean Functions', 'Namespace '.$namespace);
        $response = Http::withBasicAuth($keyId, $keySecret)
            ->timeout(300)
            ->acceptJson()
            ->put($url.'?overwrite=true', [
                'exec' => [
                    'kind' => $kind,
                    'binary' => true,
                    'code' => base64_encode($bytes),
                    'main' => $entrypoint,
                ],
                // Without web-export the action exists but is not reachable
                // over HTTP — the invocation URL would 404.
                'annotations' => [
                    ['key' => 'web-export', 'value' => true],
                ],
                'limits' => [
                    'timeout' => $limits['timeout'],
                    'memory' => $limits['memory'],
                    'concurrency' => $limits['concurrency'],
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('DigitalOcean Functions deploy failed: HTTP '.$response->status().' '.$response->body());
        }

        $this->progress->done($site, 'upload', 'Uploaded to DigitalOcean Functions');

        $json = $response->json();
        $revisionId = is_array($json) && isset($json['version']) ? (string) $json['version'] : null;

        $functionsConfig = $site->serverlessConfig();

        // Keep the operator-configured number of artifacts so a bad deploy can
        // be rolled back to a known-good one without rebuilding. `releases_to_keep`
        // is the same keep-count knob VM deploys use (1–50, default 5) — respect
        // it so people who raise it don't lose rollback targets.
        $keep = max(1, min(50, (int) ($site->releases_to_keep ?? 5)));
        $history = is_array($functionsConfig['artifact_history'] ?? null) ? $functionsConfig['artifact_history'] : [];
        array_unshift($history, [
            'artifact_path' => $realArtifactPath,
            'revision_id' => $revisionId,
            'deployed_at' => now()->toIso8601String(),
        ]);
        $history = array_slice($history, 0, $keep);

        $siteMeta['serverless'] = array_merge($functionsConfig, [
            'target' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'package' => $package,
            'runtime' => $kind,
            'entrypoint' => $entrypoint,
            'artifact_path' => $realArtifactPath,
            'action_name' => $actionName,
            'last_deployed_at' => now()->toIso8601String(),
            'last_revision_id' => $revisionId,
            'action_url' => $this->actionWebUrl($apiHost, $namespace, $package, $actionName),
            'artifact_history' => $history,
            // Snapshot the limits actually pushed to OpenWhisk so the Runtime
            // tab can show when saved limits are pending a redeploy.
            'deployed_limits' => $limits,
        ]);

        $site->forceFill(['meta' => $siteMeta])->save();

        // Local cleanup: keep only the retained rollback set on disk so the
        // artifact dir stops growing by one zip per deploy. Keyed strictly off
        // the history we just saved — every rollback-listed zip stays, so no
        // rollback breaks. Best-effort; never fail a completed deploy on it.
        try {
            $this->artifactBuilder->pruneArtifactsExcept($site, array_column($history, 'artifact_path'));
        } catch (\Throwable) {
            // ignore — the daily prune is the backstop
        }

        $this->revisionTracker->markApplied($site->fresh(), $this->contractBuilder->build($site->fresh())->revision(), 'runtime');

        // Point the function's friendly hostname ({slug}.{testing-domain}) at
        // the dply app so it resolves. The app proxies through to the raw DO
        // Functions URL — DO Functions itself has no custom-domain support.
        $dnsStatus = $this->dnsProvisioner->provision($site);

        // Smoke-test the freshly deployed function so a broken runtime is
        // caught here — and shown on the deploy journey — instead of by the
        // operator hitting the URL. The deploy itself still succeeds: the
        // action IS deployed; this only reports whether it answers.
        $health = $this->smokeTest($site, (string) $siteMeta['serverless']['action_url']);

        return [
            'output' => implode("\n", array_filter([
                $buildOutput !== '' ? $buildOutput : null,
                'DigitalOcean Functions deploy completed.',
                'Namespace: '.$namespace,
                'Package: '.$package,
                'Action: '.$actionName,
                'Runtime: '.$kind,
                $revisionId ? 'Revision: '.$revisionId : null,
                $dnsStatus,
                'Health check: '.$health,
            ])),
            'revision_id' => $revisionId,
            'url' => $siteMeta['serverless']['action_url'],
        ];
    }

    public function delete(Site $site): void
    {
        $site->loadMissing('server');

        $server = $site->server;
        if (! $server instanceof Server || ! $server->isDigitalOceanFunctionsHost()) {
            return;
        }

        $serverMeta = is_array($server->meta) ? $server->meta : [];
        $hostConfig = is_array($serverMeta['digitalocean_functions'] ?? null) ? $serverMeta['digitalocean_functions'] : [];
        $resolvedConfig = $this->deploymentConfigResolver->resolve($site);

        $apiHost = rtrim((string) ($hostConfig['api_host'] ?? ''), '/');
        $namespace = trim((string) ($hostConfig['namespace'] ?? ''));
        $accessKey = trim((string) ($hostConfig['access_key'] ?? ''));
        $package = trim((string) ($resolvedConfig['package'] ?? 'default'));
        $actionName = trim((string) ($site->serverlessConfig()['action_name'] ?? $this->actionName($site)));

        if ($apiHost === '' || $namespace === '' || $accessKey === '' || $actionName === '') {
            return;
        }

        [$keyId, $keySecret] = $this->splitAccessKey($accessKey);
        $response = Http::withBasicAuth($keyId, $keySecret)
            ->timeout(120)
            ->acceptJson()
            ->delete($this->actionPutUrl($apiHost, $package, $actionName));

        if (! $response->successful() && $response->status() !== 404) {
            throw new \RuntimeException('DigitalOcean Functions delete failed: HTTP '.$response->status().' '.$response->body());
        }
    }

    /**
     * GET the freshly deployed function once and record the result as a
     * `verify` journey sub-step. A 2xx/3xx means it boots and answers; any
     * other status — or an unreachable host — is surfaced. This never fails
     * the deploy: the action IS deployed; this only reports whether it runs.
     */
    private function smokeTest(Site $site, string $actionUrl): string
    {
        if ($actionUrl === '') {
            return 'skipped (no invocation URL).';
        }

        $this->progress->active($site, 'verify', 'Verifying the function');

        try {
            $status = Http::timeout(30)->get($actionUrl)->status();
        } catch (\Throwable $e) {
            $this->progress->step($site, 'verify', 'Function is unreachable', ServerlessDeployProgress::STATE_FAILED, $e->getMessage());

            return 'unreachable — '.$e->getMessage();
        }

        if ($status >= 200 && $status < 400) {
            $this->progress->done($site, 'verify', 'Function responded', 'HTTP '.$status);

            return 'HTTP '.$status.' — function is responding.';
        }

        $this->progress->step($site, 'verify', 'Function returned an error', ServerlessDeployProgress::STATE_FAILED, 'HTTP '.$status);

        return 'HTTP '.$status.' — function deployed but is returning an error.';
    }

    private function actionName(Site $site): string
    {
        $base = trim((string) ($site->slug ?: $site->name));
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: 'site';
        $base = trim($base, '-');

        return $base !== '' ? $base : 'site';
    }

    /**
     * The OpenWhisk action-management endpoint. The namespace is resolved
     * from the auth credentials, so the REST path uses the `_` placeholder —
     * not the literal namespace name (which 404s). An action with no package
     * lives in the implicit default package and takes no package segment;
     * the literal package name "default" is treated the same.
     */
    private function actionPutUrl(string $apiHost, string $package, string $actionName): string
    {
        $actionName = rawurlencode($actionName);

        $path = ($package === '' || $package === 'default')
            ? $actionName
            : rawurlencode($package).'/'.$actionName;

        return $apiHost.'/api/v1/namespaces/_/actions/'.$path;
    }

    /**
     * The public web-action invocation URL. Unlike the management endpoint,
     * this one carries the real namespace and always names a package —
     * default-package actions sit under the literal "default" segment.
     */
    private function actionWebUrl(string $apiHost, string $namespace, string $package, string $actionName): string
    {
        $namespace = rawurlencode($namespace);
        $actionName = rawurlencode($actionName);
        $packageSegment = rawurlencode($package !== '' ? $package : 'default');

        return $apiHost.'/api/v1/web/'.$namespace.'/'.$packageSegment.'/'.$actionName;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitAccessKey(string $accessKey): array
    {
        if (! str_contains($accessKey, ':')) {
            throw new \RuntimeException('DigitalOcean Functions access key must use `id:secret` format.');
        }

        [$id, $secret] = explode(':', $accessKey, 2);
        $id = trim($id);
        $secret = trim($secret);

        if ($id === '' || $secret === '') {
            throw new \RuntimeException('DigitalOcean Functions access key id and secret must both be present.');
        }

        return [$id, $secret];
    }
}
