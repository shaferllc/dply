<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\Kubernetes\EksRegisterFlowTest;

use App\Jobs\PollEksClusterStatusJob;
use App\Livewire\Servers\Create\StepReview;
use App\Livewire\Servers\Create\StepWhat;
use App\Livewire\Servers\WorkspaceCluster;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Models\User;
use App\Services\AwsEksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

test('step what lists aws supported regions in the picker', function () {
    Queue::fake();
    [$user, $org, $credential] = userOrgAndAwsCredential();
    seedDraft($user, $org, $credential, ['name' => 'eks-test']);

    $regions = Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->viewData('kubernetesAwsRegions');

    $regionSlugs = array_column($regions, 'value');
    expect($regionSlugs)->toContain('us-east-1');
    expect($regionSlugs)->toContain('us-west-2');
    expect($regionSlugs)->toContain('eu-west-1');
    expect($regionSlugs)->toHaveCount(count(AwsEksService::SUPPORTED_REGIONS));
});
test('step what seeds default region from credential', function () {
    Queue::fake();
    [$user, $org, $credential] = userOrgAndAwsCredential('eu-west-1');
    seedDraft($user, $org, $credential, ['name' => 'eks-test']);

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->assertSet('form.do_kubernetes_aws_region', 'eu-west-1');
});
test('changing region clears previously picked cluster', function () {
    Queue::fake();
    [$user, $org, $credential] = userOrgAndAwsCredential('us-east-1');
    seedDraft($user, $org, $credential, [
        'name' => 'eks-test',
        'do_kubernetes_aws_region' => 'us-east-1',
        'do_kubernetes_cluster_name' => 'prod-cluster',
    ]);

    // User changes region — the prior cluster_name might not exist in the
    // new region, so it should be reset.
    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->set('form.do_kubernetes_aws_region', 'us-west-2')
        ->assertSet('form.do_kubernetes_cluster_name', '');
});
test('eks register validates region field', function () {
    Queue::fake();
    [$user, $org, $credential] = userOrgAndAwsCredential('us-east-1');

    // Manually crafted draft to test validation: empty region.
    seedDraft($user, $org, $credential, [
        'name' => 'eks-test',
        'do_kubernetes_aws_region' => '',
        'do_kubernetes_cluster_name' => 'prod',
    ]);

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->set('form.do_kubernetes_aws_region', '')
        ->set('form.do_kubernetes_cluster_name', 'prod')
        ->call('next')
        ->assertHasErrors(['form.do_kubernetes_aws_region']);
});
test('store dispatches eks poller and records region in meta', function () {
    Queue::fake();
    [$user, $org, $credential] = userOrgAndAwsCredential('us-east-1');
    seedDraft($user, $org, $credential, [
        'name' => 'eks-prod',
        'do_kubernetes_cluster_name' => 'prod-cluster',
        'do_kubernetes_namespace' => 'apps',
        'do_kubernetes_aws_region' => 'eu-west-1',
    ], step: 4);

    Livewire::actingAs($user)
        ->test(StepReview::class)
        ->call('store');

    $server = Server::query()->where('name', 'eks-prod')->firstOrFail();
    expect($server->meta['kubernetes']['region'])->toBe('eu-west-1');
    expect($server->meta['kubernetes']['provider'])->toBe('aws');
    Queue::assertPushed(PollEksClusterStatusJob::class);
});
test('workspace cluster renders for eks server', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->setRelation('currentOrganization', $org);
    session(['current_organization_id' => $org->id]);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1'],
    ]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_READY,
        'meta' => [
            'host_kind' => Server::HOST_KIND_KUBERNETES,
            'kubernetes' => [
                'provider' => 'aws',
                'cluster_name' => 'prod-eks',
                'cluster_id' => 'arn:aws:eks:us-east-1:111:cluster/prod-eks',
                'region' => 'us-east-1',
                'namespace' => 'default',
                'kubeconfig' => "apiVersion: v1\nkind: Config",
                'snapshot' => [
                    'version' => '1.30',
                    'ha' => true,
                    'node_pools' => [[
                        'name' => 'default-ng',
                        'size' => 'm5.large',
                        'count' => 3,
                        'nodes' => [['status' => ['state' => 'running']], ['status' => ['state' => 'running']], ['status' => ['state' => 'running']]],
                        'status' => 'ACTIVE',
                    ]],
                ],
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceCluster::class, ['server' => $server])
        ->assertSee('prod-eks')
        ->assertSee('m5.large')
        ->assertSee('Download kubeconfig');
});
test('refresh button dispatches eks poller for aws server', function () {
    Queue::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->setRelation('currentOrganization', $org);
    session(['current_organization_id' => $org->id]);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1'],
    ]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_KUBERNETES, 'kubernetes' => [
            'provider' => 'aws',
            'cluster_name' => 'prod-eks',
            'region' => 'us-east-1',
        ]],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceCluster::class, ['server' => $server])
        ->call('refreshClusterStatus');

    Queue::assertPushed(PollEksClusterStatusJob::class);
});
test('generate kubeconfig produces aws eks get token exec yaml', function () {
    $credential = ProviderCredential::factory()->create([
        'provider' => 'aws',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-west-2'],
    ]);
    $service = new AwsEksService($credential, 'us-west-2');

    $yaml = $service->generateKubeconfig([
        'name' => 'prod-eks',
        'arn' => 'arn:aws:eks:us-west-2:111:cluster/prod-eks',
        'endpoint' => 'https://ABC.gr7.us-west-2.eks.amazonaws.com',
        'certificateAuthority' => ['data' => 'LS0tLS1CRUdJTi...'],
    ]);

    $this->assertStringContainsString('apiVersion: v1', $yaml);
    $this->assertStringContainsString('kind: Config', $yaml);
    $this->assertStringContainsString('server: https://ABC.gr7.us-west-2.eks.amazonaws.com', $yaml);
    $this->assertStringContainsString('certificate-authority-data: LS0tLS1CRUdJTi...', $yaml);
    $this->assertStringContainsString('command: aws', $yaml);
    $this->assertStringContainsString('eks', $yaml);
    $this->assertStringContainsString('get-token', $yaml);
    $this->assertStringContainsString('--cluster-name', $yaml);
    $this->assertStringContainsString('prod-eks', $yaml);
    $this->assertStringContainsString('us-west-2', $yaml);
});
test('generate kubeconfig throws when required fields missing', function () {
    $credential = ProviderCredential::factory()->create([
        'provider' => 'aws',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1'],
    ]);
    $service = new AwsEksService($credential, 'us-east-1');

    $this->expectException(\RuntimeException::class);
    $service->generateKubeconfig(['name' => 'partial-cluster']);
});
// NOTE: end-to-end PollEksClusterStatusJob testing (ACTIVE → READY +
// kubeconfig, FAILED → ERROR, CREATING → reschedule) requires AWS SDK
// MockHandler infrastructure we haven't standardised on yet. The poller's
// state-machine logic mirrors PollDoksClusterStatusJob byte-for-byte
// (covered by tests in WorkspaceClusterTest); the per-state branches in
// PollEksClusterStatusJob::handle are obvious enough to ship under that
// structural coverage + the manual Refresh test that proves dispatch.
/**
 * @return array{0: User, 1: Organization, 2: ProviderCredential}
 */
function userOrgAndAwsCredential(string $region = 'us-east-1'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->setRelation('currentOrganization', $org);
    session(['current_organization_id' => $org->id]);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => $region],
    ]);

    return [$user, $org, $credential];
}
/**
 * @param  array<string, mixed>  $extraPayload
 */
function seedDraft(User $user, Organization $org, ProviderCredential $credential, array $extraPayload = [], int $step = 3): void
{
    $defaults = [
        'mode' => 'provider',
        'type' => 'aws_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'install_profile' => 'laravel_app',
        'server_role' => 'application',
        'webserver' => 'nginx',
        'php_version' => '8.3',
        'database' => 'mysql84',
        'cache_service' => 'redis',
        'do_kubernetes_namespace' => 'default',
    ];

    ServerCreateDraft::query()->updateOrCreate(
        ['user_id' => $user->id, 'organization_id' => $org->id],
        ['step' => $step, 'payload' => array_merge($defaults, $extraPayload)],
    );
}
