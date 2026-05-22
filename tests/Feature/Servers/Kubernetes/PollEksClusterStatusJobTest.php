<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\Kubernetes\PollEksClusterStatusJobTest;
use App\Jobs\PollEksClusterStatusJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Support\StubsAwsSdk::class);

test('active cluster flips server to ready and persists kubeconfig', function () {
    $this->fakeAws();
    $this->queueAwsResult(['cluster' => [
        'name' => 'prod-eks',
        'arn' => 'arn:aws:eks:us-east-1:111:cluster/prod-eks',
        'endpoint' => 'https://ABC.gr7.us-east-1.eks.amazonaws.com',
        'certificateAuthority' => ['data' => 'LS0tLS1CRUdJTi...'],
        'version' => '1.30',
        'status' => 'ACTIVE',
    ]]);
    $this->queueAwsResult(['nodegroups' => ['ng-1']]);
    $this->queueAwsResult(['nodegroup' => [
        'nodegroupName' => 'ng-1',
        'instanceTypes' => ['m5.large'],
        'scalingConfig' => ['desiredSize' => 2, 'minSize' => 1, 'maxSize' => 4],
        'status' => 'ACTIVE',
        'diskSize' => 20,
        'amiType' => 'AL2_x86_64',
    ]]);

    $server = makeEksServer(status: Server::STATUS_PROVISIONING);

    (new PollEksClusterStatusJob($server))->handle();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_READY);
    expect($server->health_status)->toBe(Server::HEALTH_REACHABLE);
    expect($server->meta['kubernetes']['state'])->toBe('active');
    $this->assertStringContainsString('apiVersion: v1', $server->meta['kubernetes']['kubeconfig']);
    $this->assertStringContainsString('aws', $server->meta['kubernetes']['kubeconfig']);
    $this->assertStringContainsString('eks', $server->meta['kubernetes']['kubeconfig']);

    // Snapshot was normalised into the shape WorkspaceCluster's node-pool
    // table expects, with synthesised per-node statuses for the desired count.
    expect($server->meta['kubernetes']['snapshot']['node_pools'][0]['size'])->toBe('m5.large');
    expect($server->meta['kubernetes']['snapshot']['node_pools'][0]['count'])->toBe(2);
    expect($server->meta['kubernetes']['snapshot']['node_pools'][0]['nodes'])->toHaveCount(2);
});
test('failed cluster flips server to error', function () {
    $this->fakeAws();
    $this->queueAwsResult(['cluster' => [
        'name' => 'broken-eks', 'arn' => 'arn:fake', 'endpoint' => 'https://x', 'certificateAuthority' => ['data' => 'CA'],
        'status' => 'FAILED',
    ]]);

    // listNodegroups still gets called (we write the snapshot even on terminal states)
    $this->queueAwsResult(['nodegroups' => []]);

    $server = makeEksServer(status: Server::STATUS_PROVISIONING);

    (new PollEksClusterStatusJob($server))->handle();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_ERROR);
    expect($server->health_status)->toBe(Server::HEALTH_UNREACHABLE);
    $this->assertStringContainsString('failed', $server->meta['kubernetes']['last_error']);

    // Kubeconfig should NOT have been written on a failed cluster.
    $this->assertArrayNotHasKey('kubeconfig', $server->meta['kubernetes']);
});
test('deleting cluster flips server to error with aws state', function () {
    $this->fakeAws();
    $this->queueAwsResult(['cluster' => [
        'name' => 'gone-eks', 'arn' => 'arn:fake', 'endpoint' => 'https://x', 'certificateAuthority' => ['data' => 'CA'],
        'status' => 'DELETING',
    ]]);
    $this->queueAwsResult(['nodegroups' => []]);

    $server = makeEksServer(status: Server::STATUS_READY);

    (new PollEksClusterStatusJob($server))->handle();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_ERROR);
    $this->assertStringContainsString('deleting', $server->meta['kubernetes']['last_error']);
});
test('creating cluster writes snapshot and reschedules without marking ready', function () {
    $this->fakeAws();
    $this->queueAwsResult(['cluster' => [
        'name' => 'fresh-eks', 'arn' => 'arn:fake', 'endpoint' => 'https://x', 'certificateAuthority' => ['data' => 'CA'],
        'status' => 'CREATING',
    ]]);
    $this->queueAwsResult(['nodegroups' => ['ng-1']]);
    $this->queueAwsResult(['nodegroup' => [
        'nodegroupName' => 'ng-1', 'instanceTypes' => ['m5.large'],
        'scalingConfig' => ['desiredSize' => 2, 'minSize' => 1, 'maxSize' => 4],
        'status' => 'CREATING', 'diskSize' => 20, 'amiType' => 'AL2_x86_64',
    ]]);

    $server = makeEksServer(status: Server::STATUS_PROVISIONING);

    (new PollEksClusterStatusJob($server))->handle();

    $server->refresh();

    // Still provisioning — poller didn't flip to READY because cluster
    // isn't ACTIVE yet. Snapshot DID get written so the UI's milestone
    // strip can show "X of Y nodes ready".
    expect($server->status)->toBe(Server::STATUS_PROVISIONING);
    expect($server->meta['kubernetes']['state'])->toBe('creating');
    expect($server->meta['kubernetes']['snapshot'])->not->toBeNull();

    // No kubeconfig until ACTIVE.
    $this->assertArrayNotHasKey('kubeconfig', $server->meta['kubernetes']);

    // CREATING nodegroup → synthesised nodes array is empty (no fake
    // "running" entries until the nodegroup actually goes ACTIVE).
    expect($server->meta['kubernetes']['snapshot']['node_pools'][0]['nodes'])->toBe([]);
});
test('cluster not found in aws marks server error', function () {
    $this->fakeAws();
    $this->queueAwsError('ResourceNotFoundException', 'No cluster found', 404);

    $server = makeEksServer(status: Server::STATUS_PROVISIONING);

    (new PollEksClusterStatusJob($server))->handle();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_ERROR);
    $this->assertStringContainsString('no longer exists', $server->meta['kubernetes']['last_error']);
});
test('poller skips when cluster name is missing from meta', function () {
    $this->fakeAws();

    $server = makeEksServer(status: Server::STATUS_PROVISIONING, clusterName: '');

    (new PollEksClusterStatusJob($server))->handle();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_PROVISIONING, 'should not flip status when cluster_name is missing');
});
test('poller no ops when server is already in terminal error state', function () {
    $this->fakeAws();

    // No responses queued — if the poller called AWS it would crash with
    // "no responses left" from the MockHandler. The assertion is implicit.
    $server = makeEksServer(status: Server::STATUS_DISCONNECTED);

    (new PollEksClusterStatusJob($server))->handle();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_DISCONNECTED);
});
function makeEksServer(string $status = Server::STATUS_PROVISIONING, string $clusterName = 'prod-eks'): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws',
        'credentials' => [
            'access_key_id' => 'k',
            'secret_access_key' => 's',
            'region' => 'us-east-1',
        ],
    ]);

    return Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => $status,
        'meta' => [
            'host_kind' => Server::HOST_KIND_KUBERNETES,
            'kubernetes' => array_filter([
                'provider' => 'aws',
                'cluster_name' => $clusterName !== '' ? $clusterName : null,
                'region' => 'us-east-1',
                'namespace' => 'default',
            ], static fn ($v): bool => $v !== null),
        ],
    ]);
}
