<?php

namespace Tests\Feature;

use App\Models\ServerlessFunctionDeployment;
use App\Models\ServerlessProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ServerlessProjectApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('serverless.api_token', 'api_test_token');
    }

    public function test_index_lists_projects_ordered_by_name(): void
    {
        ServerlessProject::factory()->create(['name' => 'Zebra', 'slug' => 'zebra']);
        ServerlessProject::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);

        $response = $this->getJson('/api/serverless/projects', [
            'Authorization' => 'Bearer api_test_token',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.0.slug', 'alpha');
        $response->assertJsonPath('data.1.slug', 'zebra');
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_index_filters_by_query_on_name_or_slug(): void
    {
        ServerlessProject::factory()->create(['name' => 'Acme Corp', 'slug' => 'acme']);
        ServerlessProject::factory()->create(['name' => 'Other', 'slug' => 'beta']);

        $this->getJson('/api/serverless/projects?q=acme', [
            'Authorization' => 'Bearer api_test_token',
        ])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'acme');

        $this->getJson('/api/serverless/projects?q=corp', [
            'Authorization' => 'Bearer api_test_token',
        ])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'acme');
    }

    public function test_store_creates_project(): void
    {
        $response = $this->postJson('/api/serverless/projects', [
            'name' => 'Demo',
            'slug' => 'demo-app',
        ], [
            'Authorization' => 'Bearer api_test_token',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('project.slug', 'demo-app');
        $response->assertJsonPath('project.has_credentials', false);
        $response->assertJsonPath('project.settings', []);
        $this->assertDatabaseHas('serverless_projects', ['slug' => 'demo-app', 'name' => 'Demo']);
    }

    public function test_store_accepts_settings_and_credentials_are_not_exposed(): void
    {
        $response = $this->postJson('/api/serverless/projects', [
            'name' => 'Secrets',
            'slug' => 'secrets-proj',
            'settings' => ['env' => 'staging'],
            'credentials' => ['api_token' => 'super-secret-token'],
        ], [
            'Authorization' => 'Bearer api_test_token',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('project.has_credentials', true);
        $response->assertJsonPath('project.settings.env', 'staging');
        $this->assertStringNotContainsString('super-secret-token', $response->getContent());
    }

    public function test_patch_updates_name_settings_and_credentials(): void
    {
        $project = ServerlessProject::factory()->create([
            'slug' => 'patch-me',
            'name' => 'Before',
            'settings' => ['a' => 1],
        ]);

        $this->patchJson('/api/serverless/projects/patch-me', [
            'name' => 'After',
            'settings' => ['b' => 2],
            'credentials' => ['token' => 'x'],
        ], [
            'Authorization' => 'Bearer api_test_token',
        ])
            ->assertOk()
            ->assertJsonPath('project.name', 'After')
            ->assertJsonPath('project.settings.b', 2)
            ->assertJsonPath('project.has_credentials', true);

        $show = $this->getJson('/api/serverless/projects/patch-me', [
            'Authorization' => 'Bearer api_test_token',
        ]);
        $show->assertOk();
        $this->assertStringNotContainsString('x', $show->getContent());
    }

    public function test_show_reflects_has_credentials_without_leaking_values(): void
    {
        $project = ServerlessProject::factory()->create(['slug' => 'safe-show']);
        $project->credentials = ['k' => 'hidden-value'];
        $project->save();

        $response = $this->getJson('/api/serverless/projects/safe-show', [
            'Authorization' => 'Bearer api_test_token',
        ]);

        $response->assertOk();
        $response->assertJsonPath('project.has_credentials', true);
        $this->assertStringNotContainsString('hidden-value', $response->getContent());
    }

    public function test_store_rejects_invalid_slug(): void
    {
        $this->postJson('/api/serverless/projects', [
            'name' => 'Bad',
            'slug' => 'Bad_Slug',
        ], [
            'Authorization' => 'Bearer api_test_token',
        ])->assertStatus(422);
    }

    public function test_show_includes_latest_deployment(): void
    {
        $project = ServerlessProject::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
        ServerlessFunctionDeployment::factory()->create([
            'serverless_project_id' => $project->id,
            'status' => ServerlessFunctionDeployment::STATUS_SUCCEEDED,
            'function_name' => 'fn-a',
        ]);

        $deploymentId = ServerlessFunctionDeployment::query()->where('function_name', 'fn-a')->value('id');
        $this->assertNotNull($deploymentId);

        $response = $this->getJson('/api/serverless/projects/acme', [
            'Authorization' => 'Bearer api_test_token',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('project.slug', 'acme')
            ->assertJsonPath('latest_deployment.function_name', 'fn-a')
            ->assertJsonPath('latest_deployment.status', 'succeeded')
            ->assertJsonPath('latest_deployment.deployment_url', url('/api/serverless/deployments/'.$deploymentId));
    }
}
