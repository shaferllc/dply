<?php

namespace Tests\Feature;

use App\Models\CloudDeployment;
use App\Models\CloudProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CloudDeployApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('cloud.api_token', 'cloud_test_api_token');
    }

    public function test_post_deploy_requires_project_slug(): void
    {
        $this->postJson('/api/cloud/deploy', [], [
            'Authorization' => 'Bearer cloud_test_api_token',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'project_slug is required.');
    }

    public function test_post_deploy_rejects_unknown_project_slug(): void
    {
        $this->postJson('/api/cloud/deploy', [
            'project_slug' => 'nope',
        ], [
            'Authorization' => 'Bearer cloud_test_api_token',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Unknown project_slug.');
    }

    public function test_post_deploy_queues_and_stub_completes_sync_queue(): void
    {
        $project = CloudProject::factory()->create(['slug' => 'api-app', 'name' => 'API App']);

        $response = $this->postJson('/api/cloud/deploy', [
            'project_slug' => 'api-app',
            'git_ref' => 'feature/x',
            'stack' => 'php',
        ], [
            'Authorization' => 'Bearer cloud_test_api_token',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('message', 'Deployment queued.');
        $id = (int) $response->json('id');
        $this->assertGreaterThan(0, $id);

        $deployment = CloudDeployment::query()->findOrFail($id);
        $this->assertSame(CloudDeployment::STATUS_SUCCEEDED, $deployment->status);
        $this->assertSame($project->id, $deployment->cloud_project_id);
        $this->assertSame('feature/x', $deployment->git_ref);
        $this->assertSame('cloud-stub-revision-1', $deployment->revision_id);
        $this->assertNotNull($deployment->provisioner_output);
    }

    public function test_deployments_index_filters_by_project_slug(): void
    {
        $a = CloudProject::factory()->create(['slug' => 'a', 'name' => 'A']);
        $b = CloudProject::factory()->create(['slug' => 'b', 'name' => 'B']);
        CloudDeployment::factory()->create(['cloud_project_id' => $a->id]);
        CloudDeployment::factory()->create(['cloud_project_id' => $b->id]);

        $response = $this->getJson('/api/cloud/deployments?project_slug=a', [
            'Authorization' => 'Bearer cloud_test_api_token',
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($a->id, $response->json('data.0.cloud_project_id'));
    }

    public function test_deployments_show_includes_provisioner_output(): void
    {
        $deployment = CloudDeployment::factory()->create([
            'status' => CloudDeployment::STATUS_SUCCEEDED,
            'provisioner_output' => '{"ok":true}',
        ]);

        $this->getJson('/api/cloud/deployments/'.$deployment->id, [
            'Authorization' => 'Bearer cloud_test_api_token',
        ])->assertOk()
            ->assertJsonPath('deployment.provisioner_output', '{"ok":true}');
    }

    public function test_idempotency_returns_same_deployment_when_active(): void
    {
        CloudProject::factory()->create(['slug' => 'idem', 'name' => 'Idem']);

        $headers = [
            'Authorization' => 'Bearer cloud_test_api_token',
            'Idempotency-Key' => 'same-key',
        ];

        $r1 = $this->postJson('/api/cloud/deploy', ['project_slug' => 'idem'], $headers);
        $r1->assertStatus(202);
        $id1 = $r1->json('id');

        $r2 = $this->postJson('/api/cloud/deploy', ['project_slug' => 'idem'], $headers);
        $r2->assertStatus(202);
        $this->assertSame($id1, $r2->json('id'));
    }

    public function test_project_show_includes_latest_deployment(): void
    {
        $project = CloudProject::factory()->create(['slug' => 'with-deploy']);
        CloudDeployment::factory()->create([
            'cloud_project_id' => $project->id,
            'status' => CloudDeployment::STATUS_SUCCEEDED,
        ]);

        $this->getJson('/api/cloud/projects/with-deploy', [
            'Authorization' => 'Bearer cloud_test_api_token',
        ])->assertOk()
            ->assertJsonPath('latest_deployment.status', 'succeeded');
    }
}
