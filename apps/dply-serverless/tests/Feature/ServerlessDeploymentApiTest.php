<?php

namespace Tests\Feature;

use App\Models\ServerlessFunctionDeployment;
use App\Models\ServerlessProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ServerlessDeploymentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('serverless.api_token', 'api_test_token');
    }

    public function test_requires_bearer_token(): void
    {
        $deployment = ServerlessFunctionDeployment::factory()->create();

        $this->getJson('/api/serverless/deployments')->assertUnauthorized();
        $this->getJson('/api/serverless/deployments/'.$deployment->id)->assertUnauthorized();
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $this->getJson('/api/serverless/deployments/999999', [
            'Authorization' => 'Bearer api_test_token',
        ])->assertNotFound();
    }

    public function test_show_returns_deployment_with_provisioner_output(): void
    {
        $project = ServerlessProject::factory()->create(['slug' => 'acme']);
        $deployment = ServerlessFunctionDeployment::factory()->create([
            'serverless_project_id' => $project->id,
            'status' => ServerlessFunctionDeployment::STATUS_SUCCEEDED,
            'provisioner_output' => '{"provider":"aws"}',
        ]);

        $response = $this->getJson('/api/serverless/deployments/'.$deployment->id, [
            'Authorization' => 'Bearer api_test_token',
        ]);

        $response->assertOk();
        $response->assertJsonPath('deployment.id', $deployment->id);
        $response->assertJsonPath('deployment.deployment_url', url('/api/serverless/deployments/'.$deployment->id));
        $response->assertJsonPath('deployment.status', 'succeeded');
        $response->assertJsonPath('deployment.provisioner_output', '{"provider":"aws"}');
        $response->assertJsonPath('deployment.project.slug', 'acme');
    }

    public function test_index_lists_newest_first_and_omits_provisioner_output(): void
    {
        $a = ServerlessFunctionDeployment::factory()->create([
            'function_name' => 'older',
            'provisioner_output' => 'secret-json',
        ]);
        $b = ServerlessFunctionDeployment::factory()->create([
            'function_name' => 'newer',
            'provisioner_output' => 'other-secret',
        ]);

        $response = $this->getJson('/api/serverless/deployments?per_page=10', [
            'Authorization' => 'Bearer api_test_token',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $b->id);
        $response->assertJsonPath('data.1.id', $a->id);
        $response->assertJsonPath('data.0.deployment_url', url('/api/serverless/deployments/'.$b->id));
        $response->assertJsonMissingPath('data.0.provisioner_output');
    }

    public function test_index_filters_by_project_slug(): void
    {
        $p1 = ServerlessProject::factory()->create(['slug' => 'one']);
        $p2 = ServerlessProject::factory()->create(['slug' => 'two']);
        ServerlessFunctionDeployment::factory()->create(['serverless_project_id' => $p1->id]);
        $d2 = ServerlessFunctionDeployment::factory()->create(['serverless_project_id' => $p2->id]);

        $this->getJson('/api/serverless/deployments?project_slug=two', [
            'Authorization' => 'Bearer api_test_token',
        ])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $d2->id);
    }

    public function test_index_rejects_unknown_project_slug(): void
    {
        $this->getJson('/api/serverless/deployments?project_slug=nope', [
            'Authorization' => 'Bearer api_test_token',
        ])->assertStatus(422);
    }

    public function test_index_filters_by_status(): void
    {
        ServerlessFunctionDeployment::factory()->create(['status' => ServerlessFunctionDeployment::STATUS_FAILED]);
        $ok = ServerlessFunctionDeployment::factory()->create(['status' => ServerlessFunctionDeployment::STATUS_SUCCEEDED]);

        $this->getJson('/api/serverless/deployments?status=succeeded', [
            'Authorization' => 'Bearer api_test_token',
        ])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ok->id);
    }

    public function test_index_rejects_invalid_status(): void
    {
        $this->getJson('/api/serverless/deployments?status=banana', [
            'Authorization' => 'Bearer api_test_token',
        ])->assertStatus(422);
    }

    public function test_index_filters_by_function_name(): void
    {
        ServerlessFunctionDeployment::factory()->create(['function_name' => 'fn-a']);
        $b = ServerlessFunctionDeployment::factory()->create(['function_name' => 'fn-b']);

        $this->getJson('/api/serverless/deployments?function_name=fn-b', [
            'Authorization' => 'Bearer api_test_token',
        ])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $b->id);
    }

    public function test_index_clamps_per_page(): void
    {
        ServerlessFunctionDeployment::factory()->count(3)->create();

        $this->getJson('/api/serverless/deployments?per_page=0', [
            'Authorization' => 'Bearer api_test_token',
        ])
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/serverless/deployments?per_page=999', [
            'Authorization' => 'Bearer api_test_token',
        ])
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }
}
