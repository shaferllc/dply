<?php

namespace Tests\Feature;

use App\Jobs\RunServerlessFunctionDeploymentJob;
use App\Models\ServerlessFunctionDeployment;
use App\Models\ServerlessProject;
use Dply\Core\Security\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServerlessDeployWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('serverless.webhook_secret', 'whsec_test');
        Config::set('serverless.webhook_allowed_ips', []);
        Config::set('serverless.default_function_name', 'hook-fn');
    }

    public function test_rejects_when_webhook_secret_not_configured(): void
    {
        Config::set('serverless.webhook_secret', '');

        $this->postJson('/api/webhooks/serverless/deploy')->assertStatus(400);
    }

    public function test_rejects_invalid_signature(): void
    {
        $this->postJson('/api/webhooks/serverless/deploy', [], [
            'X-Dply-Signature' => 'sha256=deadbeef',
        ])->assertStatus(401);
    }

    public function test_accepts_legacy_body_hmac_and_queues_job(): void
    {
        Queue::fake();
        $body = '';
        $sig = WebhookSignature::expectedLegacyHeader('whsec_test', $body);

        $response = $this->call('POST', '/api/webhooks/serverless/deploy', [], [], [], [
            'HTTP_X_DPLY_SIGNATURE' => $sig,
        ], $body)->assertStatus(202);

        $id = (int) $response->json('id');
        $this->assertGreaterThan(0, $id);
        $response->assertJsonPath('status', ServerlessFunctionDeployment::STATUS_QUEUED);
        $response->assertJsonPath('deployment_url', url('/api/serverless/deployments/'.$id));

        Queue::assertPushed(RunServerlessFunctionDeploymentJob::class);
        $this->assertDatabaseHas('serverless_function_deployments', [
            'function_name' => 'hook-fn',
            'trigger' => ServerlessFunctionDeployment::TRIGGER_WEBHOOK,
            'status' => ServerlessFunctionDeployment::STATUS_QUEUED,
        ]);
    }

    public function test_sync_queue_marks_deployment_succeeded(): void
    {
        $body = '';
        $sig = WebhookSignature::expectedLegacyHeader('whsec_test', $body);

        $this->call('POST', '/api/webhooks/serverless/deploy', [], [], [], [
            'HTTP_X_DPLY_SIGNATURE' => $sig,
        ], $body)->assertStatus(202);

        $deployment = ServerlessFunctionDeployment::query()->first();
        $this->assertNotNull($deployment);
        $this->assertSame(ServerlessFunctionDeployment::STATUS_SUCCEEDED, $deployment->fresh()->status);
        $this->assertNotNull($deployment->fresh()->revision_id);
    }

    public function test_webhook_json_body_with_project_slug_links_deployment(): void
    {
        Queue::fake();
        $project = ServerlessProject::factory()->create(['slug' => 'from-hook']);
        $body = json_encode([
            'project_slug' => 'from-hook',
            'function_name' => 'webhook-fn',
        ], JSON_THROW_ON_ERROR);
        $sig = WebhookSignature::expectedLegacyHeader('whsec_test', $body);

        $this->call('POST', '/api/webhooks/serverless/deploy', [], [], [], [
            'HTTP_X_DPLY_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(202);

        $deployment = ServerlessFunctionDeployment::query()->where('function_name', 'webhook-fn')->first();
        $this->assertNotNull($deployment);
        $this->assertSame($project->id, $deployment->serverless_project_id);
    }
}
