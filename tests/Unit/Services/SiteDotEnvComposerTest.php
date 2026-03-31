<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\Site;
use App\Models\Workspace;
use App\Services\Sites\SiteDotEnvComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDotEnvComposerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_merges_project_variables_before_site_environment_variables(): void
    {
        $workspace = Workspace::factory()->create();
        $workspace->variables()->create([
            'env_key' => 'SHARED_KEY',
            'env_value' => 'project-value',
            'is_secret' => false,
        ]);
        $workspace->variables()->create([
            'env_key' => 'PROJECT_ONLY',
            'env_value' => 'available',
            'is_secret' => true,
        ]);

        $server = Server::factory()->create([
            'organization_id' => $workspace->organization_id,
            'user_id' => $workspace->user_id,
            'workspace_id' => $workspace->id,
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $workspace->organization_id,
            'user_id' => $workspace->user_id,
            'workspace_id' => $workspace->id,
            'deployment_environment' => 'production',
            'env_file_content' => "APP_NAME=dply\nSHARED_KEY=raw-draft",
        ]);

        $site->environmentVariables()->create([
            'env_key' => 'SHARED_KEY',
            'env_value' => 'site-override',
            'environment' => 'production',
        ]);

        $content = app(SiteDotEnvComposer::class)->compose($site->fresh());

        $this->assertStringContainsString('APP_NAME=dply', $content);
        $this->assertStringContainsString('PROJECT_ONLY=available', $content);
        $this->assertStringContainsString('SHARED_KEY=site-override', $content);
        $this->assertStringNotContainsString('SHARED_KEY=project-value', $content);
        $this->assertStringNotContainsString('SHARED_KEY=raw-draft', $content);
    }
}
