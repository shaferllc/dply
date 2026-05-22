<?php


namespace Tests\Unit\Services\SiteDotEnvComposerTest;
use App\Models\Server;
use App\Models\Site;
use App\Models\Workspace;
use App\Services\Sites\SiteDotEnvComposer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it merges project variables with site env file content', function () {
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

    // The site's env_file_content is now the only site-scoped store; it
    // overrides workspace values for matching keys.
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $workspace->organization_id,
        'user_id' => $workspace->user_id,
        'workspace_id' => $workspace->id,
        'deployment_environment' => 'production',
        'env_file_content' => "APP_NAME=dply\nSHARED_KEY=site-override",
    ]);

    $content = app(SiteDotEnvComposer::class)->compose($site->fresh());

    $this->assertStringContainsString('APP_NAME=dply', $content);
    $this->assertStringContainsString('PROJECT_ONLY=available', $content);
    $this->assertStringContainsString('SHARED_KEY=site-override', $content);
    $this->assertStringNotContainsString('SHARED_KEY=project-value', $content);
});