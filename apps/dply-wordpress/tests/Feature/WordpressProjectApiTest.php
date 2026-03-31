<?php

namespace Tests\Feature;

use App\Models\WordpressProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WordpressProjectApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('wordpress.api_token', 'wordpress_test_api_token');
    }

    public function test_returns_503_when_api_token_not_configured(): void
    {
        Config::set('wordpress.api_token', '');

        $this->getJson('/api/wordpress/projects', [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ])->assertStatus(503);
    }

    public function test_rejects_missing_bearer_token(): void
    {
        $this->getJson('/api/wordpress/projects')->assertStatus(401);
    }

    public function test_index_lists_projects_ordered_by_name(): void
    {
        WordpressProject::factory()->create(['name' => 'Zebra', 'slug' => 'zebra']);
        WordpressProject::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);

        $response = $this->getJson('/api/wordpress/projects', [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ]);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Alpha', 'Zebra'], $names);
    }

    public function test_index_filters_by_query_on_name_or_slug(): void
    {
        WordpressProject::factory()->create(['name' => 'One', 'slug' => 'one']);
        WordpressProject::factory()->create(['name' => 'Two', 'slug' => 'other']);

        $response = $this->getJson('/api/wordpress/projects?q=oth', [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('other', $response->json('data.0.slug'));
    }

    public function test_store_creates_project(): void
    {
        $response = $this->postJson('/api/wordpress/projects', [
            'name' => 'My App',
            'slug' => 'my-app',
        ], [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('project.slug', 'my-app');
        $this->assertDatabaseHas('wordpress_projects', ['slug' => 'my-app']);
    }

    public function test_store_accepts_settings_and_credentials_are_not_exposed(): void
    {
        $response = $this->postJson('/api/wordpress/projects', [
            'name' => 'S',
            'slug' => 's-app',
            'settings' => ['region' => 'us-east-1'],
            'credentials' => ['token' => 'secret-token'],
        ], [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('project.has_credentials', true);
        $response->assertJsonPath('project.settings.region', 'us-east-1');
        $this->assertArrayNotHasKey('credentials', $response->json('project'));

        $p = WordpressProject::query()->where('slug', 's-app')->first();
        $this->assertNotNull($p);
        $this->assertSame('secret-token', $p->credentials['token'] ?? null);
    }

    public function test_patch_updates_name_settings_and_credentials(): void
    {
        $p = WordpressProject::factory()->create(['slug' => 'patch-me']);

        $this->patchJson('/api/wordpress/projects/patch-me', [
            'name' => 'New Name',
            'settings' => ['k' => 'v'],
            'credentials' => ['api' => 'x'],
        ], [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ])->assertOk()
            ->assertJsonPath('project.name', 'New Name')
            ->assertJsonPath('project.settings.k', 'v')
            ->assertJsonPath('project.has_credentials', true);
    }

    public function test_show_returns_project(): void
    {
        $p = WordpressProject::factory()->create(['slug' => 'show-me']);

        $this->getJson('/api/wordpress/projects/show-me', [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ])->assertOk()
            ->assertJsonPath('project.slug', 'show-me');
    }

    public function test_store_rejects_invalid_slug(): void
    {
        $this->postJson('/api/wordpress/projects', [
            'name' => 'X',
            'slug' => 'Bad_Slug',
        ], [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ])->assertStatus(422);
    }

    public function test_store_rejects_non_hosted_runtime_in_settings(): void
    {
        $this->postJson('/api/wordpress/projects', [
            'name' => 'X',
            'slug' => 'bad-runtime',
            'settings' => ['runtime' => 'vps'],
        ], [
            'Authorization' => 'Bearer wordpress_test_api_token',
        ])->assertStatus(422)
            ->assertJsonPath('errors.settings.0', 'settings.runtime must be "hosted" when set.');
    }
}
