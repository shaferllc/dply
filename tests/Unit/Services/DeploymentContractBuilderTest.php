<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\Site;
use App\Models\Workspace;
use App\Services\Deploy\DeploymentContractBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentContractBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_typed_contract_with_environment_and_bindings(): void
    {
        $workspace = Workspace::factory()->create();
        $workspace->variables()->create([
            'env_key' => 'APP_NAME',
            'env_value' => 'workspace-name',
            'is_secret' => false,
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
            'env_file_content' => "APP_KEY=base64:test-key\nAPP_URL=https://example.test",
            'meta' => [
                'docker_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                    ],
                ],
            ],
        ]);

        $contract = app(DeploymentContractBuilder::class)->build($site->fresh());

        $this->assertSame('production', $contract->config['environment_name']);
        $this->assertSame('base64:test-key', $contract->environmentMap()['APP_KEY']);
        $this->assertSame('workspace-name', $contract->environmentMap()['APP_NAME']);
        $this->assertSame('file', $contract->environmentMap()['SESSION_DRIVER']);
        $this->assertNotEmpty($contract->revision());
        $this->assertTrue(collect($contract->resourceBindings)->contains(fn ($binding) => $binding->type === 'publication'));
    }
}
