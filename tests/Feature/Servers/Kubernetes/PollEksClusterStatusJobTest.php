<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\Kubernetes;

use App\Jobs\PollEksClusterStatusJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StubsAwsSdk;
use Tests\TestCase;

/**
 * State-machine coverage for {@see PollEksClusterStatusJob}. Mirrors the DOKS
 * poller tests in WorkspaceClusterTest but stubs the AWS SDK via
 * {@see StubsAwsSdk} since the EKS service uses the AWS PHP SDK rather than
 * Laravel's Http client (Http::fake doesn't reach it).
 *
 * Response queue order per AWS call sequence the poller makes:
 *   1. DescribeCluster        → returns the cluster array
 *   2. ListNodegroups         → returns ['nodegroups' => [name, ...]]
 *   3. DescribeNodegroup × N  → one Result per nodegroup name
 *   4. (on ACTIVE only) the kubeconfig generation is local, no AWS call
 */
final class PollEksClusterStatusJobTest extends TestCase
{
    use RefreshDatabase;
    use StubsAwsSdk;

    public function test_active_cluster_flips_server_to_ready_and_persists_kubeconfig(): void
    {
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

        $server = $this->makeEksServer(status: Server::STATUS_PROVISIONING);

        (new PollEksClusterStatusJob($server))->handle();

        $server->refresh();
        $this->assertSame(Server::STATUS_READY, $server->status);
        $this->assertSame(Server::HEALTH_REACHABLE, $server->health_status);
        $this->assertSame('active', $server->meta['kubernetes']['state']);
        $this->assertStringContainsString('apiVersion: v1', $server->meta['kubernetes']['kubeconfig']);
        $this->assertStringContainsString('aws', $server->meta['kubernetes']['kubeconfig']);
        $this->assertStringContainsString('eks', $server->meta['kubernetes']['kubeconfig']);
        // Snapshot was normalised into the shape WorkspaceCluster's node-pool
        // table expects, with synthesised per-node statuses for the desired count.
        $this->assertSame('m5.large', $server->meta['kubernetes']['snapshot']['node_pools'][0]['size']);
        $this->assertSame(2, $server->meta['kubernetes']['snapshot']['node_pools'][0]['count']);
        $this->assertCount(2, $server->meta['kubernetes']['snapshot']['node_pools'][0]['nodes']);
    }

    public function test_failed_cluster_flips_server_to_error(): void
    {
        $this->fakeAws();
        $this->queueAwsResult(['cluster' => [
            'name' => 'broken-eks', 'arn' => 'arn:fake', 'endpoint' => 'https://x', 'certificateAuthority' => ['data' => 'CA'],
            'status' => 'FAILED',
        ]]);
        // listNodegroups still gets called (we write the snapshot even on terminal states)
        $this->queueAwsResult(['nodegroups' => []]);

        $server = $this->makeEksServer(status: Server::STATUS_PROVISIONING);

        (new PollEksClusterStatusJob($server))->handle();

        $server->refresh();
        $this->assertSame(Server::STATUS_ERROR, $server->status);
        $this->assertSame(Server::HEALTH_UNREACHABLE, $server->health_status);
        $this->assertStringContainsString('failed', $server->meta['kubernetes']['last_error']);
        // Kubeconfig should NOT have been written on a failed cluster.
        $this->assertArrayNotHasKey('kubeconfig', $server->meta['kubernetes']);
    }

    public function test_deleting_cluster_flips_server_to_error_with_aws_state(): void
    {
        $this->fakeAws();
        $this->queueAwsResult(['cluster' => [
            'name' => 'gone-eks', 'arn' => 'arn:fake', 'endpoint' => 'https://x', 'certificateAuthority' => ['data' => 'CA'],
            'status' => 'DELETING',
        ]]);
        $this->queueAwsResult(['nodegroups' => []]);

        $server = $this->makeEksServer(status: Server::STATUS_READY);

        (new PollEksClusterStatusJob($server))->handle();

        $server->refresh();
        $this->assertSame(Server::STATUS_ERROR, $server->status);
        $this->assertStringContainsString('deleting', $server->meta['kubernetes']['last_error']);
    }

    public function test_creating_cluster_writes_snapshot_and_reschedules_without_marking_ready(): void
    {
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

        $server = $this->makeEksServer(status: Server::STATUS_PROVISIONING);

        (new PollEksClusterStatusJob($server))->handle();

        $server->refresh();
        // Still provisioning — poller didn't flip to READY because cluster
        // isn't ACTIVE yet. Snapshot DID get written so the UI's milestone
        // strip can show "X of Y nodes ready".
        $this->assertSame(Server::STATUS_PROVISIONING, $server->status);
        $this->assertSame('creating', $server->meta['kubernetes']['state']);
        $this->assertNotNull($server->meta['kubernetes']['snapshot']);
        // No kubeconfig until ACTIVE.
        $this->assertArrayNotHasKey('kubeconfig', $server->meta['kubernetes']);
        // CREATING nodegroup → synthesised nodes array is empty (no fake
        // "running" entries until the nodegroup actually goes ACTIVE).
        $this->assertSame([], $server->meta['kubernetes']['snapshot']['node_pools'][0]['nodes']);
    }

    public function test_cluster_not_found_in_aws_marks_server_error(): void
    {
        $this->fakeAws();
        $this->queueAwsError('ResourceNotFoundException', 'No cluster found', 404);

        $server = $this->makeEksServer(status: Server::STATUS_PROVISIONING);

        (new PollEksClusterStatusJob($server))->handle();

        $server->refresh();
        $this->assertSame(Server::STATUS_ERROR, $server->status);
        $this->assertStringContainsString('no longer exists', $server->meta['kubernetes']['last_error']);
    }

    public function test_poller_skips_when_cluster_name_is_missing_from_meta(): void
    {
        $this->fakeAws();

        $server = $this->makeEksServer(status: Server::STATUS_PROVISIONING, clusterName: '');

        (new PollEksClusterStatusJob($server))->handle();

        $server->refresh();
        $this->assertSame(Server::STATUS_PROVISIONING, $server->status, 'should not flip status when cluster_name is missing');
    }

    public function test_poller_no_ops_when_server_is_already_in_terminal_error_state(): void
    {
        $this->fakeAws();
        // No responses queued — if the poller called AWS it would crash with
        // "no responses left" from the MockHandler. The assertion is implicit.

        $server = $this->makeEksServer(status: Server::STATUS_DISCONNECTED);

        (new PollEksClusterStatusJob($server))->handle();

        $server->refresh();
        $this->assertSame(Server::STATUS_DISCONNECTED, $server->status);
    }

    private function makeEksServer(
        string $status = Server::STATUS_PROVISIONING,
        string $clusterName = 'prod-eks',
    ): Server {
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
}
