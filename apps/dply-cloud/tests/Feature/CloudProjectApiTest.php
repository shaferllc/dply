<?php

namespace Tests\Feature;

use App\Models\CloudProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CloudProjectApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('cloud.api_token', 'cloud_test_api_token');
    }

    public function test_returns_503_when_api_token_not_configured(): void
    {
        Config::set('cloud.api_token', '');

        $this->getJson('/api/cloud/projects', [
            'Authorization' => 'Bearer cloud_test_api_token',
        ])->assertStatus(503);
    }

    public function test_rejects_missing_bearer_token(): void
    {
        $this->getJson('/api/cloud/projects')->assertStatus(401);
    }

    public function test_index_lists_projects_ordered_by_name(): void
    {
        CloudProject::factory()->create(['name' => 'Zebra', 'slug' => 'zebra']);
        CloudProject::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);

        $response = $this->getJson('/api/cloud/projects', [
            'Authorization' => 'Bearer cloud_test_api_token',
        ]);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Alpha', 'Zebra'], $names);
    }

    public function test_index_filters_by_query_on_name_or_slug(): void
    {
        CloudProject::factory()->create(['name' => 'One', 'slug' => 'one']);
        CloudProject::factory()->create(['name' => 'Two', 'slug' => 'other']);

        $response = $this->getJson('/api/cloud/projects?q=oth', [
            'Authorization' => 'Bearer cloud_test_api_token',
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('other', $response->json('data.0.slug'));
    }

    public function test_store_creates_project(): void
    {
        $response = $this->postJson('/api/cloud/projects', [
            'name' => 'My App',
            'slug' => 'my-app',
        ], [
            'Authorization' => 'Bearer cloud_test_api_token',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('project.slug', 'my-app');
        $this->assertDatabaseHas('cloud_projects', ['slug' => 'my-app']);
    }

    public function test_store_accepts_settings_and_credentials_are_not_exposed(): void
    {
        $response = $this->postJson('/api/cloud/projects', [
            'name' => 'S',
            'slug' => 's-app',
            'settings' => ['region' => 'us-east-1'],
            'credentials' => ['token' => 'secret-token'],
        ], [
            'Authorization' => 'Bearer cloud_test_api_token',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('project.has_credentials', true);
        $response->assertJsonPath('project.settings.region', 'us-east-1');
        $this->assertArrayNotHasKey('credentials', $response->json('project'));

        $p = CloudProject::query()->where('slug', 's-app')->first();
        $this->assertNotNull($p);
        $this->assertSame('secret-token', $p->credentials['token'] ?? null);
    }

    public function test_patch_updates_name_settings_and_credentials(): void
    {
        $p = CloudProject::factory()->create(['slug' => 'patch-me']);

        $this->patchJson('/api/cloud/projects/patch-me', [
            'name' => 'New Name',
            'settings' => ['k' => 'v'],
            'credentials' => ['api' => 'x'],
        ], [
            'Authorization' => 'Bearer cloud_test_api_token',
        ])->assertOk()
            ->assertJsonPath('project.name', 'New Name')
            ->assertJsonPath('project.settings.k', 'v')
            ->assertJsonPath('project.has_credentials', true);
    }

    public function test_show_returns_project(): void
    {
        $p = CloudProject::factory()->create(['slug' => 'show-me']);

        $this->getJson('/api/cloud/projects/show-me', [
            'Authorization' => 'Bearer cloud_test_api_token',
        ])->assertOk()
            ->assertJsonPath('project.slug', 'show-me');
    }

    public function test_store_rejects_invalid_slug(): void
    {
        $this->postJson('/api/cloud/projects', [
            'name' => 'X',
            'slug' => 'Bad_Slug',
        ], [
            'Authorization' => 'Bearer cloud_test_api_token',
        ])->assertStatus(422);
    }
}
