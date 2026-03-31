<?php

namespace Tests\Feature;

use App\Jobs\RunServerlessFunctionDeploymentJob;
use App\Models\ServerlessFunctionDeployment;
use App\Models\ServerlessProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServerlessDeployApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('serverless.api_token', 'api_test_token');
        Config::set('serverless.default_function_name', 'api-fn');
    }

    public function test_returns_503_when_api_token_not_configured(): void
    {
        Config::set('serverless.api_token', '');

        $this->postJson('/api/serverless/deploy', [], [
            'Authorization' => 'Bearer api_test_token',
        ])->assertStatus(503);
    }

    public function test_rejects_missing_bearer_token(): void
    {
        $this->postJson('/api/serverless/deploy')->assertStatus(401);
    }

    public function test_accepts_bearer_token_and_queues_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/serverless/deploy', [], [
            'Authorization' => 'Bearer api_test_token',
        ])->assertStatus(202);

        $id = (int) $response->json('id');
        $this->assertGreaterThan(0, $id);
        $response->assertJsonPath('status', ServerlessFunctionDeployment::STATUS_QUEUED);
        $response->assertJsonPath('deployment_url', url('/api/serverless/deployments/'.$id));

        Queue::assertPushed(RunServerlessFunctionDeploymentJob::class);
        $this->assertDatabaseHas('serverless_function_deployments', [
            'function_name' => 'api-fn',
            'trigger' => ServerlessFunctionDeployment::TRIGGER_API,
        ]);
    }

    public function test_sync_queue_completes_deploy(): void
    {
        $this->postJson('/api/serverless/deploy', [
            'function_name' => 'my-fn',
        ], [
            'Authorization' => 'Bearer api_test_token',
        ])->assertStatus(202);

        $deployment = ServerlessFunctionDeployment::query()->where('function_name', 'my-fn')->first();
        $this->assertNotNull($deployment);
        $this->assertSame(ServerlessFunctionDeployment::STATUS_SUCCEEDED, $deployment->fresh()->status);
    }

    public function test_deploy_with_project_slug_links_deployment(): void
    {
        $project = ServerlessProject::factory()->create(['slug' => 'linked']);

        $this->postJson('/api/serverless/deploy', [
            'function_name' => 'linked-fn',
            'project_slug' => 'linked',
        ], [
            'Authorization' => 'Bearer api_test_token',
        ])->assertStatus(202);

        $deployment = ServerlessFunctionDeployment::query()->where('function_name', 'linked-fn')->first();
        $this->assertNotNull($deployment);
        $this->assertSame($project->id, $deployment->serverless_project_id);
    }

    public function test_deploy_rejects_unknown_project_slug(): void
    {
        $this->postJson('/api/serverless/deploy', [
            'project_slug' => 'missing',
        ], [
            'Authorization' => 'Bearer api_test_token',
        ])->assertStatus(422);
    }

    public function test_duplicate_idempotency_key_reuses_deployment_and_dispatches_job_once(): void
    {
        Queue::fake();

        $headers = [
            'Authorization' => 'Bearer api_test_token',
            'Idempotency-Key' => 'idem-abc-1',
        ];

        $r1 = $this->postJson('/api/serverless/deploy', [
            'function_name' => 'idem-fn',
        ], $headers)->assertStatus(202);

        $r2 = $this->postJson('/api/serverless/deploy', [
            'function_name' => 'idem-fn',
        ], $headers)->assertStatus(202);

        $this->assertSame($r1->json('id'), $r2->json('id'));
        Queue::assertPushed(RunServerlessFunctionDeploymentJob::class, 1);
        $this->assertDatabaseCount('serverless_function_deployments', 1);
    }

    public function test_idempotency_key_scoped_per_project(): void
    {
        $a = ServerlessProject::factory()->create(['slug' => 'proj-a']);
        $b = ServerlessProject::factory()->create(['slug' => 'proj-b']);
        Queue::fake();

        $headers = [
            'Authorization' => 'Bearer api_test_token',
            'Idempotency-Key' => 'same-key',
        ];

        $r1 = $this->postJson('/api/serverless/deploy', [
            'function_name' => 'x',
            'project_slug' => 'proj-a',
        ], $headers)->assertStatus(202);

        $r2 = $this->postJson('/api/serverless/deploy', [
            'function_name' => 'x',
            'project_slug' => 'proj-b',
        ], $headers)->assertStatus(202);

        $this->assertNotSame($r1->json('id'), $r2->json('id'));
        Queue::assertPushed(RunServerlessFunctionDeploymentJob::class, 2);
    }

    public function test_failed_deploy_with_same_idempotency_key_creates_new_deployment(): void
    {
        ServerlessFunctionDeployment::factory()->create([
            'idempotency_key' => 'retry-key',
            'status' => ServerlessFunctionDeployment::STATUS_FAILED,
            'trigger' => ServerlessFunctionDeployment::TRIGGER_API,
            'serverless_project_id' => null,
            'function_name' => 'old-fn',
        ]);

        Queue::fake();

        $this->postJson('/api/serverless/deploy', [
            'function_name' => 'new-fn',
            'idempotency_key' => 'retry-key',
        ], [
            'Authorization' => 'Bearer api_test_token',
        ])->assertStatus(202);

        Queue::assertPushed(RunServerlessFunctionDeploymentJob::class, 1);
        $this->assertDatabaseCount('serverless_function_deployments', 2);
        $this->assertNotNull(
            ServerlessFunctionDeployment::query()->where('function_name', 'new-fn')->first()
        );
    }

    public function test_rejects_idempotency_key_longer_than_255(): void
    {
        $this->postJson('/api/serverless/deploy', [
            'idempotency_key' => str_repeat('x', 256),
        ], [
            'Authorization' => 'Bearer api_test_token',
        ])->assertStatus(422);
    }

    public function test_deploy_with_project_credentials_does_not_persist_secret_in_provisioner_output(): void
    {
        $project = ServerlessProject::factory()->create(['slug' => 'cred-proj']);
        $project->credentials = ['api_token' => 'ultra-secret-do-not-store'];
        $project->save();

        $this->postJson('/api/serverless/deploy', [
            'function_name' => 'cred-fn',
            'project_slug' => 'cred-proj',
        ], [
            'Authorization' => 'Bearer api_test_token',
        ])->assertStatus(202);

        $deployment = ServerlessFunctionDeployment::query()->where('function_name', 'cred-fn')->first();
        $this->assertNotNull($deployment);
        $this->assertSame(ServerlessFunctionDeployment::STATUS_SUCCEEDED, $deployment->fresh()->status);
        $this->assertStringContainsString('credentials_present', (string) $deployment->fresh()->provisioner_output);
        $this->assertStringNotContainsString('ultra-secret-do-not-store', (string) $deployment->fresh()->provisioner_output);
    }
}
