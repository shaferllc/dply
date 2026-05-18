<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\Kubernetes;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Deploy\DeployContext;
use App\Services\Deploy\KubernetesDeployEngine;
use App\Services\Deploy\KubernetesKubectlExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end test that pins the glue between {@see \App\Jobs\PollDoksClusterStatusJob}
 * (which writes kubeconfig YAML into server.meta) and {@see KubernetesDeployEngine}
 * (which actually runs kubectl). The bug we're guarding against is the engine
 * ignoring the stored YAML and falling through to the host's default
 * ~/.kube/config — silently deploying to the wrong cluster or nothing at all.
 *
 * Strategy: bind a spy KubernetesKubectlExecutor into the container that
 * captures the kubeconfig file path it was given AND reads the file contents
 * at the moment of invocation (the engine cleans up the file after). Assert
 * the path was passed, contents match what the poller stored, and the temp
 * file is gone after the deploy completes.
 */
final class KubernetesDeployEngineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_engine_materialises_stored_kubeconfig_to_temp_file_and_cleans_up(): void
    {
        $storedYaml = "apiVersion: v1\nkind: Config\nclusters:\n- cluster:\n    server: https://EXAMPLE.k8s.ondigitalocean.com\n  name: prod\n";

        [$site, $tempPathAtDeployTime, $contentAtDeployTime] = $this->runDeployWithSpy($storedYaml);

        $this->assertNotNull($tempPathAtDeployTime, 'engine should have passed a kubeconfig path to kubectl');
        $this->assertSame($storedYaml, $contentAtDeployTime, 'temp file at deploy time must contain the YAML stored on the server');
        $this->assertFileDoesNotExist($tempPathAtDeployTime, 'engine must clean up the temp file after the deploy');
    }

    public function test_engine_uses_legacy_kubeconfig_path_when_meta_has_no_inline_yaml(): void
    {
        // Some older servers may have meta.kubernetes.kubeconfig_path pointing
        // at a real on-host file (legacy operator-set value). Engine should
        // honour that and NOT materialise a temp file.
        [$site, $tempPathAtDeployTime, $contentAtDeployTime, $passedPath] = $this->runDeployWithSpy(
            storedYaml: '', // no inline yaml
            legacyKubeconfigPath: '/etc/kube/legacy-prod.config',
        );

        $this->assertSame('/etc/kube/legacy-prod.config', $passedPath);
        $this->assertNull($tempPathAtDeployTime, 'no temp file should have been materialised when legacy path is set');
    }

    public function test_engine_passes_null_path_when_neither_meta_field_is_set(): void
    {
        // Falls through to kubectl's default (~/.kube/config). Not what dply
        // wants for managed clusters, but the engine shouldn't crash —
        // operators with their own kubeconfig setup at the host level should
        // still be able to use dply as a manifest builder.
        [$site, $tempPathAtDeployTime, $contentAtDeployTime, $passedPath] = $this->runDeployWithSpy(
            storedYaml: '',
            legacyKubeconfigPath: null,
        );

        $this->assertNull($passedPath);
        $this->assertNull($tempPathAtDeployTime);
    }

    /**
     * @return array{0: Site, 1: ?string, 2: ?string, 3?: ?string}
     */
    private function runDeployWithSpy(string $storedYaml, ?string $legacyKubeconfigPath = null): array
    {
        $capturedPath = null;
        $capturedContent = null;
        $passedPath = null;

        $spy = new class($capturedPath, $capturedContent, $passedPath) extends KubernetesKubectlExecutor
        {
            public ?string $kubeconfigPath = null;
            public ?string $kubeconfigContentAtDeploy = null;
            public ?string $passedPathArg = null;

            public function __construct(
                public ?string &$pathOut,
                public ?string &$contentOut,
                public ?string &$passedOut,
            ) {
            }

            public function deploy(string $manifest, string $namespace, string $deploymentName, ?string $kubeconfigPath = null, ?string $context = null): array
            {
                $this->passedOut = $kubeconfigPath;
                if ($kubeconfigPath !== null && is_file($kubeconfigPath)) {
                    $this->pathOut = $kubeconfigPath;
                    $this->contentOut = (string) file_get_contents($kubeconfigPath);
                }

                return ['output' => 'fake kubectl output', 'revision' => '1', 'context' => null];
            }
        };

        $this->app->instance(KubernetesKubectlExecutor::class, $spy);

        $kubernetesMeta = [
            'provider' => 'digitalocean',
            'cluster_name' => 'prod',
            'namespace' => 'default',
        ];
        if ($storedYaml !== '') {
            $kubernetesMeta['kubeconfig'] = $storedYaml;
        }
        if ($legacyKubeconfigPath !== null) {
            $kubernetesMeta['kubeconfig_path'] = $legacyKubeconfigPath;
        }

        $site = $this->makeKubernetesSiteWithServer($kubernetesMeta);
        // Project has no factory — bare instance with the site relation
        // attached is enough for the engine to walk back to the site +
        // server, which is all KubernetesDeployEngine::run touches.
        $project = new Project;
        $project->setRelation('site', $site);

        $context = new DeployContext($project, 'manual');
        $engine = app(KubernetesDeployEngine::class);
        $engine->run($context);

        return [$site, $spy->pathOut, $spy->contentOut, $spy->passedOut ?? null];
    }

    /**
     * @param  array<string, mixed>  $kubernetesMeta
     */
    private function makeKubernetesSiteWithServer(array $kubernetesMeta): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider_credential_id' => $credential->id,
            'status' => Server::STATUS_READY,
            'meta' => [
                'host_kind' => Server::HOST_KIND_KUBERNETES,
                'kubernetes' => $kubernetesMeta,
            ],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'meta' => [
                'runtime_target' => ['family' => 'digitalocean_kubernetes'],
                'kubernetes_runtime' => ['namespace' => $kubernetesMeta['namespace'] ?? 'default'],
            ],
        ]);
    }
}
