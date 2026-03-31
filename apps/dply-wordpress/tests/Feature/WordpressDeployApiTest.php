<?php

namespace Tests\Feature;

use App\Models\WordpressDeployment;
use App\Models\WordpressProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WordpressDeployApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('wordpress.api_token', 'wordpress_test_api_token');
    }

    public function test_post_deploy_requires_project_slug(): void
    {
        $this->postJson('/api/wordpress/deploy', [], [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'project_slug is required.');
    }

    public function test_post_deploy_rejects_unknown_project_slug(): void
    {
        $this->postJson('/api/wordpress/deploy', [
            'project_slug' => 'nope',
        ], [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Unknown project_slug.');
    }

    public function test_post_deploy_queues_and_engine_completes_sync_queue(): void
    {
        $project = WordpressProject::factory()->hosted()->create(['slug' => 'api-app', 'name' => 'API App']);

        $response = $this->postJson('/api/wordpress/deploy', [
            'project_slug' => 'api-app',
            'git_ref' => 'feature/x',
            'php_version' => '8.3',
        ], [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('message', 'Deployment queued.');
        $id = (int) $response->json('id');
        $this->assertGreaterThan(0, $id);

        $expectedRevision = hash('sha256', 'api-app|feature/x|8.3|API App');

        $deployment = WordpressDeployment::query()->findOrFail($id);
        $this->assertSame(WordpressDeployment::STATUS_SUCCEEDED, $deployment->status);
        $this->assertSame($project->id, $deployment->wordpress_project_id);
        $this->assertSame('feature/x', $deployment->git_ref);
        $this->assertSame($expectedRevision, $deployment->revision_id);
        $this->assertNotNull($deployment->provisioner_output);
        $out = json_decode((string) $deployment->provisioner_output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('deployed', $out['status']);
        $this->assertSame('hosted', $out['runtime']);
    }

    public function test_post_deploy_rejects_project_without_hosted_target(): void
    {
        WordpressProject::factory()->create([
            'slug' => 'no-target',
            'name' => 'No Target',
            'settings' => ['runtime' => 'hosted'],
        ]);

        $this->postJson('/api/wordpress/deploy', [
            'project_slug' => 'no-target',
        ], [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ])->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Hosted project requires settings.environment_id or settings.primary_url before deploy.'
            );
    }

    public function test_deployments_index_filters_by_project_slug(): void
    {
        $a = WordpressProject::factory()->hosted()->create(['slug' => 'a', 'name' => 'A']);
        $b = WordpressProject::factory()->hosted()->create(['slug' => 'b', 'name' => 'B']);
        WordpressDeployment::factory()->create(['wordpress_project_id' => $a->id]);
        WordpressDeployment::factory()->create(['wordpress_project_id' => $b->id]);

        $response = $this->getJson('/api/wordpress/deployments?project_slug=a', [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($a->id, $response->json('data.0.wordpress_project_id'));
    }

    public function test_deployments_show_includes_provisioner_output(): void
    {
        $deployment = WordpressDeployment::factory()->create([
            'status' => WordpressDeployment::STATUS_SUCCEEDED,
            'provisioner_output' => '{"ok":true}',
        ]);

        $this->getJson('/api/wordpress/deployments/'.$deployment->id, [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ])->assertOk()
            ->assertJsonPath('deployment.provisioner_output', '{"ok":true}');
    }

    public function test_idempotency_returns_same_deployment_when_active(): void
    {
        WordpressProject::factory()->hosted()->create(['slug' => 'idem', 'name' => 'Idem']);

        $headers = [
            'Authorization' => 'Bearer wordpress_test_api_token',
            'Idempotency-Key' => 'same-key',
        ];

        $r1 = $this->postJson('/api/wordpress/deploy', ['project_slug' => 'idem'], $headers);
        $r1->assertStatus(202);
        $id1 = $r1->json('id');

        $r2 = $this->postJson('/api/wordpress/deploy', ['project_slug' => 'idem'], $headers);
        $r2->assertStatus(202);
        $this->assertSame($id1, $r2->json('id'));
    }

    public function test_project_show_includes_latest_deployment(): void
    {
        $project = WordpressProject::factory()->create(['slug' => 'with-deploy']);
        WordpressDeployment::factory()->create([
            'wordpress_project_id' => $project->id,
            'status' => WordpressDeployment::STATUS_SUCCEEDED,
        ]);

        $this->getJson('/api/wordpress/projects/with-deploy', [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ])->assertOk()
            ->assertJsonPath('latest_deployment.status', 'succeeded');
    }
}
