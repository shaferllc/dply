<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\Kubernetes\KubernetesDeployEngineIntegrationTest;
use \App\Services\Deploy\KubernetesKubectlExecutor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Deploy\DeployContext;
use App\Services\Deploy\KubernetesDeployEngine;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('engine materialises stored kubeconfig to temp file and cleans up', function () {
    $storedYaml = "apiVersion: v1\nkind: Config\nclusters:\n- cluster:\n    server: https://EXAMPLE.k8s.ondigitalocean.com\n  name: prod\n";

    [$site, $tempPathAtDeployTime, $contentAtDeployTime] = runDeployWithSpy($storedYaml);

    expect($tempPathAtDeployTime)->not->toBeNull('engine should have passed a kubeconfig path to kubectl');
    expect($contentAtDeployTime)->toBe($storedYaml, 'temp file at deploy time must contain the YAML stored on the server');
    $this->assertFileDoesNotExist($tempPathAtDeployTime, 'engine must clean up the temp file after the deploy');
});
test('engine uses legacy kubeconfig path when meta has no inline yaml', function () {
    // Some older servers may have meta.kubernetes.kubeconfig_path pointing
    // at a real on-host file (legacy operator-set value). Engine should
    // honour that and NOT materialise a temp file.
    [$site, $tempPathAtDeployTime, $contentAtDeployTime, $passedPath] = runDeployWithSpy(
        storedYaml: '',
        // no inline yaml
        legacyKubeconfigPath: '/etc/kube/legacy-prod.config'
    );

    expect($passedPath)->toBe('/etc/kube/legacy-prod.config');
    expect($tempPathAtDeployTime)->toBeNull('no temp file should have been materialised when legacy path is set');
});
test('engine passes null path when neither meta field is set', function () {
    // Falls through to kubectl's default (~/.kube/config). Not what dply
    // wants for managed clusters, but the engine shouldn't crash —
    // operators with their own kubeconfig setup at the host level should
    // still be able to use dply as a manifest builder.
    [$site, $tempPathAtDeployTime, $contentAtDeployTime, $passedPath] = runDeployWithSpy(storedYaml: '', legacyKubeconfigPath: null);

    expect($passedPath)->toBeNull();
    expect($tempPathAtDeployTime)->toBeNull();
});
/**
 * @return array{0: Site, 1: ?string, 2: ?string, 3?: ?string}
 */
function runDeployWithSpy(string $storedYaml, ?string $legacyKubeconfigPath = null): array
{
    $capturedPath = null;
    $capturedContent = null;
    $passedPath = null;

    $spy = new class($capturedPath, $capturedContent, $passedPath) extends KubernetesKubectlExecutor
    {
        function __construct(public ?string &$pathOut, public ?string &$contentOut, public ?string &$passedOut)
        {
        }

        function deploy(string $manifest, string $namespace, string $deploymentName, ?string $kubeconfigPath = null, ?string $context = null): array
        {
            $this->passedOut = $kubeconfigPath;
            if ($kubeconfigPath !== null && is_file($kubeconfigPath)) {
                $this->pathOut = $kubeconfigPath;
                $this->contentOut = (string) file_get_contents($kubeconfigPath);
            }

            return ['output' => 'fake kubectl output', 'revision' => '1', 'context' => null];
        }
    };
    app()->instance(KubernetesKubectlExecutor::class, $spy);

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

    $site = makeKubernetesSiteWithServer($kubernetesMeta);

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
function makeKubernetesSiteWithServer(array $kubernetesMeta): Site
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
